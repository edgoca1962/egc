<?php

namespace EGC\Modules\Sgf;

use EGC\Core\ModuleLoader;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use EGC\Modules\Sgf\Libro\Libro;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — la taxonomía de categorías del módulo SGF, propia por
 * usuario.
 *
 * Vive en la raíz de `modules/sgf/`, no dentro de `libro/`: aunque la
 * sembró y la sigue administrando (crear/sembrar/reiniciar términos)
 * el mismo código que nació con Libro, la usan también Presupuesto
 * (`register_taxonomy_for_object_type()`, ver Presupuesto.php) y la
 * van a usar los movimientos de Banco todavía por construir — es una
 * categorización a nivel de módulo (Ingresos/Egresos y
 * Gastos/Transferencias), no un detalle propio de un único recurso.
 * Con el autoload dinámico de ModuleLoader (`EGC\Modules\Sgf\Clase` ->
 * `modules/sgf/Clase.php`, ver su docblock), una clase sin subcarpeta
 * intermedia en el namespace es justamente cómo se declara "esto es
 * del módulo, no de un recurso puntual" — el mismo patrón que ya usa
 * `modules/sgf/manifest.php` en la raíz.
 *
 * Sí sigue atada a Libro puntualmente en un solo lugar: la capacidad
 * PISO para crear/gestionar términos (`assign_terms`/`manage_terms`/
 * etc. en register_taxonomy(), vía libro_cap()) es `edit_libros`, no
 * una capacidad propia de la taxonomía — WordPress no tiene un
 * `capability_type` para taxonomías como sí tiene para CPT. En la
 * práctica no separa a nadie: todo rol de SGF (sgf_editor, sgf_autor)
 * recibe edit_libros junto con edit_presupuestos en el mismo manifest
 * (ver modules/sgf/manifest.php), así que "tiene Libro" y "tiene SGF"
 * son, hoy, el mismo conjunto de usuarios. Si el día de mañana
 * existiera un usuario con presupuesto o movimientos de Banco pero sin
 * acceso a Libro, ahí sí correspondería revisar este piso — no antes.
 *
 * Mantenimiento propio de categorías (crear, renombrar, eliminar,
 * sustituir) vive en CategoriaManagement, no acá — pero dos de sus
 * reglas de negocio SÍ cruzan a otros módulos ("¿esta categoría está
 * en uso?", "reasigná estos posts de la categoría A a la B") y esta
 * clase, otra vez, no puede conocer a Libro/Presupuesto/Banco uno por
 * uno sin romper ARQUITECTURA MODULAR. Por eso CategoriaManagement
 * dispara dos puntos de extensión (mismo mecanismo que
 * `egc_dropdown_items_{$post_type}` y `egc_user_row_actions`, ver
 * UserScope y core/views/gestion-usuarios.php): el filtro
 * `egc_categoria_uso` (cada módulo suma cuántos de sus posts tienen
 * esa categoría) y la pareja filtro+acción
 * `egc_categoria_reasignar_validar` / `egc_categoria_reasignar` (cada
 * módulo valida si puede reasignar sus posts de A a B sin conflicto,
 * y recién si nadie objetó, los reasigna de verdad). Libro y
 * Presupuesto ya se enganchan a los tres — Banco lo hará solo cuando
 * exista, sin tocar esta clase ni CategoriaManagement.
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
     *
     * `$args['meta_query']` no llega necesariamente como array u
     * `unset` — `WP_Term_Query` lo trae por defecto como `''` (string
     * vacío) cuando nadie lo pidió, y ese default SÍ llega hasta acá
     * (la clave existe, con ese valor). `?? []` no lo detecta: `??`
     * solo cubre "no existe" o `null`, no "existe pero es un string
     * vacío" — de ahí el `is_array()` explícito en vez de `??`. Bug
     * real encontrado en producción (2026-09-22): reventaba recién al
     * guardar el primer movimiento, porque `wp_insert_post()` con
     * `post_status => 'publish'` dispara el recuento de términos de
     * WordPress (`_update_term_count_on_transition_post_status`), que
     * llama a `get_terms()` con ese `''` por defecto nunca antes
     * ejercitado por este filtro.
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

        $meta_query = is_array($args['meta_query'] ?? null) ? $args['meta_query'] : [];

        $args['meta_query'] = array_merge($meta_query, [
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
     * Si el término $term_id es propio de $user_id — lo usa
     * LibroManagement::handle_save() para validar el categoria_id que
     * llega por POST antes de asignarlo a un movimiento: sin este
     * chequeo, alguien podría mandar a mano el ID de un término de
     * otro usuario.
     */
    public function pertenece_a($term_id, $user_id)
    {
        return (int) get_term_meta($term_id, self::META_USUARIO, true) === (int) $user_id;
    }

    /**
     * Profundidad de $term_id dentro del árbol: 0 = tipo raíz
     * (Ingresos/Egresos y Gastos/Transferencias), 1 = categoría, 2 =
     * subcategoría. Mismo recorrido de padres que tipo_de(), pero
     * contando niveles en vez de quedarse con la raíz — lo usan
     * renombrar_termino() y eliminar_termino() para bloquear el tipo
     * raíz (Edwin: "el primer nivel no se le podrá dar ningún tipo de
     * mantenimiento"), y CategoriaManagement para no ofrecer como
     * padre de una categoría nueva algo que ya está en el tope de 3
     * niveles.
     *
     * @return int|null null si $term_id no existe.
     */
    public function profundidad_de($term_id)
    {
        $termino = get_term($term_id, self::TAXONOMY);
        if (!$termino || is_wp_error($termino)) {
            return null;
        }

        $profundidad = 0;
        while ((int) $termino->parent !== 0) {
            $termino = get_term($termino->parent, self::TAXONOMY);
            if (!$termino || is_wp_error($termino)) {
                break;
            }
            $profundidad++;
        }

        return $profundidad;
    }

    /**
     * Renombra un término propio de $user_id. Nunca un tipo raíz
     * (profundidad 0): esa restricción la dio Edwin explícitamente, y
     * además el reporte de Presupuesto compara contra esos tres
     * nombres literales (ver Categoria::prioridad_tipo()) — permitir
     * renombrarlos rompería esa comparación en silencio.
     *
     * @return true|WP_Error
     */
    public function renombrar_termino($term_id, $nuevo_nombre, $user_id)
    {
        if (!$this->pertenece_a($term_id, $user_id)) {
            return new WP_Error('no_autorizado', __('Esa categoría no te pertenece.', 'egc'));
        }

        if ($this->profundidad_de($term_id) === 0) {
            return new WP_Error('tipo_protegido', __('Los tipos (Ingresos, Egresos y Gastos, Transferencias) no se pueden renombrar.', 'egc'));
        }

        $nuevo_nombre = trim((string) $nuevo_nombre);
        if ($nuevo_nombre === '') {
            return new WP_Error('nombre_vacio', __('El nombre de la categoría no puede quedar vacío.', 'egc'));
        }

        $resultado = wp_update_term($term_id, self::TAXONOMY, ['name' => $nuevo_nombre]);

        return is_wp_error($resultado) ? $resultado : true;
    }

    /**
     * Elimina un término propio de $user_id. Nunca un tipo raíz,
     * mismo motivo que renombrar_termino().
     *
     * A propósito NO comprueba acá si el término está en uso (si hay
     * movimientos de Libro o presupuestos cargados con él): esa
     * pregunta cruza a otros módulos, y esta clase vive a nivel de
     * módulo precisamente para no tener que conocerlos uno por uno
     * (ver el docblock de la clase). Quien llama a este método
     * (CategoriaManagement::handle_eliminar()) ya disparó el filtro de
     * extensión `egc_categoria_uso` antes y solo llega hasta acá si
     * ese conteo dio cero.
     *
     * @return true|WP_Error
     */
    public function eliminar_termino($term_id, $user_id)
    {
        if (!$this->pertenece_a($term_id, $user_id)) {
            return new WP_Error('no_autorizado', __('Esa categoría no te pertenece.', 'egc'));
        }

        if ($this->profundidad_de($term_id) === 0) {
            return new WP_Error('tipo_protegido', __('Los tipos (Ingresos, Egresos y Gastos, Transferencias) no se pueden eliminar.', 'egc'));
        }

        $resultado = wp_delete_term($term_id, self::TAXONOMY);

        if (is_wp_error($resultado)) {
            return $resultado;
        }

        return $resultado ? true : new WP_Error('no_encontrado', __('La categoría ya no existe.', 'egc'));
    }

    /**
     * Árbol de categorías propias de $user_id, aplanado con su
     * profundidad — para pintar un `<select>` con sangría en el
     * formulario de movimiento (LibroManagement) sin que la vista
     * tenga que resolver jerarquía ella misma (eso sería lógica, no
     * presentación).
     *
     * No puede reusar get_terms() tal cual esperando que
     * scope_get_terms() lo acote solo: ese filtro acota por quien está
     * MIRANDO la pantalla, y acá lo que importa es de quién es la
     * BILLETERA del movimiento — si sgf_editor carga un movimiento en
     * nombre de otro usuario, tiene que ver las categorías de ESE
     * otro, no las propias. Por eso arma su propio meta_query con
     * $user_id explícito en vez de depender del filtro global.
     *
     * `get_terms()` con `orderby => name` da UNA lista aplanada en
     * orden alfabético global — alcanza para que los hijos de cada
     * padre salgan alfabéticos entre sí (se conserva ese orden relativo
     * al filtrarlos por padre en aplanar()), pero para los tres tipos
     * RAÍZ (Ingresos / Egresos y Gastos / Transferencias) el alfabético
     * no es el orden que pidió Edwin — es financiero (ver
     * prioridad_tipo()). Por eso los tipos se extraen y reordenan
     * aparte, y cada uno de sus subárboles se aplana por separado, en
     * ESE orden — sin tocar cómo se ordenan los hijos dentro de cada
     * uno, que siguen viniendo alfabéticos de $terminos tal cual.
     *
     * @return array<int,array{id:int,nombre:string,profundidad:int}>
     */
    public function arbol_de($user_id)
    {
        $terminos = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'meta_query' => [
                [
                    'key'     => self::META_USUARIO,
                    'value'   => $user_id,
                    'compare' => '=',
                ],
            ],
        ]);

        if (is_wp_error($terminos) || empty($terminos)) {
            return [];
        }

        $tipos = array_values(array_filter($terminos, function ($termino) {
            return (int) $termino->parent === 0;
        }));

        usort($tipos, function ($a, $b) {
            return $this->prioridad_tipo($a->name) <=> $this->prioridad_tipo($b->name);
        });

        $resultado = [];
        foreach ($tipos as $tipo) {
            $resultado[] = [
                'id'          => $tipo->term_id,
                'nombre'      => $tipo->name,
                'profundidad' => 0,
            ];
            $resultado = array_merge($resultado, $this->aplanar($terminos, $tipo->term_id, 1));
        }

        return $resultado;
    }

    /**
     * Orden financiero de presentación para un nombre de tipo RAÍZ
     * (Ingresos / Egresos y Gastos / Transferencias) — no el
     * alfabético que da `get_terms()`. Se deriva directamente del
     * orden en que ARBOL_BASE ya declara esos tres tipos (única fuente
     * de verdad: si ese orden cambiara ahí, este método lo sigue solo,
     * sin otra lista para mantener sincronizada) — nunca de un nombre
     * pasado por `__()`, porque `get_term()` devuelve el dato crudo tal
     * como quedó guardado en la base. Un tipo fuera de esos tres (si
     * alguna vez se agrega uno a mano) cae al final, sin romper nada.
     *
     * Método público porque, además de usarlo `arbol_de()` acá mismo,
     * lo necesita PresupuestoManagement::monedas_de() para agrupar su
     * listado en este mismo orden — es la segunda vez que aparece este
     * criterio, y el dueño natural de "cuál es el orden financiero de
     * los tipos" es esta clase (dueña de ARBOL_BASE), no quien lo
     * consume.
     */
    public function prioridad_tipo($nombre)
    {
        $orden = array_search($nombre, array_keys(self::ARBOL_BASE), true);

        return $orden !== false ? $orden : count(self::ARBOL_BASE);
    }

    /**
     * Aplana el árbol jerárquico de $terminos (padres antes que hijos,
     * hijos antes que nietos) agregando su profundidad — comparte la
     * misma lista de términos ya traída por arbol_de() en cada llamada
     * recursiva, en vez de volver a consultar get_terms() por cada
     * nivel.
     *
     * @return array<int,array{id:int,nombre:string,profundidad:int}>
     */
    private function aplanar($terminos, $parent_id, $profundidad = 0)
    {
        $resultado = [];

        foreach ($terminos as $termino) {
            if ((int) $termino->parent !== $parent_id) {
                continue;
            }

            $resultado[] = [
                'id'          => $termino->term_id,
                'nombre'      => $termino->name,
                'profundidad' => $profundidad,
            ];

            $resultado = array_merge($resultado, $this->aplanar($terminos, $termino->term_id, $profundidad + 1));
        }

        return $resultado;
    }

    /**
     * El término RAÍZ del árbol al que pertenece $term_id — "Ingresos",
     * "Egresos y Gastos" o "Transferencias" (ver ARBOL_BASE), sea cual
     * sea la profundidad real de $term_id (tipo, categoría o
     * subcategoría). Lo usa PresupuestoManagement para agrupar su
     * listado en orden financiero (Ingresos primero con su subtotal,
     * después Egresos, ver su docblock) — un orden que no tiene nada
     * que ver con el alfabético que ya da arbol_de(), así que hace
     * falta este método aparte.
     *
     * @return \WP_Term|null
     */
    public function tipo_de($term_id)
    {
        $termino = get_term($term_id, self::TAXONOMY);
        if (!$termino || is_wp_error($termino)) {
            return null;
        }

        while ((int) $termino->parent !== 0) {
            $padre = get_term($termino->parent, self::TAXONOMY);
            if (!$padre || is_wp_error($padre)) {
                break;
            }
            $termino = $padre;
        }

        return $termino;
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
        include EGC_DIR . '/modules/sgf/views/partials/categoria-usuario.php';
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
