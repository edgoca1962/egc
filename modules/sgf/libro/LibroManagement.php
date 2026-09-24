<?php

namespace EGC\Modules\Sgf\Libro;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Categoria;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * CRUD de movimientos (Libro) sin pasar por wp-admin, mismo patrón
 * que BilleteraManagement: una sola página (libro-editar, alta Y
 * edición según haya o no ?post_id=), handlers de admin-post.php,
 * view_state_*() para la vista, guard_access() en template_redirect.
 *
 * A diferencia de Billetera, acá no hace falta guard de single/archive
 * ni scope_archive_query(): Libro se registró con `public => false`
 * (ver Libro::register_post_type()) precisamente porque un movimiento
 * nunca se ve por fuera del detalle de su billetera — WordPress ni
 * siquiera genera esas URLs, así que no hay nada que guardar ahí.
 *
 * Las reglas que tienen que cumplirse SIEMPRE (forzar post_parent y
 * post_author al dueño de la billetera, recalcular el saldo) NO viven
 * acá: viven en Libro.php, colgadas de hooks nativos de WordPress que
 * disparan sea cual sea la puerta de guardado — ver su docblock. Esta
 * clase solo se ocupa de lo específico del formulario front-end: quién
 * puede llegar a él, qué le muestra, y sus dos acciones de
 * admin-post.php.
 *
 * Un movimiento siempre nace y vive dentro del contexto de UNA
 * billetera puntual (?billetera_id= para alta, o el post_parent ya
 * guardado para edición) — nunca "suelto": no hay una pantalla de
 * "elegir billetera" dentro de este formulario, esa elección ya se
 * hizo al hacer clic en "Agregar movimiento" desde el detalle de esa
 * billetera (ver billetera/views/single.php).
 *
 * Segunda pantalla, "Mantenimiento de movimientos"
 * (SLUG_MANTENIMIENTO): categorización MASIVA de movimientos ya
 * cargados, filtrados por billetera/fecha/monto/categorización/texto
 * — Edwin la pidió aparte del alta/edición individual de arriba
 * porque resuelve un problema distinto (poner al día una carga
 * histórica sin categorizar, o recategorizar en lote tras corregir un
 * criterio) y siempre está acotada a las PROPIAS billeteras del
 * usuario, sin selector de "usuario" ni siquiera para quien administra
 * Libro (sgf_editor, Administrador General) — a diferencia del resto
 * del módulo, acá "un usuario" (ver el docblock de Categoria) nunca
 * incluye actuar en nombre de otro. Todos los filtros se resuelven
 * con capacidades nativas de WP_Query (ver movimientos_filtrados());
 * nada de SQL propio.
 */
class LibroManagement
{
    use Singleton;

    const SLUG_EDITAR = 'libro-editar';

    const SLUG_MANTENIMIENTO = 'libro-mantenimiento';

    const ACTION_SAVE = 'egc_libro_save';

    const ACTION_TRASH = 'egc_libro_trash';

    const ACTION_RECATEGORIZAR = 'egc_libro_recategorizar';

    const NONCE_NAME = '_egc_nonce';

    /**
     * Tope de filas por página en "Mantenimiento de movimientos" — sin
     * esto, movimientos_filtrados() traía TODO lo que matcheara el
     * filtro de una sola vez (posts_per_page => -1, sin límite). Con
     * una carga masiva de miles de movimientos sin categorizar, eso
     * quedaba lento de pintar y, más grave, exponía al formulario de
     * "Aplicar" al límite nativo de PHP `max_input_vars` (1.000 por
     * defecto en la mayoría de los hostings): con más checkboxes
     * tildados que ese límite, PHP descarta en silencio los que
     * sobran — la recategorización en bloque terminaba aplicándose
     * solo a una fracción de lo tildado, sin ningún error visible.
     * 100 por página deja el checkbox de cada página muy por debajo
     * de ese límite.
     */
    const MOVIMIENTOS_POR_PAGINA = 100;

    private $url_editar = null;

    private $url_mantenimiento = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save']);
        add_action('admin_post_' . self::ACTION_TRASH, [$this, 'handle_trash']);
        add_action('admin_post_' . self::ACTION_RECATEGORIZAR, [$this, 'handle_recategorizar']);

        // Enganches al mantenimiento de categorías de
        // CategoriaManagement (ver el docblock de Categoria): Libro
        // reporta cuántos de sus movimientos usan una categoría, y
        // reasigna los suyos cuando el usuario sustituye una categoría
        // por otra. Libro no tiene ninguna regla de "colisión" que
        // validar ahí (a diferencia de Presupuesto, un movimiento no
        // tiene una restricción de unicidad por categoría), así que
        // solo se engancha a los dos puntos que le aplican, no a
        // `egc_categoria_reasignar_validar`.
        add_filter('egc_categoria_uso', [$this, 'contar_uso_categoria'], 10, 2);
        add_action('egc_categoria_reasignar', [$this, 'reasignar_categoria'], 10, 3);

        // "Mantenimiento de movimientos" no es el archive de Libro
        // (public => false, sin URL propia — ver Libro::register_post_type()),
        // así que UserScope::links_by() no puede armar sola su entrada
        // en el dropdown del avatar. Se suma al mismo grupo que ya usa
        // CategoriaManagement para "Mis categorías" — mismo mecanismo
        // de extensión (egc_dropdown_items_{$post_type}), acá disparado
        // por esta clase en vez de por otro módulo.
        add_filter('egc_dropdown_items_' . Libro::POST_TYPE, [$this, 'add_navbar_items'], 10, 2);
    }

    public function add_navbar_items($items, $tier)
    {
        $items[] = [
            'label' => __('Mantenimiento de movimientos', 'egc'),
            'url'   => $this->url_mantenimiento(),
        ];

        return $items;
    }

    /**
     * @param  int $conteo  Lo que ya sumaron otros módulos enganchados
     *                      al mismo filtro.
     * @param  int $term_id
     * @return int
     */
    public function contar_uso_categoria($conteo, $term_id)
    {
        $movimientos = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => Categoria::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
        ]);

        return $conteo + count($movimientos);
    }

    /**
     * Reasigna a $term_id_b todos los movimientos que hoy apuntan a
     * $term_id_a — CategoriaManagement::handle_sustituir() ya validó
     * ahí que las dos categorías son del mismo usuario y del mismo
     * tipo raíz antes de disparar esto; acá solo se hace la parte de
     * Libro, sin repetir esa validación.
     */
    public function reasignar_categoria($term_id_a, $term_id_b, $user_id)
    {
        $movimientos = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => Categoria::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $term_id_a,
                ],
            ],
        ]);

        foreach ($movimientos as $movimiento_id) {
            wp_set_object_terms($movimiento_id, [$term_id_b], Categoria::TAXONOMY, false);
        }
    }

    public function url_editar()
    {
        if ($this->url_editar === null) {
            $id = Pages::get_instance()->find_or_create(__('Movimiento', 'egc'), self::SLUG_EDITAR);
            $this->url_editar = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_editar;
    }

    public function url_mantenimiento()
    {
        if ($this->url_mantenimiento === null) {
            $id = Pages::get_instance()->find_or_create(__('Mantenimiento de movimientos', 'egc'), self::SLUG_MANTENIMIENTO);
            $this->url_mantenimiento = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_mantenimiento;
    }

    public function guard_access()
    {
        if (is_page(self::SLUG_EDITAR)) {
            $this->guard_editar();
        }

        if (is_page(self::SLUG_MANTENIMIENTO)) {
            $this->guard_mantenimiento();
        }
    }

    /**
     * Mismo piso que CategoriaManagement::guard_access() (ver su
     * docblock): capacidad real sobre Libro, propia o de
     * administración — nunca nombre de rol, así que da igual si quien
     * entra es sgf_autor, sgf_editor o Administrador General (ver la
     * aclaración de Edwin sobre "un usuario" en el docblock de
     * Categoria).
     */
    private function guard_mantenimiento()
    {
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
     * Con post_id (edición): hace falta edit_post sobre ESE movimiento
     * — WordPress resuelve propio/ajeno solo, vía map_meta_cap, a
     * partir de edit_libros/edit_others_libros (el post_author de un
     * movimiento siempre es el dueño de su billetera, nunca de quien
     * lo cargó — ver Libro::forzar_billetera_y_autor()).
     *
     * Sin post_id (alta): hace falta edit_post sobre la BILLETERA de
     * destino (?billetera_id=), no una capacidad genérica de Libro —
     * "¿puedo agregar un movimiento acá?" es exactamente la misma
     * pregunta que "¿puedo editar esta billetera?", porque un
     * movimiento nuevo siempre pertenece a ella.
     */
    private function guard_editar()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if ($post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                wp_safe_redirect(home_url('/'));
                exit;
            }
            return;
        }

        $billetera_id = isset($_GET['billetera_id']) ? absint($_GET['billetera_id']) : 0;
        if (!$billetera_id || !current_user_can('edit_post', $billetera_id)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * @return array{
     *   editing: ?array,
     *   billetera_id: int,
     *   billetera_title: string,
     *   categoria_opciones: array,
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   back_url: string,
     * }|null
     */
    public function view_state_editar()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $editing = $post_id ? $this->editable_movimiento($post_id) : null;

        if ($post_id && !$editing) {
            return null;
        }

        $billetera_id = $editing
            ? $editing['billetera_id']
            : (isset($_GET['billetera_id']) ? absint($_GET['billetera_id']) : 0);

        $billetera = $billetera_id ? get_post($billetera_id) : null;
        if (!$billetera instanceof WP_Post || $billetera->post_type !== Billetera::POST_TYPE) {
            return null;
        }

        // La billetera fija de quién son las categorías válidas para
        // este movimiento (su dueño, no quien está cargando el
        // formulario) — ver el docblock de Categoria::arbol_de().
        $dueño_id = (int) $billetera->post_author;

        return [
            'editing'            => $editing,
            'billetera_id'       => $billetera_id,
            'billetera_title'    => $billetera->post_title,
            'categoria_opciones' => Categoria::get_instance()->arbol_de($dueño_id),
            'error'              => $this->message('error'),
            'success'            => (bool) $this->message('ok'),
            'form_action'        => admin_url('admin-post.php'),
            'nonce_action'       => self::ACTION_SAVE,
            'nonce_name'         => self::NONCE_NAME,
            'back_url'           => $this->back_url($billetera_id),
        ];
    }

    private function editable_movimiento($post_id)
    {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== Libro::POST_TYPE || !current_user_can('edit_post', $post_id)) {
            return null;
        }

        $terminos     = get_the_terms($post->ID, Categoria::TAXONOMY);
        $categoria_id = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

        return [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'fecha'        => get_the_date('Y-m-d', $post),
            'monto'        => (float) get_post_meta($post->ID, '_monto', true),
            'referencia'   => (string) get_post_meta($post->ID, '_referencia', true),
            'categoria_id' => $categoria_id,
            'billetera_id' => (int) $post->post_parent,
        ];
    }

    /**
     * Movimientos de una billetera, más nuevo primero — para el
     * listado del detalle de billetera (billetera/views/single.php).
     * No hay archivo/listado nativo de Libro que pudiera reutilizarse
     * (ver el docblock de la clase): siempre se pide acotado a UNA
     * billetera puntual.
     *
     * @return array<int,array>
     */
    public function movimientos_de($billetera_id)
    {
        $movimientos = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_parent'    => $billetera_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        $filas = [];
        foreach ($movimientos as $movimiento) {
            $filas[] = [
                'id'         => $movimiento->ID,
                'title'      => $movimiento->post_title,
                'fecha'      => get_the_date('', $movimiento),
                'monto'      => (float) get_post_meta($movimiento->ID, '_monto', true),
                'referencia' => (string) get_post_meta($movimiento->ID, '_referencia', true),
                'categoria'  => $this->categoria_label($movimiento->ID),
            ];
        }

        return $filas;
    }

    /**
     * Etiqueta de "Categorización" para un movimiento, para los dos
     * listados que la muestran (movimientos_de(), para
     * billetera/views/single.php, y esta misma clase más abajo, para
     * libro-mantenimiento.php) — un único punto de armado, así que
     * cambiarlo acá alcanza para los dos.
     *
     * Un movimiento siempre tiene asignado UN SOLO término (ver
     * handle_save()/handle_recategorizar()), pero ese término puede
     * ser de cualquiera de los 3 niveles del árbol de Categoria
     * (tipo > categoría > subcategoría, ver su docblock). Si es una
     * subcategoría (tiene padre, y ese padre a su vez tiene padre —
     * o sea, el término está en el tercer nivel), Edwin pidió mostrar
     * padre e hijo juntos ("Categoría, Subcategoría"): la subcategoría
     * sola no dice a qué categoría pertenece. Si el término asignado
     * es de tipo o de categoría (sin ese segundo nivel de padre), se
     * muestra solo su propio nombre, igual que antes.
     */
    private function categoria_label($post_id)
    {
        $terminos = get_the_terms($post_id, Categoria::TAXONOMY);

        if (empty($terminos) || is_wp_error($terminos)) {
            return '';
        }

        $termino = $terminos[0];

        if ($termino->parent) {
            $padre = get_term($termino->parent, Categoria::TAXONOMY);

            if ($padre && !is_wp_error($padre) && $padre->parent) {
                return $padre->name . ', ' . $termino->name;
            }
        }

        return $termino->name;
    }

    /**
     * @return array{
     *   filtros: array,
     *   billetera_opciones: array<int,string>,
     *   categoria_opciones_filtro: array,
     *   categoria_opciones_destino: array,
     *   movimientos: array,
     *   error: string,
     *   success: bool,
     *   recategorizados: int,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   redirect_to: string,
     *   paginacion: array{actual:int, total_paginas:int, total_movimientos:int, desde:int, hasta:int},
     * }
     */
    public function view_state_mantenimiento()
    {
        $user_id   = get_current_user_id();
        $filtros   = $this->filtros_mantenimiento();
        $resultado = $this->movimientos_filtrados($filtros, $user_id);

        // El rango "mostrando X–Y de Z" es aritmética de paginación,
        // no una consulta ni una decisión de negocio — se arma acá
        // para que la vista solo lo imprima (ver SEPARACIÓN DE CAPAS),
        // en vez de que repita la cuenta ella misma.
        $desde = $resultado['total'] > 0
            ? (($filtros['paged'] - 1) * self::MOVIMIENTOS_POR_PAGINA) + 1
            : 0;
        $hasta = min($filtros['paged'] * self::MOVIMIENTOS_POR_PAGINA, $resultado['total']);

        return [
            'filtros'                    => $filtros,
            'billetera_opciones'         => $this->billetera_opciones_propias($user_id),
            'categoria_opciones_filtro'  => $this->categoria_opciones_filtro($user_id),
            // El destino nunca ofrece "Cualquiera" ni "Sin
            // categorización" — Edwin fue explícito: siempre se
            // categoriza (se asigna o se sustituye), nunca se deja o se
            // deja en blanco (ver el docblock de handle_recategorizar()).
            // Por eso es el árbol puro de arbol_de(), sin las dos
            // opciones extra que sí lleva categoria_opciones_filtro().
            'categoria_opciones_destino' => Categoria::get_instance()->arbol_de($user_id),
            'movimientos'                => $resultado['filas'],
            'error'                      => $this->message('error'),
            'success'                    => (bool) $this->message('ok'),
            'recategorizados'            => isset($_GET['ok']) ? absint($_GET['ok']) : 0,
            'form_action'                => admin_url('admin-post.php'),
            'nonce_action'               => self::ACTION_RECATEGORIZAR,
            'nonce_name'                 => self::NONCE_NAME,
            'redirect_to'                => $this->current_url(),
            // Solo tiene sentido pintar controles de paginación con
            // más de una página — la vista decide eso mirando
            // total_paginas, no hace falta un booleano aparte acá.
            'paginacion'                 => [
                'actual'            => $filtros['paged'],
                'total_paginas'     => $resultado['paginas'],
                'total_movimientos' => $resultado['total'],
                'desde'             => $desde,
                'hasta'             => $hasta,
            ],
        ];
    }

    /**
     * Lee y normaliza los filtros de la URL — siempre por GET, para que
     * el resultado quede enlazable/recargable (mismo criterio que ya
     * usa el filtro Año/Mes de Presupuesto). Sin ningún filtro puesto,
     * igual se listan las propias — a diferencia de Presupuesto, acá no
     * hace falta un valor "por defecto" no vacío (como el año actual):
     * el tope natural sigue siendo "todas las propias billeteras".
     *
     * `paged` no es parte del FILTRO en sentido estricto (no acota qué
     * movimientos matchean), pero viaja en el mismo array porque
     * cambia igual qué se lista — y porque así movimientos_filtrados()
     * recibe un solo array con todo lo que necesita para armar la
     * consulta, en vez de un parámetro aparte. Se lee acá, no en
     * normalizar_filtros(), porque paginar solo tiene sentido para el
     * listado (GET) — "Aplicar a TODOS" (ver handle_recategorizar())
     * vuelve a leer los mismos filtros desde POST, pero ignora
     * cualquier noción de página: actúa sobre el conjunto COMPLETO.
     *
     * @return array{billetera_id:int, fecha_desde:string, fecha_hasta:string, monto_desde:string, monto_hasta:string, categoria_filtro:string, texto:string, paged:int}
     */
    private function filtros_mantenimiento()
    {
        $filtros          = $this->normalizar_filtros($_GET);
        $filtros['paged'] = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        return $filtros;
    }

    /**
     * Misma normalización de filtros, a partir de un array cualquiera
     * en vez de $_GET directo — la usa filtros_mantenimiento() (con
     * $_GET, para el listado) y handle_recategorizar() (con $_POST,
     * para "Aplicar a TODOS los que coinciden": ese botón manda los
     * filtros actuales como campos ocultos del formulario — ver la
     * vista — porque su form es POST, no puede reusar la querystring
     * de la URL como sí hace el listado).
     *
     * Se extrae solo ahora que existe este segundo caso que
     * literalmente necesita la misma normalización — antes de esto
     * hubiera sido generalizar sobre un solo uso, ver SRP APLICADO.
     *
     * @return array{billetera_id:int, fecha_desde:string, fecha_hasta:string, monto_desde:string, monto_hasta:string, categoria_filtro:string, texto:string}
     */
    private function normalizar_filtros($origen)
    {
        return [
            'billetera_id' => isset($origen['billetera_id']) ? absint($origen['billetera_id']) : 0,
            'fecha_desde'  => isset($origen['fecha_desde']) ? sanitize_text_field(wp_unslash($origen['fecha_desde'])) : '',
            'fecha_hasta'  => isset($origen['fecha_hasta']) ? sanitize_text_field(wp_unslash($origen['fecha_hasta'])) : '',
            'monto_desde'  => isset($origen['monto_desde']) ? sanitize_text_field(wp_unslash($origen['monto_desde'])) : '',
            'monto_hasta'  => isset($origen['monto_hasta']) ? sanitize_text_field(wp_unslash($origen['monto_hasta'])) : '',
            // '' = Cualquiera, '0' = Sin categorización, cualquier otro
            // valor = un term_id — por eso viaja como string y nunca
            // como absint(): absint('') y absint('0') dan los dos 0, y
            // acá son dos estados distintos que hay que poder separar
            // (ver categoria_opciones_filtro() y construir_args_filtro()).
            'categoria_filtro' => isset($origen['categoria_filtro']) ? sanitize_text_field(wp_unslash($origen['categoria_filtro'])) : '',
            'texto'             => isset($origen['texto']) ? sanitize_text_field(wp_unslash($origen['texto'])) : '',
        ];
    }

    /**
     * Billeteras propias de $user_id para el `<select>` del filtro —
     * "siempre las propias billeteras" (Edwin lo confirmó explícito:
     * ni sgf_editor ni Administrador General eligen acá billeteras de
     * otro usuario). Por eso filtra por post_author DIRECTO, no por
     * UserScope::manages(): esa capacidad autoriza a administrar
     * billeteras ajenas en el resto del módulo, pero esta pantalla en
     * particular nunca las ofrece.
     *
     * @return array<int,string>
     */
    private function billetera_opciones_propias($user_id)
    {
        $billeteras = get_posts([
            'post_type'      => Billetera::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $opciones = [];
        foreach ($billeteras as $billetera) {
            $opciones[$billetera->ID] = $billetera->post_title;
        }

        return $opciones;
    }

    /**
     * Si $billetera_id es una billetera de $user_id — mismo criterio de
     * comparación directa de post_author que billetera_opciones_propias(),
     * para que un billetera_id manipulado a mano en la URL (de otro
     * usuario) no cuele como filtro: en vez de romper o mostrar datos
     * ajenos, movimientos_filtrados() simplemente ignora ese filtro y
     * cae a "todas las propias".
     */
    private function billetera_propia($billetera_id, $user_id)
    {
        $billetera = get_post($billetera_id);

        return $billetera instanceof WP_Post
            && $billetera->post_type === Billetera::POST_TYPE
            && (int) $billetera->post_author === (int) $user_id;
    }

    /**
     * Opciones del `<select>` de filtro por categorización: a
     * diferencia del de "Nueva Categorización" (el árbol puro de
     * Categoria::arbol_de(), ver view_state_mantenimiento()), este
     * agrega dos estados que no son términos reales — "Cualquiera" (no
     * filtra por categorización) y "Sin categorización" (solo
     * movimientos sin ningún término) — con valores que nunca chocan
     * con un term_id real ('' y '0', ver filtros_mantenimiento()).
     *
     * @return array<int,array{id:int|string,nombre:string,profundidad:int}>
     */
    private function categoria_opciones_filtro($user_id)
    {
        $opciones = [
            ['id' => '', 'nombre' => __('Cualquiera', 'egc'), 'profundidad' => 0],
            ['id' => '0', 'nombre' => __('Sin categorización', 'egc'), 'profundidad' => 0],
        ];

        return array_merge($opciones, Categoria::get_instance()->arbol_de($user_id));
    }

    /**
     * Arma el `$args` de WP_Query a partir de los filtros normalizados
     * — cada criterio resuelto con capacidades 100% nativas de
     * WP_Query, sin SQL propio (ver PRINCIPIO RECTOR). Extraída de
     * movimientos_filtrados() (que solo agrega paginación encima) para
     * que movimiento_ids_filtrados() la reuse tal cual, sin paginar —
     * la necesita "Aplicar a TODOS los que coinciden" (ver
     * handle_recategorizar()), que actúa sobre el conjunto COMPLETO
     * del filtro, no solo la página que se está viendo. Recién ahora
     * que existe este segundo caso que de verdad la necesita tiene
     * sentido esta extracción — antes hubiera sido generalizar sobre
     * un solo uso (ver SRP APLICADO).
     *
     * - Billetera: `post_parent`, solo si se eligió una puntual (y es
     *   propia — ver billetera_propia()); "todas" no agrega nada,
     *   porque ya viene acotado por `author => $user_id` más abajo.
     * - Rango de fechas: `date_query` nativo sobre `post_date` — el
     *   mismo campo nativo que ya usa handle_save() para la fecha del
     *   movimiento (Libro nunca tuvo un meta propio de fecha).
     * - Rango de monto: Edwin lo pidió "ambos montos positivos", pero
     *   `_monto` se guarda CON signo (ver
     *   Libro::register_post_meta()) — así que hace falta abs().
     *   `meta_query` no tiene una función abs() nativa, pero el mismo
     *   resultado se logra con dos BETWEEN en OR: el rango positivo tal
     *   cual, y su espejo negativo. Un movimiento de -1500 entra en el
     *   filtro "500 a 2000" por el segundo BETWEEN ([-2000, -500]), sin
     *   tocar cómo se guarda el dato. Solo se aplica si se completaron
     *   los DOS extremos — un solo monto puesto (sin el otro) no alcanza
     *   para armar un BETWEEN válido, así que se ignora en vez de
     *   adivinar un límite.
     * - Categorización: 'Cualquiera' (valor '') no agrega `tax_query`;
     *   'Sin categorización' (valor '0') usa el operador nativo NOT
     *   EXISTS; una categoría puntual se valida antes de usarse
     *   (Categoria::pertenece_a()) — si no es propia del usuario, se
     *   ignora el filtro en vez de devolver movimientos ajenos o armar
     *   un `tax_query` con un term_id inválido.
     * - Texto: el parámetro nativo `'s'` de WP_Query. Funciona como
     *   búsqueda de solo título en la práctica porque un movimiento de
     *   Libro nunca tiene post_content ni post_excerpt (ver
     *   Libro::register_post_type(), `supports => ['title']`).
     *
     * NOTA para Edwin: al filtrar por una categoría puntual, el
     * `tax_query` queda con el comportamiento nativo de WordPress para
     * taxonomías jerárquicas (`include_children => true` por default):
     * elegir un tipo o una categoría también trae los movimientos de
     * sus subcategorías, no solo los que apuntan EXACTAMENTE a ese
     * término — me pareció lo más útil (filtrar por "Vivienda" para ver
     * todo lo de Vivienda sin elegir cada subcategoría a mano), pero
     * avisame si preferís que sea una coincidencia exacta por término.
     *
     * `author => $user_id` es la red de seguridad de fondo, sea cual
     * sea la billetera elegida: como el post_author de un movimiento
     * siempre es el dueño de su billetera (ver
     * Libro::forzar_billetera_y_autor()), y esta pantalla nunca ofrece
     * billeteras ajenas (ver billetera_opciones_propias()), no debería
     * hacer falta en la práctica — pero se deja explícito en vez de
     * confiar solo en que `$filtros['billetera_id']` llegue siempre
     * validado.
     *
     * Deliberadamente sin `posts_per_page`, `paged` ni `no_found_rows`:
     * eso lo decide cada llamador según lo que necesite (paginado y
     * contado, para el listado; todo de una y solo IDs, para el bloque
     * completo).
     *
     * @return array
     */
    private function construir_args_filtro($filtros, $user_id)
    {
        $args = [
            'post_type'   => Libro::POST_TYPE,
            'author'      => $user_id,
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ];

        if ($filtros['billetera_id'] && $this->billetera_propia($filtros['billetera_id'], $user_id)) {
            $args['post_parent'] = $filtros['billetera_id'];
        }

        if ($filtros['fecha_desde'] !== '' || $filtros['fecha_hasta'] !== '') {
            $rango = ['inclusive' => true];

            if ($filtros['fecha_desde'] !== '') {
                $rango['after'] = $filtros['fecha_desde'] . ' 00:00:00';
            }
            if ($filtros['fecha_hasta'] !== '') {
                $rango['before'] = $filtros['fecha_hasta'] . ' 23:59:59';
            }

            $args['date_query'] = [$rango];
        }

        $desde = $filtros['monto_desde'] !== '' ? abs(round((float) str_replace(',', '.', $filtros['monto_desde']), 2)) : null;
        $hasta = $filtros['monto_hasta'] !== '' ? abs(round((float) str_replace(',', '.', $filtros['monto_hasta']), 2)) : null;

        if ($desde !== null && $hasta !== null) {
            if ($desde > $hasta) {
                [$desde, $hasta] = [$hasta, $desde];
            }

            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => '_monto',
                    'value'   => [$desde, $hasta],
                    'type'    => 'NUMERIC',
                    'compare' => 'BETWEEN',
                ],
                [
                    'key'     => '_monto',
                    'value'   => [-$hasta, -$desde],
                    'type'    => 'NUMERIC',
                    'compare' => 'BETWEEN',
                ],
            ];
        }

        if ($filtros['categoria_filtro'] === '0') {
            $args['tax_query'] = [
                [
                    'taxonomy' => Categoria::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ],
            ];
        } elseif ($filtros['categoria_filtro'] !== '') {
            $categoria_id = absint($filtros['categoria_filtro']);

            if ($categoria_id && Categoria::get_instance()->pertenece_a($categoria_id, $user_id)) {
                $args['tax_query'] = [
                    [
                        'taxonomy' => Categoria::TAXONOMY,
                        'field'    => 'term_id',
                        'terms'    => $categoria_id,
                    ],
                ];
            }
        }

        if ($filtros['texto'] !== '') {
            $args['s'] = $filtros['texto'];
        }

        return $args;
    }

    /**
     * Movimientos propios que matchean el filtro, YA paginados — capa
     * fina sobre construir_args_filtro() (ver su docblock para el
     * detalle de cada criterio) que solo agrega lo específico de
     * PAGINAR (MOVIMIENTOS_POR_PAGINA por página, ver su docblock
     * sobre por qué hace falta). Arma un WP_Query en vez de usar
     * get_posts(): get_posts() no devuelve el total de resultados ni
     * la cantidad de páginas, y la vista los necesita para pintar los
     * controles de paginación (`no_found_rows` en `false` a
     * propósito, para que WordPress SÍ calcule ese total).
     *
     * @return array{filas:array<int,array>, total:int, paginas:int}
     */
    private function movimientos_filtrados($filtros, $user_id)
    {
        $args = $this->construir_args_filtro($filtros, $user_id);

        $args['posts_per_page'] = self::MOVIMIENTOS_POR_PAGINA;
        $args['paged']          = $filtros['paged'];
        $args['no_found_rows']  = false;

        $query = new WP_Query($args);

        $filas = [];
        foreach ($query->posts as $movimiento) {
            $filas[] = [
                'id'              => $movimiento->ID,
                'billetera_id'    => (int) $movimiento->post_parent,
                'billetera_title' => get_the_title($movimiento->post_parent),
                'fecha'           => get_the_date('', $movimiento),
                'titulo'          => $movimiento->post_title,
                'monto'           => (float) get_post_meta($movimiento->ID, '_monto', true),
                'categoria'       => $this->categoria_label($movimiento->ID),
            ];
        }

        return [
            'filas'   => $filas,
            'total'   => (int) $query->found_posts,
            'paginas' => (int) $query->max_num_pages,
        ];
    }

    /**
     * TODOS los IDs de movimientos propios que matchean el filtro, sin
     * paginar — lo que necesita "Aplicar a TODOS los que coinciden"
     * (ver handle_recategorizar()) para actuar sobre el conjunto
     * COMPLETO del filtro, no solo la página que se está viendo.
     *
     * get_posts() en vez de WP_Query acá: a diferencia de
     * movimientos_filtrados(), este llamador no necesita found_posts ni
     * max_num_pages (no pagina), solo la lista de IDs — por eso
     * `fields => 'ids'` y `no_found_rows => true`, para no hacerle
     * calcular a WordPress un total que nadie va a leer.
     *
     * @return array<int,int>
     */
    private function movimiento_ids_filtrados($filtros, $user_id)
    {
        $args = $this->construir_args_filtro($filtros, $user_id);

        $args['posts_per_page'] = -1;
        $args['no_found_rows']  = true;
        $args['fields']         = 'ids';

        return get_posts($args);
    }

    /**
     * Recategoriza en bloque los movimientos elegidos — siempre
     * ASIGNA, nunca "quita": Edwin fue explícito en que el `<select>`
     * de destino no ofrece ninguna opción de "sin categorizar" (su
     * primera opción, "Seleccionar Categorización", es un placeholder
     * no seleccionable — ver la vista), así que $categoria_id tiene que
     * llegar válido y propio o la operación entera se rechaza; no hay
     * un camino donde "no elegiste nada" se trate como "dejalos sin
     * categoría".
     *
     * Dos modos, elegidos por cuál de los dos botones envió el
     * formulario (`name="modo"`, ver la vista — sin JavaScript, mismo
     * criterio que el resto del CRUD):
     *
     * - 'pagina' (default): los IDs vienen de los checkboxes tildados
     *   en la página actual (`$_POST['movimiento_ids']`) — datos
     *   arbitrarios enviados por el cliente, así que cada uno se
     *   revalida del lado del servidor: tiene que ser un movimiento de
     *   Libro Y de una billetera de ESTE usuario. Comparación DIRECTA
     *   de post_author (no current_user_can) a propósito, mismo
     *   criterio que Categoria::pertenece_a(): esta pantalla es
     *   "siempre las propias billeteras" incluso para sgf_editor o
     *   Administrador General (Edwin lo confirmó explícito) —
     *   current_user_can('edit_post', …) dejaría pasar movimientos
     *   ajenos que un editor administra, que acá no corresponden.
     *
     * - 'todos': los IDs vienen de movimiento_ids_filtrados(), una
     *   consulta armada del lado del servidor con `author => $user_id`
     *   ya adentro (ver construir_args_filtro()) — no son datos que
     *   mandó el cliente, son el resultado de una consulta que este
     *   mismo código acaba de correr, así que no hace falta repetir el
     *   chequeo de propiedad por cada ID: revalidar ahí sería
     *   desconfiar de una consulta propia, no de una entrada externa
     *   (la revalidación de CRUD Y SEGURIDAD es sobre lo que llega del
     *   cliente, no sobre todo dato sin importar su origen). Los
     *   filtros en sí SÍ pasan por normalizar_filtros() +
     *   construir_args_filtro(), que ya sanitizan y validan cada
     *   criterio igual que en el listado.
     */
    public function handle_recategorizar()
    {
        check_admin_referer(self::ACTION_RECATEGORIZAR, self::NONCE_NAME);

        $user_id      = get_current_user_id();
        $categoria_id = isset($_POST['categoria_id']) ? absint($_POST['categoria_id']) : 0;

        if (!$categoria_id || !Categoria::get_instance()->pertenece_a($categoria_id, $user_id)) {
            $this->back_mantenimiento_con_error('categoria_invalida');
        }

        $modo = (isset($_POST['modo']) && $_POST['modo'] === 'todos') ? 'todos' : 'pagina';

        if ($modo === 'todos') {
            $ids = $this->movimiento_ids_filtrados($this->normalizar_filtros($_POST), $user_id);
        } else {
            $ids = isset($_POST['movimiento_ids']) ? array_map('absint', (array) wp_unslash($_POST['movimiento_ids'])) : [];
            $ids = array_filter(array_unique($ids));
        }

        if (empty($ids)) {
            $this->back_mantenimiento_con_error('sin_seleccion');
        }

        $actualizados = 0;
        foreach ($ids as $movimiento_id) {
            if ($modo === 'pagina') {
                $movimiento = get_post($movimiento_id);

                if (!$movimiento || $movimiento->post_type !== Libro::POST_TYPE || (int) $movimiento->post_author !== $user_id) {
                    continue;
                }
            }

            wp_set_object_terms($movimiento_id, [$categoria_id], Categoria::TAXONOMY, false);
            $actualizados++;
        }

        if ($actualizados === 0) {
            $this->back_mantenimiento_con_error('sin_seleccion');
        }

        $this->back_mantenimiento_con_ok($actualizados);
    }

    private function back_mantenimiento_con_ok($cantidad)
    {
        wp_safe_redirect(add_query_arg('ok', $cantidad, $this->mantenimiento_redirect_target()));
        exit;
    }

    private function back_mantenimiento_con_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->mantenimiento_redirect_target()));
        exit;
    }

    /**
     * A diferencia de redirect_target($billetera_id) (que vuelve al
     * detalle de UNA billetera), acá "volver" es a la propia pantalla
     * de mantenimiento con LOS MISMOS FILTROS aplicados — por eso el
     * fallback es url_mantenimiento() sin filtros, no home_url('/'), y
     * el valor real casi siempre viene del campo oculto `redirect_to`
     * (la URL completa del listado filtrado, armada por
     * view_state_mantenimiento() con current_url()).
     */
    private function mantenimiento_redirect_target()
    {
        $requested = isset($_POST['redirect_to']) ? wp_unslash($_POST['redirect_to']) : '';

        return $requested !== '' ? wp_validate_redirect($requested, $this->url_mantenimiento()) : $this->url_mantenimiento();
    }

    /**
     * Si corresponde mostrar el botón "agregar movimiento" en el
     * detalle de $billetera_id — decisión de autorización, así que
     * vive acá y no en la vista.
     */
    public function can_create($billetera_id)
    {
        return current_user_can('edit_post', $billetera_id);
    }

    /**
     * @return array{id:int, can_edit:bool, edit_url:string, can_trash:bool, trash_action:string, nonce_name:string}
     */
    public function actions_for($post_id)
    {
        return [
            'id'           => $post_id,
            'can_edit'     => current_user_can('edit_post', $post_id),
            'edit_url'     => $this->edit_url_for($post_id),
            'can_trash'    => current_user_can('delete_post', $post_id),
            'trash_action' => self::ACTION_TRASH,
            'nonce_name'   => self::NONCE_NAME,
        ];
    }

    public function edit_url_for($post_id)
    {
        return $this->with_return_here(add_query_arg('post_id', $post_id, $this->url_editar()));
    }

    public function nuevo_url_for($billetera_id)
    {
        return $this->with_return_here(add_query_arg('billetera_id', $billetera_id, $this->url_editar()));
    }

    public function with_return_here($url)
    {
        return add_query_arg('volver', rawurlencode($this->current_url()), $url);
    }

    private function current_url()
    {
        return home_url(add_query_arg(null, null));
    }

    public function back_url($billetera_id)
    {
        $requested = isset($_GET['volver']) ? wp_unslash($_GET['volver']) : '';

        return $requested !== '' ? wp_validate_redirect($requested, $this->fallback_url($billetera_id)) : $this->fallback_url($billetera_id);
    }

    private function fallback_url($billetera_id)
    {
        $url = $billetera_id ? get_permalink($billetera_id) : false;

        return $url ?: home_url('/');
    }

    private function message($param)
    {
        if ($param === 'ok') {
            return isset($_GET['ok']);
        }

        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        switch ($error) {
            case 'forbidden':
                return __('No tenés permiso para hacer eso.', 'egc');
            case 'empty_title':
                return __('La descripción del movimiento no puede quedar vacía.', 'egc');
            case 'categoria_invalida':
                return __('Esa categoría no es válida.', 'egc');
            case 'sin_seleccion':
                return __('Elegí al menos un movimiento para recategorizar.', 'egc');
            default:
                return '';
        }
    }

    public function handle_save()
    {
        check_admin_referer(self::ACTION_SAVE, self::NONCE_NAME);

        $post_id      = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $billetera_id = isset($_POST['billetera_id']) ? absint($_POST['billetera_id']) : 0;

        $billetera = $billetera_id ? get_post($billetera_id) : null;
        if (!$billetera instanceof WP_Post || $billetera->post_type !== Billetera::POST_TYPE) {
            $this->back_with_error('forbidden', $billetera_id);
        }

        if ($post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                $this->back_with_error('forbidden', $billetera_id);
            }
        } elseif (!current_user_can('edit_post', $billetera_id)) {
            $this->back_with_error('forbidden', $billetera_id);
        }

        $title = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        if ($title === '') {
            $this->back_with_error('empty_title', $billetera_id);
        }

        $monto        = isset($_POST['monto']) ? round((float) str_replace(',', '.', wp_unslash($_POST['monto'])), 2) : 0.0;
        $referencia   = isset($_POST['referencia']) ? sanitize_text_field(wp_unslash($_POST['referencia'])) : '';
        $categoria_id = isset($_POST['categoria_id']) ? absint($_POST['categoria_id']) : 0;
        $dueño_id     = (int) $billetera->post_author;

        if ($categoria_id && !Categoria::get_instance()->pertenece_a($categoria_id, $dueño_id)) {
            $this->back_with_error('categoria_invalida', $billetera_id);
        }

        $data = [
            'post_type'   => Libro::POST_TYPE,
            'post_parent' => $billetera_id,
            'post_author' => $dueño_id,
            'post_title'  => $title,
            // En SGF no existe un flujo de revisión pendiente (ver
            // modules/sgf/manifest.php): cada usuario publica sus
            // propios registros de inmediato, sin el chequeo de
            // publish_posts que sí hace falta en Billetera/Blog.
            'post_status' => 'publish',
            'meta_input'  => [
                '_monto'      => $monto,
                '_haber'      => $monto > 0 ? $monto : 0,
                '_debe'       => $monto < 0 ? abs($monto) : 0,
                '_referencia' => $referencia,
            ],
        ];

        $fecha = isset($_POST['fecha']) ? sanitize_text_field(wp_unslash($_POST['fecha'])) : '';
        if ($fecha !== '') {
            $timestamp = strtotime($fecha);
            if ($timestamp) {
                $data['post_date'] = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }

        if ($post_id) {
            $data['ID'] = $post_id;
            $result = wp_update_post($data, true);
        } else {
            $result = wp_insert_post($data, true);
        }

        if (is_wp_error($result)) {
            $this->back_with_error('forbidden', $billetera_id);
        }

        wp_set_object_terms((int) $result, $categoria_id ? [$categoria_id] : [], Categoria::TAXONOMY, false);

        $this->back_with_ok($billetera_id);
    }

    public function handle_trash()
    {
        check_admin_referer(self::ACTION_TRASH, self::NONCE_NAME);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post    = $post_id ? get_post($post_id) : null;

        if (!$post || $post->post_type !== Libro::POST_TYPE || !current_user_can('delete_post', $post_id)) {
            $this->back_with_error('forbidden', 0);
        }

        $billetera_id = (int) $post->post_parent;

        wp_trash_post($post_id);

        $this->back_with_ok($billetera_id);
    }

    private function back_with_ok($billetera_id)
    {
        wp_safe_redirect(add_query_arg('ok', '1', $this->redirect_target($billetera_id)));
        exit;
    }

    private function back_with_error($error, $billetera_id)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->redirect_target($billetera_id)));
        exit;
    }

    private function redirect_target($billetera_id)
    {
        $requested = isset($_POST['redirect_to']) ? wp_unslash($_POST['redirect_to']) : '';

        return $requested !== '' ? wp_validate_redirect($requested, $this->fallback_url($billetera_id)) : $this->fallback_url($billetera_id);
    }
}
