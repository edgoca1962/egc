<?php

namespace EGC\Modules\Sgf;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use EGC\Modules\Sgf\Libro\Libro;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Puente — mantenimiento de categorías propias (crear, renombrar,
 * eliminar, sustituir) en una sola página del front-end, mismo patrón
 * de admin-post.php + view_state() + guard_access() que el resto del
 * módulo.
 *
 * Nunca toca el tipo raíz (profundidad 0: Ingresos, Egresos y Gastos,
 * Transferencias) — Edwin fue explícito: "el primer nivel no se le
 * podrá dar ningún tipo de mantenimiento". Esta clase ni siquiera
 * ofrece esa opción en la vista; Categoria::renombrar_termino() y
 * ::eliminar_termino() la bloquean de nuevo del lado del servidor,
 * por si alguien arma el POST a mano.
 *
 * "Eliminar" solo está disponible si la categoría no está en uso
 * (`uso_de()`, que dispara el filtro `egc_categoria_uso` — ver el
 * docblock de Categoria). Si está en uso, la única salida es
 * "Sustituir por...": se elige una categoría de reemplazo DEL MISMO
 * TIPO RAÍZ (así lo pidió Edwin — cruzar de tipo reclasificaría
 * movimientos históricos en silencio), se valida con el filtro
 * `egc_categoria_reasignar_validar` (cada módulo puede objetar —
 * Presupuesto lo hace si fusionar dos años en distinta moneda no es
 * seguro, ver PresupuestoManagement::validar_reasignacion_categoria())
 * y, recién si nadie objetó, se dispara la acción
 * `egc_categoria_reasignar` para que cada módulo reasigne sus propios
 * posts, y por último se elimina la categoría de origen — que para
 * ese momento ya quedó sin uso.
 *
 * Los errores de validación de la sustitución son texto libre por
 * módulo (por ejemplo, mencionan un año puntual) — no códigos fijos
 * como el resto de los mensajes de esta clase, así que no pueden ir
 * por `sanitize_key()` en la URL de vuelta (eso fue justo el bug que
 * encontramos con el parámetro `año`, ver PresupuestoManagement). Se
 * guardan en un transient efímero por usuario y se leen una sola vez
 * al mostrar el mensaje de error.
 *
 * Tope de 3 niveles (tipo -> categoría -> subcategoría), que confirmó
 * Edwin: el formulario de alta solo ofrece como padre una categoría
 * de profundidad 0 o 1 — nunca de profundidad 2, que ya es el máximo.
 */
class CategoriaManagement
{
    use Singleton;

    const SLUG = 'mis-categorias';

    const ACTION_CREAR = 'egc_categoria_crear';

    const ACTION_RENOMBRAR = 'egc_categoria_renombrar';

    const ACTION_ELIMINAR = 'egc_categoria_eliminar';

    const ACTION_SUSTITUIR = 'egc_categoria_sustituir';

    const NONCE_NAME = '_egc_nonce';

    const TRANSIENT_ERRORES = 'egc_categoria_errores_';

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_CREAR, [$this, 'handle_crear']);
        add_action('admin_post_' . self::ACTION_RENOMBRAR, [$this, 'handle_renombrar']);
        add_action('admin_post_' . self::ACTION_ELIMINAR, [$this, 'handle_eliminar']);
        add_action('admin_post_' . self::ACTION_SUSTITUIR, [$this, 'handle_sustituir']);

        // "Mis categorías" no es el archive de ningún post_type (esta
        // clase no registra ningún CPT), así que UserScope::links_by()
        // no puede armar sola su entrada en el dropdown del avatar —
        // se suma al mismo grupo que ya arma Libro (comparten piso de
        // autorización, ver el docblock de Categoria), igual que
        // Presupuesto se sumó al suyo propio.
        add_filter('egc_dropdown_items_' . Libro::POST_TYPE, [$this, 'add_navbar_items'], 10, 2);
    }

    public function add_navbar_items($items, $tier)
    {
        $items[] = [
            'label' => __('Mis categorías', 'egc'),
            'url'   => $this->url(),
        ];

        return $items;
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Mis categorías', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * Información financiera propia del usuario, igual que el resto
     * del módulo: nadie sin sesión ve nada, y hace falta capacidad
     * real sobre Libro (propia o de administración) — mismo piso que
     * ya comparte la taxonomía (ver el docblock de Categoria). Se
     * comprueba con UserScope (capacidades reales), nunca con nombres
     * de rol, así que da igual si quien entra es sgf_autor, sgf_editor
     * o Administrador General.
     */
    public function guard_access()
    {
        if (!is_page(self::SLUG)) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $tiene_acceso = UserScope::get_instance()->manages(Libro::POST_TYPE)
            || UserScope::get_instance()->authors(Libro::POST_TYPE);

        if (!$tiene_acceso) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * @return array{
     *   filas: array,
     *   padre_opciones: array,
     *   editando: ?array,
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_name: string,
     *   action_crear: string,
     *   action_renombrar: string,
     *   action_eliminar: string,
     *   action_sustituir: string,
     *   redirect_to: string,
     *   cancelar_url: string,
     * }
     */
    public function view_state()
    {
        $user_id       = get_current_user_id();
        $categoria_srv = Categoria::get_instance();
        $arbol         = $categoria_srv->arbol_de($user_id);

        $filas          = [];
        $padre_opciones = [];

        foreach ($arbol as $item) {
            $es_tipo = $item['profundidad'] === 0;
            $uso     = $es_tipo ? 0 : $this->uso_de($item['id']);

            $filas[] = [
                'id'                   => $item['id'],
                'nombre'               => $item['nombre'],
                'profundidad'          => $item['profundidad'],
                'es_tipo'              => $es_tipo,
                'uso'                  => $uso,
                'puede_eliminar'       => !$es_tipo && $uso === 0,
                'puede_sustituir'      => !$es_tipo && $uso > 0,
                'candidatos_sustituto' => (!$es_tipo && $uso > 0) ? $this->candidatos_sustituto($arbol, $item['id']) : [],
                'editar_url'           => $es_tipo ? '' : add_query_arg('editar_id', $item['id'], $this->url()),
            ];

            // Padre válido para una categoría nueva: profundidad 0
            // (tipo) o 1 (categoría) — nunca 2, que ya es el tope de 3
            // niveles que confirmó Edwin.
            if ($item['profundidad'] < 2) {
                $padre_opciones[] = $item;
            }
        }

        $editar_id = isset($_GET['editar_id']) ? absint($_GET['editar_id']) : 0;

        return [
            'filas'            => $filas,
            'padre_opciones'   => $padre_opciones,
            'editando'         => $editar_id ? $this->fila_de($filas, $editar_id) : null,
            'error'            => $this->message('error'),
            'success'          => (bool) $this->message('ok'),
            'form_action'      => admin_url('admin-post.php'),
            'nonce_name'       => self::NONCE_NAME,
            'action_crear'     => self::ACTION_CREAR,
            'action_renombrar' => self::ACTION_RENOMBRAR,
            'action_eliminar'  => self::ACTION_ELIMINAR,
            'action_sustituir' => self::ACTION_SUSTITUIR,
            'redirect_to'      => $this->current_url(),
            'cancelar_url'     => $this->url(),
        ];
    }

    private function fila_de($filas, $term_id)
    {
        foreach ($filas as $fila) {
            if ((int) $fila['id'] === (int) $term_id && !$fila['es_tipo']) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * Candidatas a reemplazar $term_id en una sustitución: del mismo
     * árbol ($arbol, ya traído por view_state()), del mismo tipo raíz
     * (Categoria::tipo_de()), y nunca ella misma ni un tipo raíz.
     */
    private function candidatos_sustituto($arbol, $term_id)
    {
        $categoria_srv = Categoria::get_instance();
        $tipo          = $categoria_srv->tipo_de($term_id);

        if (!$tipo) {
            return [];
        }

        $candidatos = [];
        foreach ($arbol as $item) {
            if ((int) $item['id'] === (int) $term_id || $item['profundidad'] === 0) {
                continue;
            }

            $item_tipo = $categoria_srv->tipo_de($item['id']);
            if ($item_tipo && (int) $item_tipo->term_id === (int) $tipo->term_id) {
                $candidatos[] = $item;
            }
        }

        return $candidatos;
    }

    /**
     * Cuántos posts de otros módulos usan $term_id — dispara el punto
     * de extensión `egc_categoria_uso` (ver el docblock de la clase y
     * el de Categoria). Sin enganches, da 0: una categoría recién
     * creada, antes de que se le cargue nada, se puede eliminar
     * directo.
     */
    private function uso_de($term_id)
    {
        return (int) apply_filters('egc_categoria_uso', 0, $term_id);
    }

    private function message($param)
    {
        if ($param === 'ok') {
            return isset($_GET['ok']);
        }

        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        if ($error === 'sustitucion_bloqueada') {
            $user_id = get_current_user_id();
            $errores = get_transient(self::TRANSIENT_ERRORES . $user_id);
            delete_transient(self::TRANSIENT_ERRORES . $user_id);

            return !empty($errores) ? implode(' ', (array) $errores) : __('No se pudo completar la sustitución.', 'egc');
        }

        switch ($error) {
            case 'forbidden':
                return __('No tenés permiso para hacer eso.', 'egc');
            case 'nombre_vacio':
                return __('El nombre no puede quedar vacío.', 'egc');
            case 'nombre_duplicado':
                return __('Ya existe una categoría con ese nombre en ese mismo nivel.', 'egc');
            case 'padre_invalido':
                return __('Elegí una categoría padre válida (un tipo o una categoría, nunca una subcategoría).', 'egc');
            case 'en_uso':
                return __('Esa categoría está en uso — sustituila por otra antes de eliminarla.', 'egc');
            case 'sustituto_invalido':
                return __('Elegí una categoría de reemplazo propia, del mismo tipo (Ingresos, Egresos y Gastos o Transferencias) y distinta de la original.', 'egc');
            default:
                return '';
        }
    }

    public function handle_crear()
    {
        check_admin_referer(self::ACTION_CREAR, self::NONCE_NAME);

        $user_id       = get_current_user_id();
        $categoria_srv = Categoria::get_instance();

        $padre_id = isset($_POST['padre_id']) ? absint($_POST['padre_id']) : 0;
        $nombre   = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';

        $profundidad_padre = $padre_id ? $categoria_srv->profundidad_de($padre_id) : null;

        if (
            !$padre_id
            || !$categoria_srv->pertenece_a($padre_id, $user_id)
            || $profundidad_padre === null
            || $profundidad_padre > 1
        ) {
            $this->back_with_error('padre_invalido');
        }

        $resultado = $categoria_srv->crear_termino($nombre, $padre_id, $user_id);
        if (is_wp_error($resultado)) {
            // crear_termino() solo devuelve su propio WP_Error para el
            // nombre vacío — cualquier otro (típicamente wp_insert_term
            // rechazando un nombre repetido dentro del mismo padre) se
            // reporta aparte, para no decirle "nombre vacío" a alguien
            // que sí escribió un nombre.
            $this->back_with_error($resultado->get_error_code() === 'nombre_vacio' ? 'nombre_vacio' : 'nombre_duplicado');
        }

        $this->back_with_ok();
    }

    public function handle_renombrar()
    {
        check_admin_referer(self::ACTION_RENOMBRAR, self::NONCE_NAME);

        $user_id = get_current_user_id();
        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        $nombre  = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';

        $resultado = Categoria::get_instance()->renombrar_termino($term_id, $nombre, $user_id);

        if (is_wp_error($resultado)) {
            $codigo = $resultado->get_error_code();
            // no_autorizado/tipo_protegido (propios de renombrar_termino())
            // son de permisos; cualquier otro código es de
            // wp_update_term() — en la práctica, un nombre repetido
            // dentro del mismo padre.
            $this->back_with_error(
                $codigo === 'nombre_vacio' ? 'nombre_vacio' : (in_array($codigo, ['no_autorizado', 'tipo_protegido'], true) ? 'forbidden' : 'nombre_duplicado')
            );
        }

        $this->back_with_ok();
    }

    public function handle_eliminar()
    {
        check_admin_referer(self::ACTION_ELIMINAR, self::NONCE_NAME);

        $user_id = get_current_user_id();
        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;

        if ($this->uso_de($term_id) > 0) {
            $this->back_with_error('en_uso');
        }

        $resultado = Categoria::get_instance()->eliminar_termino($term_id, $user_id);
        if (is_wp_error($resultado)) {
            $this->back_with_error('forbidden');
        }

        $this->back_with_ok();
    }

    /**
     * "Sustituir": revalida todo de nuevo del lado del servidor (que
     * A y B sean propias, del mismo tipo raíz, y distintas entre sí —
     * ocultar las opciones inválidas en la vista es presentación, no
     * seguridad), dispara la VALIDACIÓN de cada módulo antes de tocar
     * nada, y solo si nadie objetó reasigna de verdad y recién ahí
     * elimina la categoría de origen (ver el docblock de la clase).
     */
    public function handle_sustituir()
    {
        check_admin_referer(self::ACTION_SUSTITUIR, self::NONCE_NAME);

        $user_id       = get_current_user_id();
        $categoria_srv = Categoria::get_instance();

        $term_id_a = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        $term_id_b = isset($_POST['sustituto_id']) ? absint($_POST['sustituto_id']) : 0;

        if (
            !$term_id_a
            || !$categoria_srv->pertenece_a($term_id_a, $user_id)
            || $categoria_srv->profundidad_de($term_id_a) === 0
        ) {
            $this->back_with_error('forbidden');
        }

        if (
            !$term_id_b
            || $term_id_b === $term_id_a
            || !$categoria_srv->pertenece_a($term_id_b, $user_id)
        ) {
            $this->back_with_error('sustituto_invalido');
        }

        $tipo_a = $categoria_srv->tipo_de($term_id_a);
        $tipo_b = $categoria_srv->tipo_de($term_id_b);

        if (!$tipo_a || !$tipo_b || (int) $tipo_a->term_id !== (int) $tipo_b->term_id) {
            $this->back_with_error('sustituto_invalido');
        }

        $errores = apply_filters('egc_categoria_reasignar_validar', [], $term_id_a, $term_id_b, $user_id);
        if (!empty($errores)) {
            set_transient(self::TRANSIENT_ERRORES . $user_id, $errores, MINUTE_IN_SECONDS);
            $this->back_with_error('sustitucion_bloqueada');
        }

        do_action('egc_categoria_reasignar', $term_id_a, $term_id_b, $user_id);

        // Para este punto, la reasignación ya dejó a $term_id_a sin
        // uso — eliminar_termino() no vuelve a comprobarlo (ver su
        // docblock), así que si falla acá es por otro motivo (no
        // pertenece, es un tipo raíz).
        $resultado = $categoria_srv->eliminar_termino($term_id_a, $user_id);
        if (is_wp_error($resultado)) {
            $this->back_with_error('forbidden');
        }

        $this->back_with_ok();
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

        return $requested !== '' ? wp_validate_redirect($requested, $this->url()) : $this->url();
    }

    private function current_url()
    {
        return home_url(add_query_arg(null, null));
    }
}
