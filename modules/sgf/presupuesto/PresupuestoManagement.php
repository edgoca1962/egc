<?php

namespace EGC\Modules\Sgf\Presupuesto;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Categoria;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * CRUD de Presupuesto sin pasar por wp-admin, mismo patrón que
 * BilleteraManagement/LibroManagement: handlers de admin-post.php,
 * view_state_*() para las vistas, guard_access() en template_redirect.
 *
 * A diferencia de Billetera, acá hacen falta DOS páginas propias, no
 * una: `presupuesto-editar` (el formulario de alta/edición, igual que
 * las otras dos) y `presupuesto` (el listado). Billetera puede apoyarse
 * en su propio archive.php nativo porque su listado es un WP_Query
 * genérico y paginado; el de Presupuesto no lo es — hay que agruparlo
 * por tipo (Ingresos primero con subtotal, después Egresos y Gastos,
 * Transferencias al final, ver monedas_de() más abajo) y separarlo por
 * moneda (sumar montos de monedas distintas no tiene sentido), algo
 * que un archive.php resolviendo un WP_Query normal no puede armar
 * solo. Por eso Presupuesto se registró con `public => false` (ver su
 * docblock) y esta clase arma el reporte a mano con su propio
 * get_posts(), en vez de scope_archive_query() + pre_get_posts.
 *
 * El título de cada Presupuesto (Categoría + Año) lo arma
 * handle_save(), no lo escribe la persona — no tiene sentido pedirle
 * un nombre libre a algo cuya identidad ya la dan esos dos datos (ver
 * titulo_para()).
 *
 * Alta siempre "para uno mismo": igual que Billetera, crear un
 * Presupuesto nuevo lo crea a nombre de quien está logueado — no hay
 * flujo de "cargar en nombre de otro usuario". Quien administra el
 * recurso (sgf_editor, Administrador General) puede EDITAR o VER el
 * presupuesto de cualquiera (por eso el filtro `?usuario=` en el
 * listado), pero no crear uno nuevo a nombre de otro.
 */
class PresupuestoManagement
{
    use Singleton;

    const SLUG_EDITAR = 'presupuesto-editar';

    const SLUG_LISTADO = 'presupuesto';

    const ACTION_SAVE = 'egc_presupuesto_save';

    const ACTION_TRASH = 'egc_presupuesto_trash';

    const NONCE_NAME = '_egc_nonce';

    private $url_editar = null;

    private $url_listado = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save']);
        add_action('admin_post_' . self::ACTION_TRASH, [$this, 'handle_trash']);

        // A diferencia de Billetera (archive nativo, así que
        // UserScope::links_by() arma sola la entrada del dropdown del
        // avatar) Presupuesto se registró con `public => false` (ver el
        // docblock de Presupuesto): no hay URL de archive de la que
        // salga un link solo, en NINGÚN tier. Blog resuelve el mismo
        // problema para su cola de revisión enganchando
        // `egc_dropdown_items_{$post_type}` — acá se usa el mismo
        // mecanismo, pero sumando el link en los dos bloques (autor Y
        // módulo), no solo en uno: a diferencia de la cola de Blog,
        // "Mi presupuesto" es la pantalla que necesita tanto un
        // sgf_autor (carga y ve el propio) como un sgf_editor (además
        // puede ver el de otros con ?usuario=) — no hay una pantalla de
        // administración separada de la de autoservicio.
        add_filter('egc_dropdown_items_' . Presupuesto::POST_TYPE, [$this, 'add_navbar_items'], 10, 2);

        // Enganches al mantenimiento de categorías de
        // CategoriaManagement (ver el docblock de Categoria).
        // Presupuesto, a diferencia de Libro, SÍ tiene una regla de
        // unicidad que puede entrar en conflicto al sustituir (un
        // único presupuesto por dueño+categoría+año, ver
        // existe_presupuesto()) — por eso también se engancha a
        // `egc_categoria_reasignar_validar`, para poder objetar ANTES
        // de que se reasigne nada si la fusión no es segura (ver
        // validar_reasignacion_categoria()).
        add_filter('egc_categoria_uso', [$this, 'contar_uso_categoria'], 10, 2);
        add_filter('egc_categoria_reasignar_validar', [$this, 'validar_reasignacion_categoria'], 10, 4);
        add_action('egc_categoria_reasignar', [$this, 'reasignar_categoria'], 10, 3);
    }

    /**
     * @param  int $conteo  Lo que ya sumaron otros módulos enganchados
     *                      al mismo filtro.
     * @param  int $term_id
     * @return int
     */
    public function contar_uso_categoria($conteo, $term_id)
    {
        $presupuestos = get_posts([
            'post_type'      => Presupuesto::POST_TYPE,
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

        return $conteo + count($presupuestos);
    }

    /**
     * Objeta la sustitución cuando, para el mismo año, tanto la
     * categoría de origen (A) como la de reemplazo (B) ya tienen un
     * presupuesto propio cargado: si se dejara pasar, quedarían dos
     * presupuestos apuntando a la misma categoría+año, justo lo que
     * existe_presupuesto() impide al cargar a mano. La fusión
     * (sumar el monto de A dentro del de B, ver
     * reasignar_categoria()) solo es segura si además comparten
     * moneda — sumar montos de monedas distintas no tiene sentido
     * (mismo criterio que ya aplica monedas_de(), que por eso separa
     * el reporte por moneda). Si difieren, se objeta en vez de
     * fusionar mal: la persona tiene que resolver ese año a mano
     * antes de reintentar.
     *
     * @param  string[] $errores
     * @return string[]
     */
    public function validar_reasignacion_categoria($errores, $term_id_a, $term_id_b, $user_id)
    {
        $presupuesto_a = $this->presupuesto_por_año($term_id_a, $user_id);
        $presupuesto_b = $this->presupuesto_por_año($term_id_b, $user_id);

        foreach ($presupuesto_a as $año => $post_a) {
            if (!isset($presupuesto_b[$año])) {
                continue;
            }

            $moneda_a = (int) get_post_meta($post_a, '_moneda', true);
            $moneda_b = (int) get_post_meta($presupuesto_b[$año], '_moneda', true);

            if ($moneda_a !== $moneda_b) {
                $errores[] = sprintf(
                    /* translators: %d: año en conflicto */
                    __('El presupuesto de %d no se puede fusionar: la categoría de origen y la de reemplazo tienen distinta moneda ese año. Corregilo a mano antes de sustituir.', 'egc'),
                    $año
                );
            }
        }

        return $errores;
    }

    /**
     * Reasigna a $term_id_b los presupuestos que hoy apuntan a
     * $term_id_a. Cuando ambas categorías ya tienen un presupuesto
     * cargado el mismo año, valida_reasignacion_categoria() ya
     * confirmó que comparten moneda — acá se fusiona sumando el monto
     * mensual de A dentro del registro de B y se manda el de A a la
     * papelera, en vez de dejar dos presupuestos para la misma
     * categoría y año.
     */
    public function reasignar_categoria($term_id_a, $term_id_b, $user_id)
    {
        $presupuesto_a = $this->presupuesto_por_año($term_id_a, $user_id);
        $presupuesto_b = $this->presupuesto_por_año($term_id_b, $user_id);

        foreach ($presupuesto_a as $año => $post_a) {
            if (isset($presupuesto_b[$año])) {
                $post_b  = $presupuesto_b[$año];
                $monto_a = (float) get_post_meta($post_a, '_monto', true);
                $monto_b = (float) get_post_meta($post_b, '_monto', true);

                update_post_meta($post_b, '_monto', round($monto_a + $monto_b, 2));
                wp_trash_post($post_a);
                continue;
            }

            wp_set_object_terms($post_a, [$term_id_b], Categoria::TAXONOMY, false);
        }
    }

    /**
     * Presupuestos propios de $user_id con la categoría $term_id,
     * indexados por año — compartido por validar_reasignacion_categoria()
     * y reasignar_categoria() para no repetir la misma consulta.
     *
     * @return array<int,int> año => post_id
     */
    private function presupuesto_por_año($term_id, $user_id)
    {
        $posts = get_posts([
            'post_type'      => Presupuesto::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'tax_query'      => [
                [
                    'taxonomy' => Categoria::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
        ]);

        $por_año = [];
        foreach ($posts as $post) {
            $por_año[(int) get_post_meta($post->ID, '_año', true)] = $post->ID;
        }

        return $por_año;
    }

    /**
     * @param  array  $items Lo que UserScope::links_by() ya armó para
     *                       'presupuesto' en este bloque — vacío,
     *                       porque el post_type no tiene archive nativo
     *                       (ver el comentario del constructor).
     * @param  string $tier  'modulo' o 'autor'. Sin uso acá: a
     *                       diferencia de Blog, este link corresponde a
     *                       los dos por igual.
     * @return array
     */
    public function add_navbar_items($items, $tier)
    {
        $items[] = [
            'label' => __('Mi presupuesto', 'egc'),
            'url'   => $this->url_listado(),
        ];

        return $items;
    }

    public function url_editar()
    {
        if ($this->url_editar === null) {
            $id = Pages::get_instance()->find_or_create(__('Presupuesto', 'egc'), self::SLUG_EDITAR);
            $this->url_editar = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_editar;
    }

    public function url_listado()
    {
        if ($this->url_listado === null) {
            $id = Pages::get_instance()->find_or_create(__('Mi presupuesto', 'egc'), self::SLUG_LISTADO);
            $this->url_listado = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_listado;
    }

    public function guard_access()
    {
        if (is_page(self::SLUG_EDITAR)) {
            $this->guard_editar();
            return;
        }

        if (is_page(self::SLUG_LISTADO)) {
            $this->guard_listado();
        }
    }

    /**
     * Sin post_id ("nuevo presupuesto"): alcanza con la capacidad
     * primitiva del recurso — se va a crear a nombre de quien guarda
     * (ver el docblock de la clase). Con post_id (edición): hace falta
     * edit_post sobre ESE presupuesto puntual — WordPress resuelve
     * propio/ajeno solo, vía map_meta_cap, a partir de
     * edit_presupuestos/edit_others_presupuestos.
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
                wp_safe_redirect($this->url_listado());
                exit;
            }
            return;
        }

        if (!current_user_can($this->cap('edit_posts'))) {
            wp_safe_redirect($this->url_listado());
            exit;
        }
    }

    /**
     * Información financiera privada, igual que Billetera: nadie sin
     * sesión ve nada.
     */
    private function guard_listado()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }
    }

    /**
     * Nombre real de una capacidad primitiva de Presupuesto — nunca
     * `edit_posts` a secas, mismo motivo y mismo mecanismo que ya usan
     * BilleteraManagement::cap() y Categoria::libro_cap(): el
     * capability_type de Presupuesto es `['presupuesto',
     * 'presupuestos']`.
     */
    private function cap($meta_cap)
    {
        $post_type_object = get_post_type_object(Presupuesto::POST_TYPE);

        return $post_type_object ? $post_type_object->cap->$meta_cap : $meta_cap;
    }

    /**
     * @return array{
     *   editing: ?array,
     *   categoria_opciones: array,
     *   moneda_opciones: array<int,string>,
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
        $editing = $post_id ? $this->editable_presupuesto($post_id) : null;

        if ($post_id && !$editing) {
            return null;
        }

        // Sin post_id, el dueño va a ser quien guarda (ver
        // handle_save()): las categorías disponibles son las propias
        // de quien está completando el formulario. Editando, son las
        // del dueño REAL de ese registro — que puede no ser quien
        // mira la pantalla, si quien administra el recurso está
        // editando el presupuesto de otro usuario.
        $dueño_id = $editing ? $editing['dueño_id'] : get_current_user_id();

        return [
            'editing'            => $editing,
            'categoria_opciones' => Categoria::get_instance()->arbol_de($dueño_id),
            'moneda_opciones'    => $this->moneda_opciones(),
            'error'              => $this->message('error'),
            'success'            => (bool) $this->message('ok'),
            'form_action'        => admin_url('admin-post.php'),
            'nonce_action'       => self::ACTION_SAVE,
            'nonce_name'         => self::NONCE_NAME,
            'back_url'           => $this->back_url(),
        ];
    }

    private function editable_presupuesto($post_id)
    {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== Presupuesto::POST_TYPE || !current_user_can('edit_post', $post_id)) {
            return null;
        }

        $terminos     = get_the_terms($post->ID, Categoria::TAXONOMY);
        $categoria_id = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

        return [
            'id'           => $post->ID,
            'dueño_id'     => (int) $post->post_author,
            'monto'        => (float) get_post_meta($post->ID, '_monto', true),
            'moneda'       => (int) get_post_meta($post->ID, '_moneda', true) ?: Billetera::MONEDA_LOCAL,
            'año'          => (int) get_post_meta($post->ID, '_año', true) ?: (int) gmdate('Y'),
            'categoria_id' => $categoria_id,
        ];
    }

    private function moneda_opciones()
    {
        return [
            Billetera::MONEDA_LOCAL      => __('Moneda Local', 'egc'),
            Billetera::MONEDA_EXTRANJERA => __('Moneda Extranjera', 'egc'),
        ];
    }

    /**
     * @return array{
     *   año: int,
     *   año_opciones: array<int,string>,
     *   mes: int,
     *   mes_opciones: array<int,string>,
     *   puede_filtrar_usuario: bool,
     *   usuario_opciones: array<int,string>,
     *   usuario_seleccionado: int,
     *   monedas: array<int,array>,
     *   can_create: bool,
     *   listado_url: string,
     *   error: string,
     *   success: bool,
     * }
     */
    public function view_state_listado()
    {
        $puede_filtrar = UserScope::get_instance()->manages(Presupuesto::POST_TYPE);
        $usuario_id    = $this->usuario_seleccionado($puede_filtrar);
        $año           = $this->año_seleccionado();
        $mes           = $this->mes_seleccionado($año);

        return [
            'año'                   => $año,
            'año_opciones'          => $this->año_opciones(),
            'mes'                   => $mes,
            'mes_opciones'          => $this->mes_opciones(),
            'puede_filtrar_usuario' => $puede_filtrar,
            'usuario_opciones'      => $puede_filtrar ? $this->usuario_opciones() : [],
            'usuario_seleccionado'  => $usuario_id,
            'monedas'               => $this->monedas_de($usuario_id, $año, $mes),
            'can_create'            => $this->can_create(),
            // Para el redirect_to de post-actions.php: conserva el
            // ?año=&mes=&usuario= actual en vez de volver siempre al
            // listado "en blanco" después de eliminar una fila.
            'listado_url'           => $this->current_url(),
            'error'                 => $this->message('error'),
            'success'               => (bool) $this->message('ok'),
        ];
    }

    private function usuario_seleccionado($puede_filtrar)
    {
        if ($puede_filtrar) {
            $user_id = isset($_GET['usuario']) ? absint($_GET['usuario']) : 0;
            if ($user_id && get_userdata($user_id)) {
                return $user_id;
            }
        }

        return get_current_user_id();
    }

    private function año_seleccionado()
    {
        $actual = (int) gmdate('Y');
        $año    = isset($_GET['anio']) ? absint($_GET['anio']) : $actual;

        return $año ?: $actual;
    }

    /**
     * Rango angosto (año actual, el anterior, y tres siguientes) para
     * el filtro del listado — no una lista de "todos los años con
     * datos", que obligaría a otra consulta aparte solo para armar un
     * combo. Es un filtro de navegación, no una fuente de verdad: el
     * usuario igual puede cargar un Presupuesto de cualquier año desde
     * el formulario (ahí el campo Año es un número libre, ver
     * presupuesto-editar.php).
     *
     * @return array<int,string>
     */
    private function año_opciones()
    {
        $actual   = (int) gmdate('Y');
        $opciones = [];

        for ($i = -1; $i <= 3; $i++) {
            $opciones[$actual + $i] = (string) ($actual + $i);
        }

        return $opciones;
    }

    /**
     * Sin selección explícita en la URL: si el año elegido es el año en
     * curso, el mes en curso — es el caso de uso que pidió Edwin
     * ("cuánto llevo acumulado a este mes"); para cualquier otro año,
     * el año completo (12), porque ya pasó entero y no hay "mes actual"
     * que tenga sentido ahí.
     */
    private function mes_seleccionado($año)
    {
        $mes = isset($_GET['mes']) ? absint($_GET['mes']) : 0;

        if ($mes >= 1 && $mes <= 12) {
            return $mes;
        }

        return $año === (int) gmdate('Y') ? (int) gmdate('n') : 12;
    }

    /**
     * Nombres de mes vía WP_Locale — ya traducidos, sin repetir acá una
     * lista propia de meses en español (PRINCIPIO RECTOR: WordPress ya
     * resuelve esto, get_month() es lo mismo que usa internamente el
     * selector de meses de wp-admin).
     *
     * @return array<int,string>
     */
    private function mes_opciones()
    {
        global $wp_locale;

        $opciones = [];
        for ($m = 1; $m <= 12; $m++) {
            $opciones[$m] = $wp_locale ? $wp_locale->get_month(sprintf('%02d', $m)) : (string) $m;
        }

        return $opciones;
    }

    /**
     * Usuarios que tienen al menos un Presupuesto propio — mismo
     * criterio que BilleteraManagement::archive_usuario_opciones(): no
     * "todos los usuarios del sitio" (ruido), y no hace falta cubrir
     * "alguien sin presupuesto todavía" porque acá no existe un flujo
     * de "cargar en nombre de otro" (ver el docblock de la clase) —
     * quien administra el recurso solo necesita ENCONTRAR presupuestos
     * ya cargados, nunca crear el primero de otro usuario.
     *
     * @return array<int,string>
     */
    private function usuario_opciones()
    {
        $ids = get_posts([
            'post_type'      => Presupuesto::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        $usuarios = [];
        foreach ($ids as $post_id) {
            $user_id = (int) get_post_field('post_author', $post_id);
            if ($user_id === 0 || isset($usuarios[$user_id])) {
                continue;
            }

            $user = get_userdata($user_id);
            if ($user) {
                $usuarios[$user_id] = $user->user_email;
            }
        }

        asort($usuarios);

        return $usuarios;
    }

    /**
     * Arma el reporte de presupuesto de $usuario_id para $año,
     * separado por moneda — nunca mezclado: sumar un monto en moneda
     * local con uno en moneda extranjera no tiene sentido (ver el
     * docblock de la clase). Dentro de cada moneda, agrupa por tipo
     * (Categoria::tipo_de()) en el orden financiero que pidió Edwin —
     * Ingresos primero con su subtotal, después Egresos y Gastos,
     * Transferencias al final — y calcula `diferencia` (Ingresos −
     * Egresos); Transferencias queda afuera de ese cálculo, porque no
     * es ingreso ni egreso real (movimientos entre cuentas propias o
     * pago de tarjeta de crédito).
     *
     * $mes es la cantidad de meses transcurridos a acumular: cada fila
     * y cada subtotal muestran `_monto` (el mensual guardado) YA
     * MULTIPLICADO por $mes, no el valor mensual crudo — es el mismo
     * criterio que Edwin dio para el futuro Comparativo ("el sexto mes
     * acumulado real contra el presupuesto, este último se multiplica
     * por seis"), aplicado acá aunque todavía no exista el lado "real"
     * de esa comparación (eso sigue siendo Paso 4 aparte). La consulta
     * de posts no cambia con $mes: sigue siendo por $año únicamente,
     * porque el presupuesto guardado es siempre el mismo dato mensual
     * — $mes solo afecta cómo se lo multiplica para mostrarlo.
     *
     * @return array<int,array{etiqueta:string,grupos:array,diferencia:float}>
     */
    private function monedas_de($usuario_id, $año, $mes)
    {
        $presupuestos = get_posts([
            'post_type'      => Presupuesto::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => $usuario_id,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'   => '_año',
                    'value' => $año,
                ],
            ],
        ]);

        $monedas = [];

        foreach ($presupuestos as $presupuesto) {
            $terminos = get_the_terms($presupuesto->ID, Categoria::TAXONOMY);
            if (empty($terminos) || is_wp_error($terminos)) {
                continue;
            }

            $tipo = Categoria::get_instance()->tipo_de($terminos[0]->term_id);
            if (!$tipo) {
                continue;
            }

            $moneda          = (int) get_post_meta($presupuesto->ID, '_moneda', true);
            $monto_mensual   = (float) get_post_meta($presupuesto->ID, '_monto', true);
            $monto_acumulado = round($monto_mensual * $mes, 2);

            if (!isset($monedas[$moneda])) {
                $monedas[$moneda] = [
                    'etiqueta'   => $this->moneda_opciones()[$moneda] ?? '',
                    'grupos'     => [],
                    'diferencia' => 0.0,
                ];
            }

            if (!isset($monedas[$moneda]['grupos'][$tipo->term_id])) {
                $monedas[$moneda]['grupos'][$tipo->term_id] = [
                    'nombre'   => $tipo->name,
                    'subtotal' => 0.0,
                    'filas'    => [],
                ];
            }

            $monedas[$moneda]['grupos'][$tipo->term_id]['subtotal'] += $monto_acumulado;
            $monedas[$moneda]['grupos'][$tipo->term_id]['filas'][]   = [
                'id'        => $presupuesto->ID,
                'categoria' => $terminos[0]->name,
                'monto'     => $monto_acumulado,
            ];
        }

        $categoria = Categoria::get_instance();

        foreach ($monedas as &$reporte) {
            uasort($reporte['grupos'], function ($a, $b) use ($categoria) {
                return $categoria->prioridad_tipo($a['nombre']) <=> $categoria->prioridad_tipo($b['nombre']);
            });

            $ingresos = 0.0;
            $egresos  = 0.0;
            foreach ($reporte['grupos'] as &$grupo) {
                // Dentro de cada tipo, las filas en orden alfabético de
                // categoría — mismo criterio que pidió Edwin para el
                // árbol de categorías (ver Categoria::arbol_de()), acá
                // aplicado a las filas del reporte. wp_list_sort() es
                // el ordenador nativo de WordPress para arrays de
                // arrays/objetos por campo, no hace falta escribir un
                // comparador propio (PRINCIPIO RECTOR).
                $grupo['filas'] = wp_list_sort($grupo['filas'], 'categoria', 'ASC');

                if ($grupo['nombre'] === 'Ingresos') {
                    $ingresos = $grupo['subtotal'];
                } elseif ($grupo['nombre'] === 'Egresos y Gastos') {
                    $egresos = $grupo['subtotal'];
                }
            }
            unset($grupo);
            $reporte['diferencia'] = round($ingresos - $egresos, 2);
        }
        unset($reporte);

        return $monedas;
    }

    public function can_create()
    {
        return current_user_can($this->cap('edit_posts'));
    }

    /**
     * @return array{id:int, can_edit:bool, edit_url:string, can_trash:bool, trash_action:string, nonce_name:string}
     */
    public function actions_for($post_id)
    {
        $edit_url = add_query_arg('post_id', $post_id, $this->url_editar());

        return [
            'id'           => $post_id,
            'can_edit'     => current_user_can('edit_post', $post_id),
            'edit_url'     => $this->with_return_here($edit_url),
            'can_trash'    => current_user_can('delete_post', $post_id),
            'trash_action' => self::ACTION_TRASH,
            'nonce_name'   => self::NONCE_NAME,
        ];
    }

    public function with_return_here($url)
    {
        return add_query_arg('volver', rawurlencode($this->current_url()), $url);
    }

    private function current_url()
    {
        return home_url(add_query_arg(null, null));
    }

    public function back_url()
    {
        $requested = isset($_GET['volver']) ? wp_unslash($_GET['volver']) : '';

        return $requested !== '' ? wp_validate_redirect($requested, $this->url_listado()) : $this->url_listado();
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
            case 'categoria_invalida':
                return __('Elegí una categoría válida.', 'egc');
            case 'monto_invalido':
                return __('El monto mensual tiene que ser mayor a cero.', 'egc');
            case 'moneda_invalida':
                return __('Seleccioná una moneda válida.', 'egc');
            case 'anio_invalido':
                return __('Ingresá un año válido.', 'egc');
            case 'ya_existe':
                return __('Ya tenés un presupuesto cargado para esa categoría y ese año — editá el que ya existe en vez de crear uno nuevo.', 'egc');
            default:
                return '';
        }
    }

    public function handle_save()
    {
        check_admin_referer(self::ACTION_SAVE, self::NONCE_NAME);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if ($post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                $this->back_with_error('forbidden');
            }
            $dueño_id = (int) get_post_field('post_author', $post_id);
        } else {
            if (!current_user_can($this->cap('edit_posts'))) {
                $this->back_with_error('forbidden');
            }
            $dueño_id = get_current_user_id();
        }

        $categoria_id = isset($_POST['categoria_id']) ? absint($_POST['categoria_id']) : 0;
        if (!$categoria_id || !Categoria::get_instance()->pertenece_a($categoria_id, $dueño_id)) {
            $this->back_with_error('categoria_invalida');
        }

        $monto = isset($_POST['monto']) ? round((float) str_replace(',', '.', wp_unslash($_POST['monto'])), 2) : 0.0;
        if ($monto <= 0) {
            $this->back_with_error('monto_invalido');
        }

        $moneda = isset($_POST['moneda']) ? absint($_POST['moneda']) : 0;
        if (!in_array($moneda, [Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA], true)) {
            $this->back_with_error('moneda_invalida');
        }

        $año = isset($_POST['anio']) ? absint($_POST['anio']) : 0;
        if ($año < 2000 || $año > 2099) {
            $this->back_with_error('anio_invalido');
        }

        // Solo al CREAR: no tiene sentido dos presupuestos del mismo
        // dueño, la misma categoría y el mismo año — se editaría el
        // que ya existe en vez de duplicarlo (ver existe_presupuesto()).
        if (!$post_id && $this->existe_presupuesto($dueño_id, $categoria_id, $año)) {
            $this->back_with_error('ya_existe');
        }

        $data = [
            'post_type'   => Presupuesto::POST_TYPE,
            'post_title'  => $this->titulo_para($categoria_id, $año),
            'post_status' => 'publish',
            'post_author' => $dueño_id,
            'meta_input'  => [
                '_monto'  => $monto,
                '_moneda' => $moneda,
                '_año'    => $año,
            ],
        ];

        if ($post_id) {
            $data['ID'] = $post_id;
            $result = wp_update_post($data, true);
        } else {
            $result = wp_insert_post($data, true);
        }

        if (is_wp_error($result)) {
            $this->back_with_error('forbidden');
        }

        wp_set_object_terms((int) $result, [$categoria_id], Categoria::TAXONOMY, false);

        $this->back_with_ok();
    }

    /**
     * El título lo arma el sistema, no lo escribe la persona (ver el
     * docblock de la clase) — Categoría + Año alcanza para identificar
     * cada registro sin pedir un campo de texto que no aportaría nada.
     */
    private function titulo_para($categoria_id, $año)
    {
        $termino = get_term($categoria_id, Categoria::TAXONOMY);
        $nombre  = ($termino && !is_wp_error($termino)) ? $termino->name : '';

        return sprintf('%s — %d', $nombre, $año);
    }

    private function existe_presupuesto($dueño_id, $categoria_id, $año)
    {
        $existentes = get_posts([
            'post_type'      => Presupuesto::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => $dueño_id,
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => Categoria::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $categoria_id,
                ],
            ],
            'meta_query'     => [
                [
                    'key'   => '_año',
                    'value' => $año,
                ],
            ],
        ]);

        return !empty($existentes);
    }

    public function handle_trash()
    {
        check_admin_referer(self::ACTION_TRASH, self::NONCE_NAME);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post    = $post_id ? get_post($post_id) : null;

        if (!$post || $post->post_type !== Presupuesto::POST_TYPE || !current_user_can('delete_post', $post_id)) {
            $this->back_with_error('forbidden');
        }

        wp_trash_post($post_id);

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

        return $requested !== '' ? wp_validate_redirect($requested, $this->url_listado()) : $this->url_listado();
    }
}
