<?php

namespace EGC\Modules\Sgf\Libro;

use EGC\Core\ModuleLoader;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — la taxonomía de categorías de Libro, propia por
 * usuario.
 *
 * Es una única taxonomía jerárquica (`register_taxonomy` con
 * `hierarchical => true`), no tres separadas: los "3 niveles" (tipo,
 * categoría, subcategoría) son tres profundidades de `term_parent`
 * dentro del mismo árbol — el mismo mecanismo nativo que ya usan las
 * Categorías de WordPress, con más niveles. El tercer nivel es
 * opcional en todo el resto del diseño (formulario individual,
 * categorización masiva), así que nada acá exige que exista.
 *
 * WordPress no tiene ningún concepto nativo de "dueño de un término"
 * — a diferencia de un CPT (`post_author` + `map_meta_cap` con el par
 * `edit_posts`/`edit_others_posts`), `get_terms()` no distingue autor.
 * Por eso cada término lleva su dueño en term meta (`_user_id`) y esta
 * clase filtra `get_terms()` a mano para acotarlo — mismo motivo, y
 * misma comparación directa de dato en vez de una capacidad nativa,
 * que ya usa `BilleteraManagement::guard_single()` para el detalle de
 * una billetera.
 *
 * El slug de cada término lleva el ID de usuario como sufijo
 * (`{slug}_{user_id}`, por ejemplo `salarios_4`): dos usuarios pueden
 * llamar "Alimentación" a una categoría propia sin que WordPress les
 * fuerce un slug invisible tipo `alimentacion-2` — en las vistas y en
 * los filtros siempre se busca y se muestra por nombre (y acotado por
 * el term meta de usuario), nunca por slug.
 */
class Categoria
{
    use Singleton;

    const TAXONOMY = 'sgf_igt';

    const META_USUARIO = '_user_id';

    const ACTION_SEMBRAR = 'egc_categoria_sembrar';

    const ACTION_REINICIAR = 'egc_categoria_reiniciar';

    const NONCE_NAME = '_egc_nonce';

    /**
     * Árbol base (tipo => categorías) que se siembra para cada usuario
     * la primera vez que se le asigna un rol de SGF — ver
     * sembrar_si_corresponde(). Sin tercer nivel: es opcional, y no
     * hay un set "base" razonable de subcategorías para todo el mundo.
     */
    const ARBOL_BASE = [
        'Ingresos' => [
            'Aguinaldo',
            'Bonificación',
            'Financieros',
            'Pensión',
            'Salario Fijo',
            'Salario Variable',
        ],
        'Egresos y Gastos' => [
            'Ahorros',
            'Cuidado Personal',
            'Educación',
            'Generosidad',
            'Impuestos',
            'Inversiones',
            'Recreación',
            'Salud',
            'Seguros',
            'Supermercado',
            'Transporte',
            'Vestimenta',
            'Vivienda',
            'Gastos Varios',
        ],
        'Transferencias' => [
            'Cuentas Propias',
            'Cuentas Terceros',
            'Pago Tarjeta Crédito',
        ],
    ];

    private function __construct()
    {
        add_action('init', [$this, 'register_taxonomy'], 20);
        add_action('init', [$this, 'register_term_meta']);
        add_filter('get_terms_args', [$this, 'scope_get_terms'], 10, 2);
        add_action('egc_role_assigned', [$this, 'sembrar_si_corresponde'], 10, 3);

        add_action('egc_user_row_actions', [$this, 'render_user_row_actions']);
        add_action('admin_post_' . self::ACTION_SEMBRAR, [$this, 'handle_sembrar']);
        add_action('admin_post_' . self::ACTION_REINICIAR, [$this, 'handle_reiniciar']);
    }

    /**
     * Prioridad 20 (Libro::register_post_type() corre en la 10, la
     * default): así get_post_type_object(Libro::POST_TYPE) ya existe
     * cuando se leen sus capacidades acá abajo, sin depender del orden
     * en que module.php instancia una clase u otra.
     */
    public function register_taxonomy()
    {
        $libro_cap = $this->libro_cap();

        register_taxonomy(self::TAXONOMY, [Libro::POST_TYPE], [
            'labels' => [
                'name'              => __('Categorías', 'egc'),
                'singular_name'     => __('Categoría', 'egc'),
                'search_items'      => __('Buscar categorías', 'egc'),
                'all_items'         => __('Todas las categorías', 'egc'),
                'parent_item'       => __('Categoría padre', 'egc'),
                'parent_item_colon' => __('Categoría padre:', 'egc'),
                'edit_item'         => __('Editar categoría', 'egc'),
                'update_item'       => __('Actualizar categoría', 'egc'),
                'add_new_item'      => __('Agregar categoría', 'egc'),
                'new_item_name'     => __('Nombre de la nueva categoría', 'egc'),
                'not_found'         => __('No se encontraron categorías', 'egc'),
            ],
            'hierarchical'  => true,
            'public'        => false,
            'show_ui'       => false,
            'show_in_rest'  => false,
            'capabilities'  => [
                // Asignar una categoría a un movimiento propio: alcanza
                // con poder editar movimientos (edit_libros). Crear,
                // renombrar o eliminar un término: el mismo piso —
                // "¿participa de SGF?" —, porque WordPress no tiene una
                // capacidad nativa de "own vs. others" para términos.
                // La distinción fina (¿es SUYO este término puntual?)
                // no la resuelve esta capacidad: la resuelve
                // puede_gestionar() más abajo, comparando el term meta,
                // igual que guard_single() ya hace con post_author.
                'assign_terms' => $libro_cap,
                'manage_terms' => $libro_cap,
                'edit_terms'   => $libro_cap,
                'delete_terms' => $libro_cap,
            ],
        ]);
    }

    public function register_term_meta()
    {
        register_term_meta(self::TAXONOMY, self::META_USUARIO, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
        ]);
    }

    /**
     * Acota cualquier get_terms() de esta taxonomía al usuario actual,
     * salvo que administre el recurso Libro (sgf_editor, o el
     * superusuario) — mismo criterio que
     * BilleteraManagement::scope_archive_query() ya aplica a
     * WP_Query, pero para términos: WordPress tampoco ofrece acá un
     * equivalente nativo, así que se fuerza a mano.
     *
     * No logueado: se filtra por el user_id 0 (que ningún usuario
     * real tiene) en vez de no filtrar — misma defensa adicional que
     * ya usa scope_archive_query(), aunque en la práctica nadie sin
     * sesión llega a pintar nada de Libro.
     */
    public function scope_get_terms($args, $taxonomies)
    {
        if (!in_array(self::TAXONOMY, (array) $taxonomies, true)) {
            return $args;
        }

        if (UserScope::get_instance()->manages(Libro::POST_TYPE)) {
            return $args;
        }

        $user_id = is_user_logged_in() ? get_current_user_id() : 0;

        $args['meta_query'] = array_merge($args['meta_query'] ?? [], [
            [
                'key'     => self::META_USUARIO,
                'value'   => $user_id,
                'compare' => '=',
            ],
        ]);

        return $args;
    }

    /**
     * Si el usuario actual puede gestionar (crear como propio,
     * renombrar, eliminar) este término puntual: o es su dueño, o
     * administra el recurso Libro. Lo va a usar el formulario
     * individual y la pantalla de categorización masiva (pasos
     * siguientes) antes de tocar cualquier término.
     */
    public function puede_gestionar($term_id)
    {
        if (UserScope::get_instance()->manages(Libro::POST_TYPE)) {
            return true;
        }

        $dueño = (int) get_term_meta($term_id, self::META_USUARIO, true);

        return $dueño && is_user_logged_in() && $dueño === get_current_user_id();
    }

    /**
     * Crea un término nuevo, propio de $user_id, con su slug
     * sufijado (`{slug}_{user_id}`) y su term meta de dueño ya
     * guardado — el único punto de escritura real de esta taxonomía
     * (ver el docblock de la clase: register_taxonomy()'s
     * 'capabilities' es, cuanto mucho, un piso; la autorización de
     * negocio la hace quien llame a este método, con puede_gestionar()
     * o con la regla de "es para mí mismo").
     *
     * @return int|WP_Error El ID del término nuevo, o el error de
     *                       WordPress si falló.
     */
    public function crear_termino($nombre, $parent_id, $user_id)
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return new WP_Error('nombre_vacio', __('El nombre de la categoría no puede quedar vacío.', 'egc'));
        }

        $slug = sanitize_title($nombre) . '_' . $user_id;

        $resultado = wp_insert_term($nombre, self::TAXONOMY, [
            'parent' => $parent_id,
            'slug'   => $slug,
        ]);

        if (is_wp_error($resultado)) {
            return $resultado;
        }

        update_term_meta($resultado['term_id'], self::META_USUARIO, $user_id);

        return (int) $resultado['term_id'];
    }

    /**
     * Roles que el propio manifest de SGF declaró (nunca
     * 'sgf_editor'/'sgf_autor' a mano) — compartido por
     * sembrar_si_corresponde() y tiene_rol_sgf(), para que ninguno de
     * los dos se rompa si algún día se renombran los roles ahí.
     *
     * @return string[]
     */
    private function roles_sgf()
    {
        $manifest = ModuleLoader::get_instance()->discover()['sgf'] ?? [];

        return array_keys($manifest['roles'] ?? []);
    }

    /**
     * Si $user_id tiene capacidad real sobre Libro (edit_libros) — NO
     * si tiene asignado literalmente el rol sgf_editor/sgf_autor. La
     * diferencia importa: Administrador General nunca pasa por
     * UserManagement::handle_role_change() (arma sus capacidades con
     * su propio rol transversal, ver AdminGeneralRole::build_capabilities(),
     * que sí incluye edit_libros junto con el resto de los CPT de
     * todos los módulos), así que jamás tiene 'sgf_editor' ni
     * 'sgf_autor' en $user->roles — pero sí puede crear y editar sus
     * propias billeteras y movimientos como cualquier otro usuario.
     * Comparar contra roles_sgf() (como antes) lo dejaba afuera del
     * botón de sembrado aunque tuviera plena capacidad de usar Libro
     * para sí mismo.
     *
     * user_can() en vez de current_user_can() porque acá se pregunta
     * por UN USUARIO PUNTUAL ($user_id), no por quien está mirando la
     * pantalla — y por capacidad, nunca por nombre de rol, tal como
     * pide AUTORIZACIÓN. La usan render_user_row_actions() (para no
     * mostrar el botón a quien no tiene acceso al módulo — no tendría
     * sentido sembrarle categorías) y user_id_autorizado() (para que
     * esa misma regla no dependa solo de que el botón esté oculto: ver
     * su docblock).
     *
     * El superusuario real del sitio queda afuera de este chequeo sin
     * necesidad de ningún caso especial: nunca aparece como fila de
     * "Gestión de usuarios" (ver UserManagement::users_rows()), así
     * que este método nunca llega a evaluarse para él. Si alguna vez
     * necesita usar SGF para sí mismo, le corresponde una cuenta de
     * usuario común — mismo criterio que ya aplica el resto de esta
     * pantalla al excluirlo de su propio CRUD.
     */
    private function tiene_acceso_sgf($user_id)
    {
        return user_can($user_id, $this->libro_cap());
    }

    /**
     * Siembra el árbol base (ver ARBOL_BASE) la primera vez que a un
     * usuario se le asigna un rol de SGF — enganchado al hook
     * genérico que dispara UserManagement::handle_role_change()
     * (ARQUITECTURA MODULAR: el Core no puede saber que SGF existe,
     * así que es SGF quien escucha, no el Core quien llama).
     *
     * $role_slug se compara contra los roles que el propio manifest
     * de SGF declaró (nunca 'sgf_editor'/'sgf_autor' a mano, ver
     * roles_sgf()): si algún día se renombran ahí, este chequeo sigue
     * sin tocarse.
     */
    public function sembrar_si_corresponde($user_id, $role_slug, $post_type)
    {
        if (!in_array($role_slug, $this->roles_sgf(), true)) {
            return;
        }

        $this->sembrar_arbol_base($user_id);
    }

    /**
     * Idempotente: si $user_id ya tiene algún término propio, no
     * vuelve a sembrar — así que sacarle y volver a darle un rol de
     * SGF más adelante no le duplica el árbol. Mismo criterio que usa
     * "Reiniciar categorías a la base" (ver handle_reiniciar()): ahí
     * se borra todo lo propio primero, así que esta misma idempotencia
     * es lo que hace que el reinicio también resiembre solo.
     */
    public function sembrar_arbol_base($user_id)
    {
        if (!empty($this->terminos_ids_de($user_id))) {
            return;
        }

        foreach (self::ARBOL_BASE as $tipo => $categorias) {
            $tipo_id = $this->crear_termino($tipo, 0, $user_id);
            if (is_wp_error($tipo_id)) {
                continue;
            }

            foreach ($categorias as $categoria) {
                $this->crear_termino($categoria, $tipo_id, $user_id);
            }
        }
    }

    /**
     * Botón extra en la fila de cada usuario de "Gestión de usuarios"
     * (core/views/gestion-usuarios.php) — enganchado al hook genérico
     * `egc_user_row_actions` que esa vista expone ahí, mismo mecanismo
     * que ya usa Blog con `egc_dropdown_items_{$post_type}`: el Core
     * no sabe qué módulo se cuelga ni qué hace, solo avisa dónde.
     *
     * Dos condiciones, no una: quien MIRA la pantalla tiene que
     * administrar Libro (superusuario, Administrador General o
     * sgf_editor) — un admin de otro módulo que entre a esta pantalla
     * (alcanza con administrar CUALQUIER recurso para entrar, ver
     * UserManagement::guard_access()) no ve nada acá — y el usuario DE
     * LA FILA tiene que tener capacidad real sobre Libro: no tiene
     * sentido sembrarle o reiniciarle categorías a alguien sin acceso
     * al módulo (ver tiene_acceso_sgf()).
     *
     * Nada de HTML acá: arma los datos y delega el marcado al partial,
     * misma separación de capas que ya usa
     * BilleteraManagement::actions_for() con post-actions.php.
     */
    public function render_user_row_actions($user_id)
    {
        if (!UserScope::get_instance()->manages(Libro::POST_TYPE)) {
            return;
        }

        if (!$this->tiene_acceso_sgf($user_id)) {
            return;
        }

        $estado = $this->view_state_fila_usuario($user_id);
        include EGC_DIR . '/modules/sgf/libro/views/partials/categoria-usuario.php';
    }

    /**
     * @return array{user_id:int, tiene_categorias:bool, action:string, nonce_action:string, nonce_name:string, form_action:string, redirect_to:string}
     */
    private function view_state_fila_usuario($user_id)
    {
        $tiene_categorias = !empty($this->terminos_ids_de($user_id));
        $action           = $tiene_categorias ? self::ACTION_REINICIAR : self::ACTION_SEMBRAR;

        return [
            'user_id'          => $user_id,
            'tiene_categorias' => $tiene_categorias,
            'action'           => $action,
            'nonce_action'     => $action,
            'nonce_name'       => self::NONCE_NAME,
            'form_action'      => admin_url('admin-post.php'),
            // "Gestión de usuarios" es la única pantalla donde vive
            // este botón, así que la URL actual YA es la de vuelta —
            // mismo truco que BilleteraManagement::current_url().
            'redirect_to'      => home_url(add_query_arg(null, null)),
        ];
    }

    public function handle_sembrar()
    {
        check_admin_referer(self::ACTION_SEMBRAR, self::NONCE_NAME);

        $user_id = $this->user_id_autorizado();
        if (!$user_id) {
            $this->back_with_error('forbidden');
        }

        $this->sembrar_arbol_base($user_id);

        $this->back_with_ok();
    }

    /**
     * Borra TODOS los términos propios de $user_id (nunca los de otro
     * usuario: el filtro es siempre por su term meta, igual que en
     * terminos_ids_de()) y los vuelve a sembrar desde ARBOL_BASE. No
     * hace falta una versión de sembrar_arbol_base() sin el chequeo de
     * idempotencia: después de borrar, ya no le queda ningún término
     * propio, así que la siembra normal entra sola.
     */
    public function handle_reiniciar()
    {
        check_admin_referer(self::ACTION_REINICIAR, self::NONCE_NAME);

        $user_id = $this->user_id_autorizado();
        if (!$user_id) {
            $this->back_with_error('forbidden');
        }

        foreach ($this->terminos_ids_de($user_id) as $term_id) {
            wp_delete_term($term_id, self::TAXONOMY);
        }

        $this->sembrar_arbol_base($user_id);

        $this->back_with_ok();
    }

    /**
     * IDs de todos los términos propios de $user_id, en cualquiera de
     * los 3 niveles — compartido por sembrar_arbol_base() (chequeo de
     * idempotencia), view_state_fila_usuario() (qué botón mostrar) y
     * handle_reiniciar() (qué borrar).
     *
     * @return int[]
     */
    private function terminos_ids_de($user_id)
    {
        return get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_query' => [
                [
                    'key'     => self::META_USUARIO,
                    'value'   => $user_id,
                    'compare' => '=',
                ],
            ],
        ]);
    }

    /**
     * user_id del $_POST, solo si quien pide esto administra Libro Y
     * el usuario objetivo tiene capacidad real sobre Libro — 0 en
     * cualquier otro caso (falta el dato, no está autorizado, o el
     * objetivo no tiene acceso al módulo).
     *
     * Esta segunda condición es la revalidación de servidor de lo
     * mismo que ya oculta render_user_row_actions(): que el botón no
     * aparezca en la fila es presentación, no seguridad — si alguien
     * arma el POST a mano contra admin-post.php con el user_id de
     * cualquiera, tiene que rebotar acá igual.
     */
    private function user_id_autorizado()
    {
        if (!UserScope::get_instance()->manages(Libro::POST_TYPE)) {
            return 0;
        }

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        return ($user_id && $this->tiene_acceso_sgf($user_id)) ? $user_id : 0;
    }

    private function back_with_ok()
    {
        wp_safe_redirect(add_query_arg('ok', '1', $this->redirect_target()));
        exit;
    }

    private function back_with_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->redirect_target()));
        exit;
    }

    private function redirect_target()
    {
        $requested = isset($_POST['redirect_to']) ? wp_unslash($_POST['redirect_to']) : '';
        $fallback  = home_url('/');

        return $requested !== '' ? wp_validate_redirect($requested, $fallback) : $fallback;
    }

    /**
     * Nombre real de la capacidad primitiva de Libro (`edit_posts` del
     * objeto del CPT, o sea `edit_libros`), nunca hardcodeado — mismo
     * motivo que ya corrigió UserScope y BilleteraManagement: el
     * capability_type de Libro es `['libro', 'libros']`, así que la
     * capacidad real es `edit_libros`, no `edit_posts`.
     */
    private function libro_cap()
    {
        $post_type_object = get_post_type_object(Libro::POST_TYPE);

        return $post_type_object ? $post_type_object->cap->edit_posts : 'edit_libros';
    }
}
