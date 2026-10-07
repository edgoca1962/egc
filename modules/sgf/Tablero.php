<?php

namespace EGC\Modules\Sgf;

use EGC\Core\Account;
use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Billetera\BilleteraManagement;
use EGC\Modules\Sgf\Libro\Libro;
use EGC\Modules\Sgf\Libro\LibroManagement;
use EGC\Modules\Sgf\Presupuesto\PresupuestoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — Tablero (panel de control) de SGF: una sola Página
 * (SLUG), sin CRUD propio ni admin-post.php (a diferencia del resto del
 * módulo, esta pantalla no escribe nada, solo agrega y muestra), que
 * arma en un solo `view_state()` todo lo que su vista necesita para
 * pintar sus seis secciones.
 *
 * Vive en la raíz de `modules/sgf/`, junto a Categoria — mismo motivo:
 * es transversal a Billetera, Libro, Categoria y Presupuesto, no un
 * detalle de ninguno de esos recursos puntuales (ver el docblock de
 * Categoria sobre por qué una clase sin subcarpeta intermedia es
 * justamente "esto es del módulo, no de un recurso").
 *
 * Reusa, sin cambiarles el comportamiento, filtros y reportes que ya
 * existían para otras pantallas — promovidos de `private` a `public`
 * en LibroManagement y PresupuestoManagement para este segundo/tercer
 * caso real que los necesita (ver sus propios docblocks, cada uno dice
 * por qué): el filtro de Mantenimiento de movimientos
 * (normalizar_filtros/construir_args_filtro/billetera_propia/
 * billetera_opciones_propias/categoria_opciones_filtro) y el reporte de
 * Presupuesto (año_seleccionado/mes_seleccionado/mes_opciones/
 * monedas_de). Los años del selector del Comparativo, en cambio, son
 * propios (ver años_con_movimientos()): el rango fijo de Presupuesto
 * no alcanza para quien tiene historia de más años.
 *
 * Cinco de sus seis secciones dependen del filtro principal (el mismo
 * de Mantenimiento, con una única regla propia: sin ningún rango de
 * fecha puesto, se asume una ventana de 12 meses que termina al final
 * del mes del movimiento más reciente — ver filtros_panel()); la
 * sexta, el Comparativo, es deliberadamente independiente (Edwin fue
 * explícito: "el comparativo no hereda el filtro") y tiene su propio
 * selector de Año/Mes, reusando el mismo de Presupuesto.
 *
 * Alcance siempre "mis billeteras" — igual que Mantenimiento de
 * movimientos, sin excepción para quien administra el recurso (Edwin
 * lo confirmó explícito): por eso $user_id es siempre
 * get_current_user_id(), nunca un ?usuario= como sí ofrece el archive
 * de Billetera o el listado de Presupuesto.
 */
class Tablero
{
    use Singleton;

    const SLUG = 'tablero';

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);

        // Mismo mecanismo de extensión que ya usan CategoriaManagement,
        // LibroManagement y LibroImportacion para sumar su entrada al
        // dropdown del avatar: el Tablero tampoco tiene una URL de
        // archive nativa de la que UserScope::links_by() pudiera armar
        // sola un link (ver el docblock de la clase).
        add_filter('egc_dropdown_items_' . Libro::POST_TYPE, [$this, 'add_navbar_items'], 10, 2);
    }

    public function add_navbar_items($items, $tier)
    {
        $items[] = [
            'label' => __('Tablero', 'egc'),
            'url'   => $this->url(),
        ];

        return $items;
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Tablero', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * Mismo piso que Mantenimiento de movimientos (ver
     * LibroManagement::guard_mantenimiento()): capacidad real sobre
     * Libro, propia o de administración — nunca nombre de rol. El
     * Tablero no tiene una capacidad más restrictiva propia: es una
     * vista agregada de datos que ya son visibles, uno por uno, en
     * otras pantallas a las que ya llega quien tiene acceso a Libro.
     *
     * El fallback de "está logueado pero sin capacidad sobre Libro" es
     * Account::url() ("Mi cuenta"), NUNCA home_url('/') (bug real que
     * encontró Edwin): home_url('/') es una URL fija, no
     * necesariamente una página SIN este mismo guard — si el sitio
     * define el Tablero como página de inicio (Edwin lo hizo), home_url('/')
     * pasa a ser la URL DEL PROPIO TABLERO, y quien no tiene acceso
     * queda en un loop infinito de redirects contra sí mismo (por eso
     * "la página no se muestra": el navegador corta el loop con un
     * error de demasiadas redirecciones). "Mi cuenta" es segura como
     * destino porque su propio guard_access() (ver Account.php) exige
     * únicamente is_user_logged_in(), sin ninguna capacidad de módulo
     * de por medio — cualquier usuario logueado, tenga o no acceso a
     * Libro, siempre puede aterrizar ahí sin volver a rebotar. El caso
     * "no está logueado" de acá abajo no tiene este problema: LoginPage
     * es una página distinta del Tablero en cualquier configuración.
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
            wp_safe_redirect(Account::get_instance()->url());
            exit;
        }
    }

    /**
     * @return array{
     *   primeros_pasos: ?array{caso:string, leyenda:string, url:string, boton:string},
     *   filtros: array,
     *   billetera_opciones: array<int,string>,
     *   categoria_opciones_filtro: array,
     *   saldo_por_moneda: array<int,array{etiqueta:string,total:float}>,
     *   serie_mensual: array<int,array{etiqueta:string,meses:array,ingresos:array,egresos:array}>,
     *   paretos: array{ingresos:array,egresos:array},
     *   sin_categorizar: array{cantidad:int,monto_neto:float},
     *   año_comparativo: int,
     *   año_opciones: array<int,string>,
     *   mes_comparativo: int,
     *   mes_opciones: array<int,string>,
     *   waterfall_presupuesto: array<int,array>,
     *   comparativo: array<int,array>,
     *   interanual_año_actual: ?int,
     *   interanual_año_anterior: ?int,
     *   interanual_mes_opciones: array<int,string>,
     *   interanual_mes_seleccionado: ?int,
     *   interanual_waterfall: array<int,array>,
     * }
     */
    public function view_state()
    {
        $user_id = get_current_user_id();
        $filtros = $this->filtros_panel($user_id);

        $movimientos  = $this->movimientos_filtrados($filtros, $user_id);
        $clasificados = $this->clasificar($movimientos);
        $meses        = $this->meses_del_rango($filtros['fecha_desde'], $filtros['fecha_hasta']);

        $monedas_disponibles = $this->monedas_con_billetera($user_id);

        // Subconjunto de $monedas_disponibles con actividad categorizada
        // real (ver moneda_tiene_actividad_categorizada()): más estricto
        // que "tiene billetera", así que siempre es un subconjunto, y
        // conserva el mismo orden (local primero) porque se recorre en
        // ese orden. Gobierna Serie mensual, los 2 Pareto y el
        // Comparativo interanual — el Saldo por moneda sigue usando
        // $monedas_disponibles tal cual.
        $monedas_con_graficos = array_values(array_filter(
            $monedas_disponibles,
            function ($moneda_id) use ($user_id) {
                return $this->moneda_tiene_actividad_categorizada($user_id, $moneda_id);
            }
        ));

        // Último período con movimientos reales: ancla tanto el
        // Comparativo interanual como el DEFAULT de mes del Comparativo
        // real-vs-presupuesto (ver ultimo_periodo_con_datos() sobre por
        // qué se usa el último movimiento real y nunca el año/mes de
        // calendario).
        $periodo_interanual = $this->ultimo_periodo_con_datos($user_id);

        $año_comparativo = PresupuestoManagement::get_instance()->año_seleccionado();

        // El mes por defecto del Comparativo real-vs-presupuesto es el
        // del último movimiento real, pero solo si el año que se está
        // viendo ES el de ese movimiento: en cualquier otro año ese mes
        // no tiene relación con lo que se muestra, y rige el default
        // de siempre (ver PresupuestoManagement::mes_seleccionado()).
        $mes_ultimo_real = ($periodo_interanual !== null && $periodo_interanual['año'] === $año_comparativo)
            ? $periodo_interanual['mes']
            : null;
        $mes_comparativo = PresupuestoManagement::get_instance()->mes_seleccionado($año_comparativo, $mes_ultimo_real);

        $año_actual_interanual   = $periodo_interanual['año'] ?? null;
        $año_anterior_interanual = $año_actual_interanual !== null ? $año_actual_interanual - 1 : null;
        $mes_max_interanual      = $periodo_interanual['mes'] ?? null;
        $mes_interanual          = $mes_max_interanual !== null ? $this->mes_interanual_seleccionado($mes_max_interanual) : null;

        return [
            'primeros_pasos'            => $this->primeros_pasos($user_id),
            'filtros'                   => $filtros,
            'billetera_opciones'        => LibroManagement::get_instance()->billetera_opciones_propias($user_id),
            'categoria_opciones_filtro' => LibroManagement::get_instance()->categoria_opciones_filtro($user_id),
            'saldo_por_moneda'          => $this->saldo_por_moneda($user_id, $filtros['billetera_id'], $monedas_disponibles),
            'serie_mensual'             => $this->serie_mensual($clasificados['serie'], $meses, $monedas_con_graficos),
            'paretos'                   => $this->paretos($clasificados['pareto'], $monedas_con_graficos),
            'sin_categorizar'           => [
                'cantidad'   => $clasificados['sin_categorizar']['cantidad'],
                'monto_neto' => round($clasificados['sin_categorizar']['monto_neto'], 2),
            ],
            'año_comparativo' => $año_comparativo,
            'año_opciones'    => $this->años_con_movimientos($user_id, $año_comparativo),
            'mes_comparativo' => $mes_comparativo,
            'mes_opciones'    => PresupuestoManagement::get_instance()->mes_opciones(),
            'waterfall_presupuesto' => $this->waterfall_presupuesto($user_id, $año_comparativo, $mes_comparativo),
            'comparativo'     => $this->comparativo($user_id, $año_comparativo, $mes_comparativo),

            'interanual_año_actual'       => $año_actual_interanual,
            'interanual_año_anterior'     => $año_anterior_interanual,
            'interanual_mes_opciones'     => $mes_max_interanual !== null ? $this->mes_opciones_interanual($mes_max_interanual) : [],
            'interanual_mes_seleccionado' => $mes_interanual,
            'interanual_waterfall'        => $mes_interanual !== null
                ? $this->waterfall_interanual($user_id, $año_actual_interanual, $año_anterior_interanual, $mes_interanual, $monedas_con_graficos)
                : [],
        ];
    }

    /**
     * "Primeros pasos": el aviso que se imprime ARRIBA DE TODO en la
     * vista (sin ocultar el resto de las secciones, Edwin lo pidió
     * explícito) mientras Tablero todavía no tiene nada real que
     * mostrarle a $user_id — tres casos, EXCLUYENTES entre sí y
     * evaluados en este orden (el mismo orden en que Edwin los dio: es
     * la secuencia natural de alta — primero una billetera, después un
     * movimiento, después categorizarlo; el primero que aplica gana):
     *
     * 1. Ninguna billetera propia todavía → enlace a
     *    BilleteraManagement::url_editar() (el formulario para CREAR
     *    una).
     * 2. Ya tiene billeteras, pero ningún movimiento cargado en
     *    NINGUNA de ellas → enlace a BilleteraManagement::archive_url()
     *    (el listado de sus propias billeteras). Un movimiento siempre
     *    se agrega desde la billetera puntual a la que pertenece,
     *    nunca desde una pantalla genérica del módulo Libro (ver
     *    LibroManagement::nuevo_url_for(), que exige un
     *    `?billetera_id=`) — como acá no hay ninguna billetera puntual
     *    elegida de antemano (puede tener varias), el destino
     *    correcto es el listado para que elija una y agregue el
     *    movimiento desde ahí, no url_editar() (esa es para crear una
     *    billetera NUEVA, no corresponde si ya tiene al menos una) —
     *    Edwin fue explícito en que este caso también "lleva a
     *    billetera", con esta leyenda distinta.
     * 3. Tiene movimientos, pero NINGUNO está categorizado todavía →
     *    enlace a Mantenimiento de movimientos con el filtro "sin
     *    categorizar" ya aplicado (`categoria_filtro=0`, mismo
     *    criterio que ya usa LibroManagement::construir_args_filtro()
     *    para ese filtro). A propósito exige CERO categorizados, no
     *    "al menos uno sin categorizar" (Edwin lo confirmó explícito):
     *    en cuanto categoriza el primer movimiento, el Tablero ya
     *    tiene datos reales que mostrar en sus secciones, y el aviso
     *    de 'sin_categorizar' (cantidad + monto neto, ver
     *    view_state()) sigue cubriendo, sin este banner, el caso de
     *    que queden sueltos algunos sin categorizar.
     *
     * null si ninguno de los tres aplica (ya tiene al menos un
     * movimiento categorizado) — la vista no imprime nada en ese caso.
     *
     * Las tres consultas de existencia usan `posts_per_page => 1`:
     * alcanza con saber que existe AL MENOS UNO, nunca hace falta
     * traer la lista completa para esto — mismo criterio que ya usa
     * LibroImportacion::existe_duplicado().
     *
     * `boton` es el texto del enlace en sí (la vista no decide texto
     * por caso, solo pinta — ver views/tablero.php): "Ir a Billetera"
     * en los casos 1 y 2 (el destino real es Billetera en los dos,
     * aunque con leyenda distinta), "Ir a Mantenimiento" en el caso 3.
     *
     * @return array{caso:string, leyenda:string, url:string, boton:string}|null
     */
    private function primeros_pasos($user_id)
    {
        $tiene_billeteras = !empty(get_posts([
            'post_type'      => Billetera::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]));

        if (!$tiene_billeteras) {
            return [
                'caso'    => 'billetera',
                'leyenda' => __('Todavía no tenés ninguna billetera cargada. Agregá al menos una para empezar a usar el Tablero.', 'egc'),
                'url'     => BilleteraManagement::get_instance()->url_editar(),
                'boton'   => __('Ir a Billetera', 'egc'),
            ];
        }

        $tiene_movimientos = !empty(get_posts([
            'post_type'      => Libro::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]));

        if (!$tiene_movimientos) {
            return [
                'caso'    => 'movimiento',
                'leyenda' => __('Tenés billeteras cargadas, pero todavía no ingresaste ningún movimiento.', 'egc'),
                'url'     => BilleteraManagement::get_instance()->archive_url(),
                'boton'   => __('Ir a Billetera', 'egc'),
            ];
        }

        $tiene_categorizado = !empty(get_posts([
            'post_type'      => Libro::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => Categoria::TAXONOMY,
                    'operator' => 'EXISTS',
                ],
            ],
        ]));

        if (!$tiene_categorizado) {
            return [
                'caso'    => 'categoria',
                'leyenda' => __('Ingresaste movimientos, pero todavía no categorizaste ninguno.', 'egc'),
                'url'     => add_query_arg('categoria_filtro', '0', LibroManagement::get_instance()->url_mantenimiento()),
                'boton'   => __('Ir a Mantenimiento', 'egc'),
            ];
        }

        return null;
    }

    /**
     * Monedas (subconjunto de [Billetera::MONEDA_LOCAL,
     * Billetera::MONEDA_EXTRANJERA]) para las que $user_id tiene AL
     * MENOS UNA billetera propia — chequeo puramente estructural, que
     * a propósito ignora el filtro de billetera_id del panel (ese
     * filtro solo acota qué movimientos se cuentan; esto decide si la
     * columna entera de una moneda debe existir en la pantalla).
     *
     * Esto es lo único que decide si se muestra o se oculta el Saldo
     * por moneda (ver saldo_por_moneda()): sin ninguna billetera en una
     * moneda, esa tarjeta de saldo se oculta del todo; con al menos
     * una billetera, el saldo se muestra aunque sea $0.00 (billetera
     * sin movimientos todavía) — Edwin fue explícito en esta
     * distinción. Para las demás secciones de gráficos (línea mensual,
     * los 4 Pareto, Comparativo interanual) el criterio de ocultamiento
     * es otro, más estricto — ver moneda_tiene_actividad_categorizada().
     * El Comparativo real vs. presupuestado tiene, a su vez, su propia
     * regla independiente de ambos — ver comparativo_real().
     *
     * El ORDEN del resultado es fijo — Moneda Local primero, Moneda
     * Extranjera después — nunca el orden en que get_posts() haya
     * devuelto las billeteras: Edwin pidió explícito que, cuando hay
     * las dos, la local se vea siempre primero. Por eso este método
     * arma primero el CONJUNTO de monedas presentes ($presentes, sin
     * importar el orden de la consulta) y recién después lo recorre en
     * el orden fijo que define el resultado.
     *
     * @return array<int,int>
     */
    private function monedas_con_billetera($user_id)
    {
        $billeteras_ids = get_posts([
            'post_type'      => Billetera::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        $presentes = [];
        foreach ($billeteras_ids as $billetera_id) {
            $moneda = (int) get_post_meta($billetera_id, '_moneda', true);
            if (!in_array($moneda, [Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA], true)) {
                continue;
            }
            $presentes[$moneda] = true;
        }

        $monedas = [];
        foreach ([Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA] as $moneda_id) {
            if (isset($presentes[$moneda_id])) {
                $monedas[] = $moneda_id;
            }
        }

        return $monedas;
    }

    /**
     * true si $user_id tiene, en alguna billetera propia de $moneda_id,
     * AL MENOS UN movimiento que cuente para un gráfico — mismo
     * criterio de inclusión que clasificar() (categorizado y de tipo
     * Ingresos o Egresos y Gastos; Transferencias y sin categorizar NO
     * cuentan, ver su docblock) pero sin pasar por movimientos_filtrados():
     * esto es deliberadamente de "toda la vida", nunca acotado por el
     * filtro principal del panel ni por su rango de fecha — Edwin fue
     * explícito en que el ocultamiento de una columna es estructural,
     * no depende de qué esté filtrado en un momento dado.
     *
     * Gobierna el ocultamiento de la Serie mensual, los 2 Pareto y el
     * Comparativo interanual (ver view_state(), que arma
     * $monedas_con_graficos llamando esto por cada moneda de
     * monedas_con_billetera()) — a diferencia del Saldo por moneda, que
     * se muestra con solo tener la billetera, aunque esté en cero (ver
     * monedas_con_billetera()). El Comparativo real vs. presupuestado
     * no usa este método: tiene su propia regla, ya resuelta por
     * comparativo_real() para el Año/Mes puntual de su propio selector
     * (ver su docblock).
     *
     * La consulta de existencia usa post_parent__in sobre las
     * billeteras propias de esa moneda (mismo vínculo nativo
     * Libro→Billetera que ya usa clasificar()) y corta en el primer
     * movimiento que resuelva a un tipo válido — no hace falta traer ni
     * clasificar la lista completa para esto.
     */
    private function moneda_tiene_actividad_categorizada($user_id, $moneda_id)
    {
        $billeteras_ids = get_posts([
            'post_type'      => Billetera::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_moneda',
                    'value' => $moneda_id,
                ],
            ],
        ]);

        if (empty($billeteras_ids)) {
            return false;
        }

        $movimientos_ids = get_posts([
            'post_type'       => Libro::POST_TYPE,
            'author'          => $user_id,
            'post_parent__in' => $billeteras_ids,
            'post_status'     => 'publish',
            'posts_per_page'  => -1,
            'no_found_rows'   => true,
            'fields'          => 'ids',
            'tax_query'       => [
                [
                    'taxonomy' => Categoria::TAXONOMY,
                    'operator' => 'EXISTS',
                ],
            ],
        ]);

        if (empty($movimientos_ids)) {
            return false;
        }

        $categoria = Categoria::get_instance();

        foreach ($movimientos_ids as $movimiento_id) {
            $terminos = get_the_terms($movimiento_id, Categoria::TAXONOMY);
            $term_id  = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

            if (!$term_id) {
                continue;
            }

            $tipo = $categoria->tipo_de($term_id);
            if ($tipo && $tipo->name !== 'Transferencias') {
                return true;
            }
        }

        return false;
    }

    /**
     * Año y mes del movimiento MÁS RECIENTE que cuenta para un
     * acumulado real (mismo criterio de inclusión que
     * comparativo_real(): tiene que resolver a Ingresos o Egresos y
     * Gastos, nunca Transferencias ni sin categorización) — de TODAS
     * las billeteras propias de $user_id, sin importar el filtro del
     * panel ni el rango de fecha (misma independencia que el resto
     * del Comparativo).
     *
     * Esto es lo que ancla la sección "Comparativo interanual" (real
     * acumulado de este año vs. el mismo acumulado del año anterior):
     * Edwin fue explícito en que el "año/mes actual" de esa
     * comparación es el más reciente CON INFORMACIÓN, nunca el
     * año/mes de CALENDARIO (gmdate('Y')/gmdate('n')) — si todavía no
     * cargó ningún movimiento del mes en curso, no tiene sentido
     * ofrecerle comparar contra un acumulado vacío. El mismo criterio
     * rige el mes por defecto del Comparativo real-vs-presupuesto (ver
     * view_state()): el real se CARGA hacia atrás, a medida que
     * ocurre, así que un mes sin movimientos todavía mostraría un
     * acumulado incompleto. (El Presupuesto, en cambio, se carga hacia
     * adelante; su propio default sin ancla de real sigue siendo el
     * mes de calendario, ver PresupuestoManagement::mes_seleccionado().)
     *
     * Se detiene en el primer movimiento que matchea, recorriendo
     * `orderby => date, order => DESC` — no hace falta traer más que
     * el ID y la fecha de cada uno (`fields => 'ids'`, get_the_date()
     * y get_the_terms() aceptan un ID tal cual un objeto \WP_Post).
     *
     * @return array{año:int, mes:int}|null null si $user_id no tiene
     *                                      ningún movimiento que cuente.
     */
    private function ultimo_periodo_con_datos($user_id)
    {
        $movimientos_ids = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);

        $categoria = Categoria::get_instance();

        foreach ($movimientos_ids as $movimiento_id) {
            $terminos = get_the_terms($movimiento_id, Categoria::TAXONOMY);
            $term_id  = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

            if (!$term_id) {
                continue;
            }

            $tipo = $categoria->tipo_de($term_id);
            if (!$tipo || !in_array($tipo->name, ['Ingresos', 'Egresos y Gastos'], true)) {
                continue;
            }

            return [
                'año' => (int) get_the_date('Y', $movimiento_id),
                'mes' => (int) get_the_date('n', $movimiento_id),
            ];
        }

        return null;
    }

    /**
     * Mes elegido por la persona para el Comparativo interanual — GET
     * propio (`mes_interanual`), separado del `mes` que ya usa el
     * selector Año/Mes del Comparativo real-vs-presupuesto (son dos
     * secciones independientes, con sus propios controles, mismo
     * criterio que ya separa los distintos `<form>` de esta vista).
     * Nunca puede superar $mes_max (el mes de ultimo_periodo_con_datos()):
     * no tiene sentido comparar contra un acumulado de un mes que el
     * año actual todavía no alcanzó. Sin selección en la URL, o una
     * fuera de rango, el default es el propio $mes_max — "el año/mes
     * actual más reciente", tal como pidió Edwin.
     */
    private function mes_interanual_seleccionado($mes_max)
    {
        $mes = isset($_GET['mes_interanual']) ? absint($_GET['mes_interanual']) : 0;

        if ($mes >= 1 && $mes <= $mes_max) {
            return $mes;
        }

        return $mes_max;
    }

    /**
     * Nombres de mes (reusa PresupuestoManagement::mes_opciones(), ya
     * traducidos vía WP_Locale — PRINCIPIO RECTOR) recortados a los
     * primeros $mes_max — Edwin fue explícito: el <select> "mostrará
     * hasta el mes más reciente con información", nunca los 12 meses
     * completos. array_slice() con $preserve_keys = true porque el
     * value de cada <option> tiene que seguir siendo el número de mes
     * (1..$mes_max), no la posición dentro del array recortado.
     *
     * @return array<int,string>
     */
    private function mes_opciones_interanual($mes_max)
    {
        return array_slice(PresupuestoManagement::get_instance()->mes_opciones(), 0, $mes_max, true);
    }

    /**
     * Misma normalización que Mantenimiento de movimientos
     * (LibroManagement::normalizar_filtros(), ahora público), más una
     * regla de default que solo tiene sentido ACÁ: sin ningún rango de
     * fecha puesto, se asume una ventana de 12 meses (Edwin lo
     * confirmó explícito) — un tablero sin ningún recorte de fecha no
     * puede quedar agregando TODA la historia de una, a diferencia de
     * Mantenimiento, donde "sin filtro" (ver todo, para categorizar en
     * bloque) sí es un estado de partida válido y buscado.
     *
     * La ventana ya no termina HOY sino al FINAL DEL MES del movimiento
     * más reciente (ver fecha_ultimo_movimiento()), y arranca el día 1
     * del mes 11 meses antes de ese — así el default siempre abarca los
     * 12 meses calendario que terminan en el último mes con
     * información, igual que ya hace el Comparativo interanual (ver
     * ultimo_periodo_con_datos()). Sin ningún movimiento todavía, cae
     * al criterio anterior: el mes de calendario en curso.
     *
     * Solo aplica cuando NO hay ningún rango puesto: si la persona
     * eligió un desde y/o un hasta, se respeta tal cual.
     *
     * @return array{billetera_id:int, fecha_desde:string, fecha_hasta:string, monto_desde:string, monto_hasta:string, categoria_filtro:string, texto:string}
     */
    private function filtros_panel($user_id)
    {
        $filtros = LibroManagement::get_instance()->normalizar_filtros($_GET);

        if ($filtros['fecha_desde'] === '' && $filtros['fecha_hasta'] === '') {
            $ancla = new \DateTime($this->fecha_ultimo_movimiento($user_id) ?? gmdate('Y-m-d'));

            // "first day of this month" ANTES de restar meses: restar
            // desde un día 29-31 puede desbordar al mes siguiente
            // (31 de marzo menos 11 meses no existe en abril).
            $desde = clone $ancla;
            $desde->modify('first day of this month');
            $desde->modify('-11 months');

            $hasta = clone $ancla;
            $hasta->modify('last day of this month');

            $filtros['fecha_desde'] = $desde->format('Y-m-d');
            $filtros['fecha_hasta'] = $hasta->format('Y-m-d');
        }

        return $filtros;
    }

    /**
     * Fecha ('Y-m-d') del movimiento publicado más reciente de
     * $user_id, de CUALQUIER billetera y cualquier categorización
     * (incluidos los sin categorizar y las Transferencias): a
     * diferencia de ultimo_periodo_con_datos(), que ancla un acumulado
     * real y por eso exige que cuente como ingreso o egreso, acá solo
     * se necesita "hasta dónde llega lo cargado" para ubicar la ventana
     * del filtro — un movimiento reciente sin categorizar tiene que
     * quedar adentro de ella, porque es justo el que alimenta el aviso
     * de sin categorizar.
     *
     * @return string|null null si todavía no tiene ningún movimiento.
     */
    private function fecha_ultimo_movimiento($user_id)
    {
        return $this->fecha_extrema_de_movimientos($user_id, 'DESC');
    }

    /**
     * Fecha ('Y-m-d') del movimiento publicado más antiguo ('ASC') o
     * más reciente ('DESC') de $user_id — la misma consulta para los
     * dos extremos, así fecha_ultimo_movimiento() y
     * años_con_movimientos() no repiten el get_posts().
     *
     * @return string|null null si todavía no tiene ningún movimiento.
     */
    private function fecha_extrema_de_movimientos($user_id, $orden)
    {
        $ids = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => $orden,
            'fields'         => 'ids',
        ]);

        if (empty($ids)) {
            return null;
        }

        return substr(get_post_field('post_date', $ids[0]), 0, 10);
    }

    /**
     * Opciones del <select> de Año del Comparativo: los años en los que
     * $user_id tiene al menos un movimiento publicado, de CUALQUIERA de
     * sus billeteras (el post_author de un movimiento es siempre el
     * dueño de su billetera, ver Libro::forzar_billetera_y_autor()).
     *
     * Sin SQL propio: WordPress no trae una función de "años
     * distintos" para un CPT con autor, y el proyecto no escribe SQL
     * directo. Se ubican el primer y el último año con dos consultas
     * de un solo resultado, y se confirma cada año del medio con otra
     * de un solo resultado (`date_query`, sobre `post_date` local, que
     * es la fecha que muestra y filtra todo el módulo) — del orden de
     * (cantidad de años + 2) consultas livianas, sin traer ningún
     * movimiento completo. Un año del medio sin movimientos no se
     * ofrece.
     *
     * Siempre se incluye $año_seleccionado, aunque no tenga
     * movimientos (por defecto, el año en curso): si no estuviera, el
     * <select> mostraría otra opción como elegida mientras el
     * Comparativo muestra el año de la URL.
     *
     * @return array<int,string> año => etiqueta, ascendente.
     */
    private function años_con_movimientos($user_id, $año_seleccionado)
    {
        $opciones = [];
        $primera  = $this->fecha_extrema_de_movimientos($user_id, 'ASC');
        $ultima   = $this->fecha_extrema_de_movimientos($user_id, 'DESC');

        if ($primera !== null && $ultima !== null) {
            for ($año = (int) substr($primera, 0, 4); $año <= (int) substr($ultima, 0, 4); $año++) {
                $hay_movimientos = get_posts([
                    'post_type'      => Libro::POST_TYPE,
                    'post_status'    => 'publish',
                    'author'         => $user_id,
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                    'fields'         => 'ids',
                    'date_query'     => [['year' => $año]],
                ]);

                if (!empty($hay_movimientos)) {
                    $opciones[$año] = (string) $año;
                }
            }
        }

        $opciones[$año_seleccionado] = (string) $año_seleccionado;
        ksort($opciones);

        return $opciones;
    }

    /**
     * TODOS los movimientos propios que matchean el filtro del panel,
     * con sus objetos COMPLETOS (no solo IDs, a diferencia de
     * LibroManagement::movimiento_ids_filtrados()) y sin paginar: este
     * panel no lista fila por fila, agrega todo de una sola vez para
     * armar sus gráficos, así que no hay "página" que tenga sentido
     * acá.
     *
     * Reusa construir_args_filtro() (ahora público) tal cual arma cada
     * criterio; solo agrega `posts_per_page => -1` y
     * `no_found_rows => true` encima — mismo patrón que ya usa
     * movimiento_ids_filtrados() para el mismo tipo de consulta sin
     * paginar.
     *
     * @return \WP_Post[]
     */
    private function movimientos_filtrados($filtros, $user_id)
    {
        $args = LibroManagement::get_instance()->construir_args_filtro($filtros, $user_id);

        $args['posts_per_page'] = -1;
        $args['no_found_rows']  = true;

        return get_posts($args);
    }

    /**
     * Recorre UNA sola vez los movimientos ya filtrados y los reparte
     * en las tres estructuras que necesitan las secciones de gráficos
     * — serie mensual de ingresos/egresos, Pareto de categorías, y el
     * conteo/monto neto de sin categorización — en vez de recorrer la
     * misma lista tres veces, una por sección.
     *
     * Las Transferencias se descartan directo, sin pasar a ninguna de
     * las tres: Edwin fue explícito en que no se puede confirmar que
     * NO sean transferencias entre cuentas propias, así que no
     * corresponde tratarlas como ingreso ni egreso real en ningún
     * gráfico — y a diferencia de "sin categorización", tampoco llevan
     * un aviso propio (nadie lo pidió para ellas).
     *
     * @return array{
     *   serie: array<int,array{ingreso?:array<string,float>, egreso?:array<string,float>}>,
     *   pareto: array<string,array<int,array<int,array{nombre:string,total:float}>>>,
     *   sin_categorizar: array{cantidad:int, monto_neto:float},
     * }
     */
    private function clasificar($movimientos)
    {
        $categoria    = Categoria::get_instance();
        $cache_moneda = [];

        $serie           = [];
        $pareto          = [];
        $sin_categorizar = ['cantidad' => 0, 'monto_neto' => 0.0];

        foreach ($movimientos as $movimiento) {
            $monto        = (float) get_post_meta($movimiento->ID, '_monto', true);
            $billetera_id = (int) $movimiento->post_parent;

            if (!isset($cache_moneda[$billetera_id])) {
                $cache_moneda[$billetera_id] = (int) get_post_meta($billetera_id, '_moneda', true);
            }
            $moneda = $cache_moneda[$billetera_id];

            $terminos = get_the_terms($movimiento->ID, Categoria::TAXONOMY);
            $term_id  = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

            if (!$term_id) {
                $sin_categorizar['cantidad']++;
                $sin_categorizar['monto_neto'] += $monto;
                continue;
            }

            $tipo = $categoria->tipo_de($term_id);
            if (!$tipo || $tipo->name === 'Transferencias') {
                continue;
            }

            $mes_key = get_the_date('Y-m', $movimiento);

            if ($tipo->name === 'Ingresos') {
                $serie[$moneda]['ingreso'][$mes_key] = ($serie[$moneda]['ingreso'][$mes_key] ?? 0.0) + $monto;
            } elseif ($tipo->name === 'Egresos y Gastos') {
                $serie[$moneda]['egreso'][$mes_key] = ($serie[$moneda]['egreso'][$mes_key] ?? 0.0) + $monto;
            } else {
                // Tipo raíz fuera de los tres de ARBOL_BASE: defensivo,
                // no debería pasar en la práctica — se descarta igual
                // que Transferencias en vez de romper el gráfico.
                continue;
            }

            $categoria_term   = $categoria->categoria_de($term_id);
            $categoria_id     = $categoria_term ? $categoria_term->term_id : $term_id;
            $categoria_nombre = $categoria_term ? $categoria_term->name : $tipo->name;

            if (!isset($pareto[$tipo->name][$moneda][$categoria_id])) {
                $pareto[$tipo->name][$moneda][$categoria_id] = [
                    'nombre' => $categoria_nombre,
                    'total'  => 0.0,
                ];
            }
            $pareto[$tipo->name][$moneda][$categoria_id]['total'] += abs($monto);
        }

        return [
            'serie'           => $serie,
            'pareto'          => $pareto,
            'sin_categorizar' => $sin_categorizar,
        ];
    }

    /**
     * Lista de meses (clave 'Y-m' => etiqueta 'AAAAMM', ej. "202609")
     * entre $fecha_desde y $fecha_hasta, ambos incluidos. WordPress no
     * resuelve esto de forma nativa (no hay un helper que itere meses
     * entre dos fechas — PRINCIPIO RECTOR: no hay nada que reusar acá,
     * así que corresponde código propio) — de ahí este recorrido con
     * DateTime nativo de PHP, anclado siempre al día 1 de cada mes
     * para que la comparación no dependa del día exacto de los
     * extremos del filtro.
     *
     * La etiqueta es 'AAAAMM' (Edwin lo pidió así para el eje X del
     * gráfico lineal, ver tablero.js) — a propósito NO el nombre de
     * mes traducido que usaba antes: 6 dígitos ocupan menos espacio en
     * el eje que "Septiembre 2026", así entran más meses sin
     * amontonarse.
     *
     * @return array<string,string> 'Y-m' => 'AAAAMM'
     */
    private function meses_del_rango($fecha_desde, $fecha_hasta)
    {
        $cursor = new \DateTime($fecha_desde);
        $cursor->modify('first day of this month');

        $fin = new \DateTime($fecha_hasta);
        $fin->modify('first day of this month');

        $meses = [];
        while ($cursor <= $fin) {
            $clave         = $cursor->format('Y-m');
            $meses[$clave] = $cursor->format('Ym');

            $cursor->modify('+1 month');
        }

        return $meses;
    }

    /**
     * Para cada moneda, los arrays alineados con $meses que necesita el
     * gráfico lineal: las etiquetas del eje X, los ingresos (siempre
     * positivos, tal cual) y los egresos ya en VALOR ABSOLUTO — Edwin
     * lo pidió así, para poder comparar visualmente contra los
     * ingresos como dos magnitudes positivas; el signo real solo
     * importa para el color (verde/rojo), no para la altura de la
     * línea.
     *
     * Solo arma entrada para las monedas de $monedas_disponibles (ver
     * monedas_con_billetera()) — si el usuario no tiene ninguna
     * billetera en moneda extranjera, este array ni siquiera trae esa
     * clave, y la vista no dibuja esa columna.
     *
     * @return array<int,array{etiqueta:string, meses:array<int,string>, ingresos:array<int,float>, egresos:array<int,float>}>
     */
    private function serie_mensual($serie, $meses, $monedas_disponibles)
    {
        $resultado = [];

        foreach ($monedas_disponibles as $moneda_id) {
            $ingresos = [];
            $egresos  = [];

            foreach ($meses as $clave => $etiqueta) {
                $ingresos[] = round($serie[$moneda_id]['ingreso'][$clave] ?? 0.0, 2);
                $egresos[]  = round(abs($serie[$moneda_id]['egreso'][$clave] ?? 0.0), 2);
            }

            $resultado[$moneda_id] = [
                'etiqueta' => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                'meses'    => array_values($meses),
                'ingresos' => $ingresos,
                'egresos'  => $egresos,
            ];
        }

        return $resultado;
    }

    /**
     * Arma los 4 Pareto finales (Ingresos/Egresos y Gastos × moneda
     * Local/Extranjera) a partir de lo que ya acumuló clasificar() —
     * ver armar_pareto() para el recorte al ~80%.
     *
     * Solo arma columna para las monedas de $monedas_disponibles (ver
     * monedas_con_billetera()) — mismo criterio que serie_mensual().
     *
     * @return array{ingresos:array<int,array>, egresos:array<int,array>}
     */
    private function paretos($pareto_bruto, $monedas_disponibles)
    {
        $tipos = [
            'ingresos' => 'Ingresos',
            'egresos'  => 'Egresos y Gastos',
        ];

        $resultado = [];

        foreach ($tipos as $clave => $tipo_nombre) {
            $resultado[$clave] = [];

            foreach ($monedas_disponibles as $moneda_id) {
                $resultado[$clave][$moneda_id] = [
                    'etiqueta'  => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                    'segmentos' => $this->armar_pareto($pareto_bruto[$tipo_nombre][$moneda_id] ?? []),
                ];
            }
        }

        return $resultado;
    }

    /**
     * Ordena $categorias (term_id => {nombre, total}) de mayor a menor
     * monto y las conserva individuales mientras el acumulado ANTES de
     * sumar cada una siga por debajo del 80% del total — la categoría
     * que hace cruzar ese umbral todavía entra individual (Edwin: "una
     * vez que tengamos alrededor del 80%"), y desde la siguiente en
     * adelante todo se agrupa bajo "Otros". Sin fila "Otros" si no
     * queda ningún resto (por ejemplo, una sola categoría con el
     * 100%).
     *
     * `es_otros` viaja explícito en cada fila (en vez de que
     * tablero.js tenga que reconocer el segmento "Otros" comparando su
     * nombre, que en un sitio traducido a otro idioma dejaría de ser
     * el literal 'Otros') — así el JS solo necesita mirar un booleano
     * para saber a qué segmento pintarle el color neutro (ver
     * tablero.js, COLOR_OTROS).
     *
     * @return array<int,array{nombre:string, total:float, es_otros:bool}>
     */
    private function armar_pareto($categorias)
    {
        if (empty($categorias)) {
            return [];
        }

        uasort($categorias, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $total_general = array_sum(array_column($categorias, 'total'));
        if ($total_general <= 0) {
            return [];
        }

        $resultado = [];
        $acumulado = 0.0;
        $otros     = 0.0;

        foreach ($categorias as $fila) {
            if (($acumulado / $total_general) >= 0.80) {
                $otros += $fila['total'];
                continue;
            }

            $resultado[] = [
                'nombre'   => $fila['nombre'],
                'total'    => round($fila['total'], 2),
                'es_otros' => false,
            ];
            $acumulado += $fila['total'];
        }

        if ($otros > 0) {
            $resultado[] = [
                'nombre'   => __('Otros', 'egc'),
                'total'    => round($otros, 2),
                'es_otros' => true,
            ];
        }

        return $resultado;
    }

    /**
     * Suma `_saldo` (siempre el ACTUAL, ya derivado — ver el docblock
     * de Libro::recalcular_saldo_de()) de las billeteras propias de
     * $user_id, separado por moneda. Ignora a propósito el rango de
     * fechas del filtro (Edwin lo confirmó: el saldo es el de HOY, no
     * el de un momento pasado, así que un filtro de fecha no tiene
     * nada que acotar acá) pero sí respeta $billetera_id_filtro cuando
     * se eligió una billetera puntual — misma validación de propiedad
     * que el resto del filtro (billetera_propia()).
     *
     * Solo devuelve entrada para las monedas de $monedas_disponibles
     * (ver monedas_con_billetera()) — mismo criterio que
     * serie_mensual() y paretos(). El total en sí se sigue calculando
     * igual que antes (recorre TODAS las billeteras propias que
     * matcheen $billetera_id_filtro); lo único que cambia es qué
     * monedas llegan al resultado final.
     *
     * @return array<int,array{etiqueta:string, total:float}>
     */
    private function saldo_por_moneda($user_id, $billetera_id_filtro, $monedas_disponibles)
    {
        $args = [
            'post_type'      => Billetera::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ];

        if ($billetera_id_filtro && LibroManagement::get_instance()->billetera_propia($billetera_id_filtro, $user_id)) {
            $args['include'] = [$billetera_id_filtro];
        }

        $billeteras = get_posts($args);

        $totales = [
            Billetera::MONEDA_LOCAL      => 0.0,
            Billetera::MONEDA_EXTRANJERA => 0.0,
        ];

        foreach ($billeteras as $billetera) {
            $moneda = (int) get_post_meta($billetera->ID, '_moneda', true);
            if (!isset($totales[$moneda])) {
                continue;
            }
            $totales[$moneda] += (float) get_post_meta($billetera->ID, '_saldo', true);
        }

        $resultado = [];
        foreach ($totales as $moneda_id => $total) {
            if (!in_array($moneda_id, $monedas_disponibles, true)) {
                continue;
            }

            $resultado[$moneda_id] = [
                'etiqueta' => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                'total'    => round($total, 2),
            ];
        }

        return $resultado;
    }

    /**
     * Compara, por moneda, lo REAL acumulado contra lo PRESUPUESTADO
     * (PresupuestoManagement::monedas_de(), ahora público — el mismo
     * cálculo que esa clase ya hace para su propio listado, reusado
     * tal cual: es exactamente lo que anticipaba el docblock de
     * Presupuesto.php como "el futuro Comparativo").
     *
     * A propósito NO hereda el filtro principal del panel (Edwin fue
     * explícito: "el comparativo no hereda el filtro") — usa TODAS las
     * billeteras propias del usuario sin importar qué billetera o
     * rango de fecha se haya elegido arriba, con su propio selector de
     * Año/Mes independiente (ver comparativo_real()).
     *
     * Fila por CATEGORÍA (nivel "categoría", ver
     * Categoria::categoria_de()), agrupadas por tipo — Edwin pidió
     * explícito bajar un nivel la granularidad de esta tabla, que
     * antes solo comparaba a nivel de tipo (Ingresos/Egresos y
     * Gastos). Cada tipo trae sus categorías más un subtotal
     * (subtotal_real/subtotal_presupuestado, con su propia variación),
     * y al final una única fila "Superávit(Déficit)" a nivel de TODA
     * la moneda (`diferencia_*` en el array, el nombre del campo no
     * cambió aunque la vista ya no la rotule "Diferencia") — mismo
     * esqueleto de tres niveles (categoría → subtotal de tipo →
     * superávit/déficit general) que ya usa
     * PresupuestoManagement::monedas_de() para su propio listado.
     *
     * `diferencia_real` sale de comparativo_real(), que la calcula
     * como SUMA de Ingresos + Egresos y Gastos (nunca resta): el
     * subtotal de Egresos y Gastos ya es negativo (`$monto` con
     * signo, ver Libro.php), así que sumarlo es lo que neta
     * correctamente — restarlo lo hubiera sumado dos veces (bug real
     * que encontró Edwin). `diferencia_presupuestado`, desde que
     * PresupuestoManagement::monedas_de() devuelve Egresos y Gastos en
     * negativo, usa la MISMA convención que el real: también es una
     * suma (Ingresos + Egresos y Gastos), y los dos lados se confrontan
     * sin conversiones.
     *
     * El REAL de una categoría se muestra siempre, tenga o no
     * presupuesto cargado (Edwin fue explícito: "debe mostrar la
     * información del real acumulado... aunque no haya presupuesto")
     * — una categoría entra a esta tabla si tiene movimientos reales O
     * presupuesto en el período, lo que haya, nunca solo cuando tiene
     * las dos cosas. El caso "sin presupuesto" solo afecta a la
     * columna Variación de esa fila puntual (ver variacion()), nunca
     * a si la fila se muestra.
     *
     * Categoría y subtotal de tipo comparten una única convención de
     * signo en los dos lados: positivo para Ingresos y negativo para
     * Egresos y Gastos (real: `$monto` crudo de comparativo_real();
     * presupuestado: lo que devuelve PresupuestoManagement::monedas_de(),
     * que aplica el signo según el tipo). Por eso la variación
     * (real − presupuestado) es positiva cuando se está mejor que lo
     * presupuestado — más ingreso, o menos egreso — en cualquier fila.
     *
     * @return array<int,array{
     *   etiqueta:string,
     *   tipos:array<int,array{
     *     nombre:string,
     *     categorias:array<int,array{nombre:string, real:float, presupuestado:float, variacion_absoluta:float, variacion_relativa:float}>,
     *     subtotal_real:float, subtotal_presupuestado:float,
     *     subtotal_variacion_absoluta:float, subtotal_variacion_relativa:float,
     *   }>,
     *   diferencia_real:float, diferencia_presupuestado:float,
     *   diferencia_variacion_absoluta:float, diferencia_variacion_relativa:float,
     * }>
     */
    private function comparativo($user_id, $año, $mes)
    {
        $real          = $this->comparativo_real($user_id, $año, $mes);
        $presupuestado = PresupuestoManagement::get_instance()->monedas_de($user_id, $año, $mes);
        $categoria     = Categoria::get_instance();

        $resultado = [];

        foreach ([Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA] as $moneda_id) {
            // Sin entrada en $real: comparativo_real() no encontró ni un
            // movimiento categorizado (no-Transferencia) de esta moneda
            // para $año/$mes — se omite la moneda entera del resultado
            // (título, waterfall y tabla juntos, ver la vista), en vez
            // de mostrarla vacía con el presupuesto solo. Edwin lo pidió
            // explícito para esta sección.
            if (!isset($real[$moneda_id])) {
                continue;
            }

            $reporte_real   = $real[$moneda_id];
            $reporte_presup = $presupuestado[$moneda_id] ?? ['grupos' => [], 'diferencia' => 0.0];

            // categoria_id => {nombre, real, presupuestado} — arranca
            // con lo real de cada categoría (siempre presente, aunque
            // no tenga presupuesto) y le suma encima lo presupuestado
            // que corresponda, si lo hay.
            $categorias = [];
            foreach ($reporte_real['categorias'] as $categoria_id => $fila) {
                $categorias[$categoria_id] = [
                    'nombre'        => $fila['nombre'],
                    'real'          => $fila['total'],
                    'presupuestado' => 0.0,
                ];
            }

            foreach ($reporte_presup['grupos'] as $grupo) {
                if (!in_array($grupo['nombre'], ['Ingresos', 'Egresos y Gastos'], true)) {
                    continue;
                }

                foreach ($grupo['filas'] as $fila) {
                    $categoria_term   = $categoria->categoria_de($fila['term_id']);
                    $categoria_id     = $categoria_term ? $categoria_term->term_id : $fila['term_id'];
                    $categoria_nombre = $categoria_term ? $categoria_term->name : $fila['categoria'];

                    if (!isset($categorias[$categoria_id])) {
                        $categorias[$categoria_id] = ['nombre' => $categoria_nombre, 'real' => 0.0, 'presupuestado' => 0.0];
                    }

                    $categorias[$categoria_id]['presupuestado'] += $fila['monto'];
                }
            }

            // Reparte cada categoría bajo su tipo (Ingresos/Egresos y
            // Gastos) — tipo_de() la resuelve sin importar si esa
            // categoría solo tiene real, solo presupuesto, o las dos
            // cosas.
            $tipos = [];
            foreach ($categorias as $categoria_id => $fila) {
                $tipo = $categoria->tipo_de($categoria_id);
                if (!$tipo || !in_array($tipo->name, ['Ingresos', 'Egresos y Gastos'], true)) {
                    continue;
                }

                if (!isset($tipos[$tipo->term_id])) {
                    $tipos[$tipo->term_id] = ['nombre' => $tipo->name, 'categorias' => []];
                }

                $real_cat          = round($fila['real'], 2);
                $presupuestado_cat = round($fila['presupuestado'], 2);
                $variacion_cat     = $this->variacion($real_cat, $presupuestado_cat);

                $tipos[$tipo->term_id]['categorias'][] = [
                    'nombre'             => $fila['nombre'],
                    'real'               => $real_cat,
                    'presupuestado'      => $presupuestado_cat,
                    'variacion_absoluta' => $variacion_cat['absoluta'],
                    'variacion_relativa' => $variacion_cat['relativa'],
                ];
            }

            uasort($tipos, function ($a, $b) use ($categoria) {
                return $categoria->prioridad_tipo($a['nombre']) <=> $categoria->prioridad_tipo($b['nombre']);
            });

            foreach ($tipos as &$tipo_fila) {
                // Alfabético dentro de cada tipo — mismo criterio que
                // ya usa monedas_de() para sus propias filas.
                $tipo_fila['categorias'] = wp_list_sort($tipo_fila['categorias'], 'nombre', 'ASC');

                $subtotal_real          = round(array_sum(array_column($tipo_fila['categorias'], 'real')), 2);
                $subtotal_presupuestado = round(array_sum(array_column($tipo_fila['categorias'], 'presupuestado')), 2);
                $subtotal_variacion     = $this->variacion($subtotal_real, $subtotal_presupuestado);

                $tipo_fila['subtotal_real']              = $subtotal_real;
                $tipo_fila['subtotal_presupuestado']      = $subtotal_presupuestado;
                $tipo_fila['subtotal_variacion_absoluta'] = $subtotal_variacion['absoluta'];
                $tipo_fila['subtotal_variacion_relativa'] = $subtotal_variacion['relativa'];
            }
            unset($tipo_fila);

            $diferencia_real          = round($reporte_real['diferencia'] ?? 0.0, 2);
            $diferencia_presupuestado = round($reporte_presup['diferencia'] ?? 0.0, 2);
            $diferencia_variacion     = $this->variacion_superavit($diferencia_real, $diferencia_presupuestado);

            $resultado[$moneda_id] = [
                'etiqueta'                      => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                'tipos'                         => array_values($tipos),
                'diferencia_real'               => $diferencia_real,
                'diferencia_presupuestado'      => $diferencia_presupuestado,
                'diferencia_variacion_absoluta' => $diferencia_variacion['absoluta'],
                'diferencia_variacion_relativa' => $diferencia_variacion['relativa'],
            ];
        }

        return $resultado;
    }

    /**
     * Variación de la fila final "Superávit(Déficit)" de una moneda
     * (módulo SGF, capa lógica). Misma fórmula que variacion() cuando
     * hay presupuesto neto: absoluta = real − presupuestado (los dos
     * ya son Ingresos − Egresos, ver comparativo()), relativa = esa
     * absoluta sobre el valor absoluto del presupuestado.
     *
     * Diferencia con variacion(), pedida por Edwin solo para esta fila:
     * si el presupuestado neto es 0 (no hay presupuesto, o ingresos y
     * gastos presupuestados se cancelan) la división es por cero y la
     * relativa se fija en 100.00 en vez de 0. La absoluta deja de
     * forzarse a 0: es real − 0, o sea el superávit/déficit real
     * completo. El signo de ese 100 sigue al de la absoluta (un déficit
     * real contra presupuesto 0 es −100.00), para que la columna no
     * muestre un monto negativo con un porcentaje positivo. Si el real
     * también es 0 no hay variación que firmar y queda 100.00.
     *
     * No se tocó variacion(): categorías y subtotales de tipo siguen
     * devolviendo 0 / 0 sin presupuesto, porque ahí "sin presupuesto"
     * sí es ausencia de dato y no un neto.
     *
     * @return array{absoluta:float, relativa:float}
     */
    private function variacion_superavit($real, $presupuestado)
    {
        if ($presupuestado == 0.0) {
            return [
                'absoluta' => round($real, 2),
                'relativa' => ($real < 0.0) ? -100.0 : 100.0,
            ];
        }

        return $this->variacion($real, $presupuestado);
    }

    /**
     * Variación absoluta ($real − $presupuestado) y relativa (esa
     * diferencia sobre el VALOR ABSOLUTO de $presupuestado, en %) entre
     * lo real y lo presupuestado de una misma fila del Comparativo. Con
     * Egresos y Gastos en negativo en los dos lados, el absoluto en el
     * denominador mantiene el signo de la variación igual al de la
     * absoluta (positivo = mejor que lo presupuestado): sin él, una
     * fila de egresos daría una absoluta positiva y una relativa
     * negativa.
     *
     * Caso especial pedido por Edwin: si $presupuestado es 0, las dos
     * se devuelven en 0 en vez de calcularse — nunca 0 en 0 (división
     * por cero) ni, tampoco, la variación absoluta real (que mostraría
     * el monto real completo como si fuera "100% de exceso" contra un
     * presupuesto que en realidad nunca se cargó). Este caso no es
     * ambiguo: Presupuesto::guardar_meta_box() nunca guarda un monto
     * <= 0 (ver su docblock, "un presupuesto negativo no tiene
     * sentido"), así que un $presupuestado en 0 significa siempre "sin
     * presupuesto cargado para esta fila", nunca "se presupuestó
     * literalmente cero".
     *
     * @return array{absoluta:float, relativa:float}
     */
    private function variacion($real, $presupuestado)
    {
        if ($presupuestado == 0.0) {
            return ['absoluta' => 0.0, 'relativa' => 0.0];
        }

        $absoluta = $real - $presupuestado;
        $relativa = ($absoluta / abs($presupuestado)) * 100;

        return [
            'absoluta' => round($absoluta, 2),
            'relativa' => round($relativa, 2),
        ];
    }

    /**
     * Lado REAL del Comparativo: movimientos propios de TODAS las
     * billeteras (sin filtro de billetera_id ni de rango de fecha del
     * panel — ver el docblock de comparativo()) entre el 1 de enero y
     * el último día de $mes de $año, agrupados por moneda → tipo, en
     * la misma forma que PresupuestoManagement::monedas_de() para
     * poder confrontar subtotal contra subtotal. Sin categorización y
     * Transferencias quedan afuera, mismo motivo que en el resto del
     * panel (ver clasificar()): no se puede saber si son ingreso o
     * egreso real.
     *
     * Además de 'grupos' (subtotal por tipo, lo único que necesita
     * comparativo()), arma 'categorias' (subtotal por categoría —
     * nivel "categoría", ver Categoria::categoria_de(), nunca por
     * subcategoría suelta) CON SIGNO (Ingresos positivo, Egresos y
     * Gastos negativo, el mismo `$monto` crudo sin abs()): lo necesita
     * variacion_por_categoria() para el waterfall del Requisito B —
     * hasta esa función, nadie pedía el detalle categoría por
     * categoría de este reporte, por eso antes no se armaba.
     *
     * @return array<int,array{etiqueta:string, grupos:array<int,array{nombre:string,subtotal:float}>, categorias:array<int,array{nombre:string,total:float}>, diferencia:float}>
     */
    private function comparativo_real($user_id, $año, $mes)
    {
        $fecha_desde = sprintf('%04d-01-01', $año);

        $fin_mes = new \DateTime(sprintf('%04d-%02d-01', $año, $mes));
        $fin_mes->modify('last day of this month');
        $fecha_hasta = $fin_mes->format('Y-m-d');

        $movimientos = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'date_query'     => [
                [
                    'after'     => $fecha_desde . ' 00:00:00',
                    'before'    => $fecha_hasta . ' 23:59:59',
                    'inclusive' => true,
                ],
            ],
        ]);

        $categoria    = Categoria::get_instance();
        $cache_moneda = [];
        $monedas      = [];

        foreach ($movimientos as $movimiento) {
            $terminos = get_the_terms($movimiento->ID, Categoria::TAXONOMY);
            $term_id  = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

            if (!$term_id) {
                continue;
            }

            $tipo = $categoria->tipo_de($term_id);
            if (!$tipo || $tipo->name === 'Transferencias') {
                continue;
            }

            $billetera_id = (int) $movimiento->post_parent;
            if (!isset($cache_moneda[$billetera_id])) {
                $cache_moneda[$billetera_id] = (int) get_post_meta($billetera_id, '_moneda', true);
            }
            $moneda = $cache_moneda[$billetera_id];
            $monto  = (float) get_post_meta($movimiento->ID, '_monto', true);

            if (!isset($monedas[$moneda])) {
                $monedas[$moneda] = [
                    'etiqueta'   => BilleteraManagement::get_instance()->moneda_label($moneda),
                    'grupos'     => [],
                    'categorias' => [],
                    'diferencia' => 0.0,
                ];
            }

            if (!isset($monedas[$moneda]['grupos'][$tipo->term_id])) {
                $monedas[$moneda]['grupos'][$tipo->term_id] = [
                    'nombre'   => $tipo->name,
                    'subtotal' => 0.0,
                ];
            }

            $monedas[$moneda]['grupos'][$tipo->term_id]['subtotal'] += $monto;

            $categoria_term   = $categoria->categoria_de($term_id);
            $categoria_id     = $categoria_term ? $categoria_term->term_id : $term_id;
            $categoria_nombre = $categoria_term ? $categoria_term->name : $tipo->name;

            if (!isset($monedas[$moneda]['categorias'][$categoria_id])) {
                $monedas[$moneda]['categorias'][$categoria_id] = [
                    'nombre' => $categoria_nombre,
                    'total'  => 0.0,
                ];
            }
            $monedas[$moneda]['categorias'][$categoria_id]['total'] += $monto;
        }

        foreach ($monedas as &$reporte) {
            $ingresos = 0.0;
            $egresos  = 0.0;

            foreach ($reporte['grupos'] as $grupo) {
                if ($grupo['nombre'] === 'Ingresos') {
                    $ingresos = $grupo['subtotal'];
                } elseif ($grupo['nombre'] === 'Egresos y Gastos') {
                    $egresos = $grupo['subtotal'];
                }
            }

            // SUMA, no resta: $egresos ya es negativo acá (es el
            // subtotal de `$monto` crudo de Egresos y Gastos, y
            // `_monto` se guarda con signo — ver Libro.php, "positivo
            // -> Ingreso, negativo -> Egreso"). Restar un número que
            // ya es negativo lo SUMA dos veces en vez de netearlo
            // (bug real que encontró Edwin: el Superávit/Déficit daba
            // inflado en vez del neto correcto apenas había algún
            // egreso). El 'diferencia' de
            // PresupuestoManagement::monedas_de() usa hoy la misma
            // suma: allí Egresos y Gastos también sale negativo.
            $reporte['diferencia'] = round($ingresos + $egresos, 2);
        }
        unset($reporte);

        return $monedas;
    }

    /**
     * Variación (real − presupuestado) por categoría, con Ingresos y
     * Egresos y Gastos ya MEZCLADOS en una sola lista — a diferencia
     * del resto del Tablero, que siempre los separa, el waterfall del
     * Requisito B lo pidió Edwin así explícitamente ("un solo
     * gráfico... el pareto de los aumentos y disminuciones", eligió
     * la opción combinada entre las que se le ofrecieron).
     *
     * Los dos lados vienen en la MISMA convención de signo que ya usa
     * comparativo_real()/comparativo() para la fila "Diferencia": un
     * Ingreso suma, un Egreso resta. `categorias` de comparativo_real()
     * ya viene así (`$monto` crudo, sin abs()), y lo presupuestado
     * (PresupuestoManagement::monedas_de()) también: Egresos y Gastos
     * sale negativo desde el propio reporte, así que acá alcanza con
     * restar cada `monto` tal cual. Así,
     * la suma de TODAS las variaciones de categoría coincide EXACTO
     * con (real_total − presupuestado_total) — el punto de llegada
     * del waterfall (ver waterfall_presupuesto()), sin ningún ajuste
     * de redondeo aparte para que la cascada "cierre".
     *
     * Agrupa por categoría (nivel "categoría", ver
     * Categoria::categoria_de()) usando el `term_id` que
     * monedas_de() expone en cada fila — necesario porque el término
     * asignado a un Presupuesto puede ser una subcategoría, y no
     * corresponde mostrarla suelta en el waterfall (mismo criterio
     * que ya aplica clasificar() para los 4 Pareto existentes).
     *
     * Transferencias queda afuera en los dos lados: comparativo_real()
     * ya las descarta, y monedas_de() nunca las agrupa bajo 'Ingresos'
     * ni 'Egresos y Gastos' (el filtro explícito de acá abajo evita
     * arrastrar un presupuesto cargado, por error, con una categoría
     * de Transferencias).
     *
     * `tiene_presupuesto` (`$reporte_presup['grupos']` no vacío, es
     * decir: existe al menos un Presupuesto cargado para esa moneda en
     * $año) es lo que usa waterfall_presupuesto() para no dibujar la
     * cascada cuando no hay NADA presupuestado — Edwin fue explícito
     * ("cuando el usuario no tenga presupuesto no se muestre el
     * waterfall"). Es distinto de "presupuestado_total == 0.0": ese
     * total puede dar 0 porque Ingresos presupuestado == Egresos
     * presupuestado (sí hay presupuesto cargado, casualmente neto
     * cero) — `tiene_presupuesto` no se confunde con ese caso.
     *
     * @return array<int,array{presupuestado_total:float, real_total:float, tiene_presupuesto:bool, categorias:array<int,array{nombre:string, variacion:float}>}>
     */
    private function variacion_por_categoria($user_id, $año, $mes)
    {
        $real          = $this->comparativo_real($user_id, $año, $mes);
        $presupuestado = PresupuestoManagement::get_instance()->monedas_de($user_id, $año, $mes);
        $categoria     = Categoria::get_instance();

        $resultado = [];

        foreach ([Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA] as $moneda_id) {
            // Misma omisión que comparativo(): sin movimientos
            // categorizados (no-Transferencia) para $año/$mes, la
            // moneda no entra en $resultado — waterfall_presupuesto()
            // la propaga, dejando afuera también su waterfall.
            if (!isset($real[$moneda_id])) {
                continue;
            }

            $reporte_real   = $real[$moneda_id];
            $reporte_presup = $presupuestado[$moneda_id] ?? ['grupos' => [], 'diferencia' => 0.0];

            $categorias = [];
            foreach ($reporte_real['categorias'] as $categoria_id => $fila) {
                $categorias[$categoria_id] = [
                    'nombre'    => $fila['nombre'],
                    'variacion' => $fila['total'],
                ];
            }

            foreach ($reporte_presup['grupos'] as $grupo) {
                if (!in_array($grupo['nombre'], ['Ingresos', 'Egresos y Gastos'], true)) {
                    continue;
                }

                foreach ($grupo['filas'] as $fila) {
                    $categoria_term   = $categoria->categoria_de($fila['term_id']);
                    $categoria_id     = $categoria_term ? $categoria_term->term_id : $fila['term_id'];
                    $categoria_nombre = $categoria_term ? $categoria_term->name : $fila['categoria'];

                    if (!isset($categorias[$categoria_id])) {
                        $categorias[$categoria_id] = ['nombre' => $categoria_nombre, 'variacion' => 0.0];
                    }

                    // `monto` ya viene con signo (Egresos y Gastos en
                    // negativo, ver PresupuestoManagement::monedas_de()).
                    $categorias[$categoria_id]['variacion'] -= $fila['monto'];
                }
            }

            $resultado[$moneda_id] = [
                'presupuestado_total' => round($reporte_presup['diferencia'] ?? 0.0, 2),
                'real_total'          => round($reporte_real['diferencia'] ?? 0.0, 2),
                'tiene_presupuesto'   => !empty($reporte_presup['grupos']),
                'categorias'          => $categorias,
            ];
        }

        return $resultado;
    }

    /**
     * Mismo recorte 80/20 que armar_pareto(), pero para datos CON
     * SIGNO (variación real − presupuestado por categoría: puede ser
     * un aumento, positivo, o una disminución, negativo) — no puede
     * reusar armar_pareto() tal cual, que asume totales siempre
     * positivos sumando al 100% de un total general. Acá el ranking y
     * el corte del 80% se hacen por MAGNITUD (valor absoluto), pero el
     * signo de cada categoría viaja intacto a $resultado, porque es lo
     * que decide su color en tablero.js (verde/rojo, ver
     * waterfall_presupuesto()).
     *
     * "Otros" suma el resto CON SIGNO (no en valor absoluto): si lo
     * que sobra es mayormente disminuciones, "Otros" queda negativo —
     * y viceversa — para que la cascada siga cerrando exacto en el
     * total Real, la misma propiedad que ya garantiza
     * variacion_por_categoria().
     *
     * Categorías sin variación (real == presupuestado, diferencia
     * 0.00) se descartan antes de rankear: una barra plana no aporta
     * nada al waterfall.
     *
     * @return array<int,array{nombre:string, variacion:float, es_otros:bool}>
     */
    private function armar_pareto_variacion($categorias)
    {
        $categorias = array_filter($categorias, function ($fila) {
            return round($fila['variacion'], 2) !== 0.0;
        });

        if (empty($categorias)) {
            return [];
        }

        uasort($categorias, function ($a, $b) {
            return abs($b['variacion']) <=> abs($a['variacion']);
        });

        $total_general = array_sum(array_map('abs', array_column($categorias, 'variacion')));
        if ($total_general <= 0) {
            return [];
        }

        $resultado = [];
        $acumulado = 0.0;
        $otros     = 0.0;

        foreach ($categorias as $fila) {
            if (($acumulado / $total_general) >= 0.80) {
                $otros += $fila['variacion'];
                continue;
            }

            $resultado[] = [
                'nombre'    => $fila['nombre'],
                'variacion' => round($fila['variacion'], 2),
                'es_otros'  => false,
            ];
            $acumulado += abs($fila['variacion']);
        }

        if (round($otros, 2) !== 0.0) {
            $resultado[] = [
                'nombre'    => __('Otros', 'egc'),
                'variacion' => round($otros, 2),
                'es_otros'  => true,
            ];
        }

        return $resultado;
    }

    /**
     * "Cascada" (bridge chart) de variación del presupuesto: arranca
     * en el total PRESUPUESTADO, atraviesa la variación de cada
     * categoría (ya mezcladas Ingresos y Egresos y Gastos, ver
     * variacion_por_categoria()) en el mismo orden que armó el 80/20
     * de armar_pareto_variacion(), y termina en el total REAL.
     *
     * Sentido Presupuestado → Real (antes iba del Real al
     * Presupuestado): cada variación es `real − presupuestado`, así que
     * al recorrer en este sentido se SUMA tal cual al cursor. Así la
     * dirección de cada barra coincide con su color — 'aumento'
     * (variación positiva, verde) SUBE, 'disminucion' (negativa, roja)
     * BAJA — y la cascada cierra exacto en el total Real. En el
     * sentido anterior (Real → Presupuestado) cada segmento se
     * restaba, y las barras verdes bajaban y las rojas subían (bug
     * real que reportó Edwin). Es el mismo esquema de
     * waterfall_interanual(). Cada elemento de 'barras' ya
     * trae 'desde'/'hasta' listos para que tablero.js dibuje una barra
     * flotante de Chart.js sin tener que acumular nada del lado del
     * cliente (SEPARACIÓN DE CAPAS: la cuenta es lógica, no
     * presentación).
     *
     * 'tipo' distingue color en tablero.js: 'presupuestado'/'real'
     * (barras ancla, color neutro) vs. 'aumento'/'disminucion' (verde/
     * rojo, según el signo de esa variación puntual — Edwin: "verde
     * para aumentos y rojo para disminuciones").
     *
     * Sin presupuesto cargado para una moneda (ver
     * `tiene_presupuesto` de variacion_por_categoria()), 'barras'
     * queda vacío para esa moneda — Edwin fue explícito: "cuando el
     * usuario no tenga presupuesto no se muestre el gráfico waterfall".
     * La vista (modules/sgf/views/tablero.php) esconde el `<canvas>`
     * únicamente cuando 'barras' viene VACÍO (`empty()`), así que un
     * array vacío alcanza para ocultarlo del todo, sin que la vista
     * necesite consultar 'tiene_presupuesto' por su cuenta — a
     * propósito NO se esconde solo porque 'barras' tenga los dos
     * anclajes nomás (Real == Presupuestado, ninguna categoría varió):
     * eso sigue siendo presupuesto real, solo que sin diferencia que
     * graficar, y es un caso distinto de "no hay presupuesto cargado"
     * (mismo criterio que corrigió el bug real de
     * waterfall_interanual(), ver su propio docblock).
     *
     * NUNCA combina las dos monedas entre sí (sumar montos de monedas
     * distintas no tiene sentido sin un tipo de cambio — tema que
     * Edwin dejó pendiente a propósito): un gráfico por moneda,
     * siempre las dos, sin filtrar por monedas_con_billetera() — igual
     * que el resto del Comparativo, esta sección no se ve afectada por
     * el Requisito A (Edwin: "esto aplica únicamente para los
     * gráficos, el presupuesto queda igual").
     *
     * @return array<int,array{
     *   etiqueta:string,
     *   barras:array<int,array{etiqueta:string, desde:float, hasta:float, tipo:string}>,
     * }>
     */
    private function waterfall_presupuesto($user_id, $año, $mes)
    {
        $variaciones = $this->variacion_por_categoria($user_id, $año, $mes);

        $resultado = [];

        foreach ([Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA] as $moneda_id) {
            // variacion_por_categoria() ya no trae esta moneda cuando
            // comparativo_real() no encontró movimientos categorizados
            // (no-Transferencia) para $año/$mes — se omite entera acá
            // también, en vez de caer al placeholder "sin presupuesto"
            // (que es un caso distinto: sí hay actividad real, pero
            // nada presupuestado).
            if (!isset($variaciones[$moneda_id])) {
                continue;
            }

            $reporte = $variaciones[$moneda_id];

            if (!$reporte['tiene_presupuesto']) {
                $resultado[$moneda_id] = [
                    'etiqueta' => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                    'barras'   => [],
                ];
                continue;
            }

            $segmentos = $this->armar_pareto_variacion($reporte['categorias']);

            $barras   = [];
            $barras[] = [
                'etiqueta' => __('Presupuestado', 'egc'),
                'desde'    => 0.0,
                'hasta'    => round($reporte['presupuestado_total'], 2),
                'tipo'     => 'presupuestado',
            ];

            $cursor = $reporte['presupuestado_total'];
            foreach ($segmentos as $segmento) {
                $siguiente = $cursor + $segmento['variacion'];

                $barras[] = [
                    'etiqueta' => $segmento['nombre'],
                    'desde'    => round($cursor, 2),
                    'hasta'    => round($siguiente, 2),
                    'tipo'     => $segmento['variacion'] >= 0 ? 'aumento' : 'disminucion',
                ];

                $cursor = $siguiente;
            }

            $barras[] = [
                'etiqueta' => __('Real', 'egc'),
                'desde'    => 0.0,
                'hasta'    => round($reporte['real_total'], 2),
                'tipo'     => 'real',
            ];

            $resultado[$moneda_id] = [
                'etiqueta' => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                'barras'   => $barras,
            ];
        }

        return $resultado;
    }

    /**
     * Variación (real $año_actual − real $año_anterior) por
     * categoría, acumulado Enero a $mes de cada año — mismo criterio
     * "combinado" (Ingresos y Egresos y Gastos ya mezclados en una
     * sola lista) que ya usa variacion_por_categoria() para el
     * waterfall real-vs-presupuesto (ver su docblock): ahí se combina
     * REAL contra PRESUPUESTADO, acá se combina el mismo REAL de dos
     * años distintos, así que aplica el mismo razonamiento sin
     * repetirlo.
     *
     * Reusa comparativo_real() tal cual para los dos lados — es
     * exactamente el mismo cálculo de acumulado (Enero 1 al último
     * día de $mes) que ya usa el Comparativo real-vs-presupuesto,
     * llamado dos veces con años distintos en vez de una vez con el
     * año/mes del selector de Presupuesto.
     *
     * `tiene_dato_anterior` (existe `$anterior[$moneda_id]`, es decir:
     * hubo al menos un movimiento que cuenta en $año_anterior para esa
     * moneda) es lo que usa waterfall_interanual() para no dibujar el
     * waterfall cuando no hay NADA del año anterior — Edwin fue
     * explícito ("en caso de que no se cuente con información del año
     * anterior esta sección no mostrará el gráfico pero sí el
     * título").
     *
     * $monedas_disponibles (Tablero::monedas_con_billetera()) acota
     * qué monedas se calculan acá — Edwin pidió explícito extender acá
     * el mismo análisis del Requisito A ("es necesario hacer el
     * análisis de que si el usuario cuenta con billeteras con las dos
     * monedas"): sin ninguna billetera en una moneda, ni su título ni
     * su gráfico tienen que aparecer en esta sección. Esto revierte lo
     * que decía antes el docblock de esta función (que el Comparativo
     * interanual quedaba afuera del Requisito A) — Edwin lo corrigió
     * explícito para esta sección en particular; waterfall_presupuesto()
     * sigue afuera, eso no cambió.
     *
     * @param array<int,int> $monedas_disponibles
     * @return array<int,array{actual_total:float, anterior_total:float, tiene_dato_anterior:bool, categorias:array<int,array{nombre:string, variacion:float}>}>
     */
    private function variacion_interanual($user_id, $año_actual, $año_anterior, $mes, $monedas_disponibles)
    {
        $actual   = $this->comparativo_real($user_id, $año_actual, $mes);
        $anterior = $this->comparativo_real($user_id, $año_anterior, $mes);

        $resultado = [];

        foreach ($monedas_disponibles as $moneda_id) {
            $reporte_actual   = $actual[$moneda_id] ?? ['categorias' => [], 'diferencia' => 0.0];
            $reporte_anterior = $anterior[$moneda_id] ?? null;

            $categorias = [];
            foreach ($reporte_actual['categorias'] as $categoria_id => $fila) {
                $categorias[$categoria_id] = [
                    'nombre'    => $fila['nombre'],
                    'variacion' => $fila['total'],
                ];
            }

            if ($reporte_anterior !== null) {
                foreach ($reporte_anterior['categorias'] as $categoria_id => $fila) {
                    if (!isset($categorias[$categoria_id])) {
                        $categorias[$categoria_id] = ['nombre' => $fila['nombre'], 'variacion' => 0.0];
                    }
                    $categorias[$categoria_id]['variacion'] -= $fila['total'];
                }
            }

            $resultado[$moneda_id] = [
                'actual_total'        => round($reporte_actual['diferencia'] ?? 0.0, 2),
                'anterior_total'      => round($reporte_anterior['diferencia'] ?? 0.0, 2),
                'tiene_dato_anterior' => $reporte_anterior !== null,
                'categorias'          => $categorias,
            ];
        }

        return $resultado;
    }

    /**
     * "Cascada" (bridge chart) del Comparativo interanual: arranca en
     * el total REAL acumulado de $año_anterior, atraviesa la
     * variación de cada categoría (ya mezcladas Ingresos y Egresos y
     * Gastos, ver variacion_interanual()) en el mismo orden que armó
     * el 80/20 de armar_pareto_variacion(), y termina en el total REAL
     * acumulado de $año_actual — mismo esquema (se suma la variación al
     * cursor) que el waterfall real-vs-presupuesto, que va de
     * Presupuestado a Real; acá el sentido cronológico (año anterior
     * primero, año actual después) es el que corresponde sin
     * ambigüedad.
     *
     * Sin ningún movimiento que cuente en $año_anterior para una
     * moneda (ver `tiene_dato_anterior` de variacion_interanual()),
     * 'barras' queda vacío para esa moneda — Edwin fue explícito:
     * "esta sección no mostrará el gráfico pero sí el título". La
     * vista (modules/sgf/views/tablero.php) esconde el `<canvas>`
     * únicamente cuando 'barras' viene VACÍO (`empty()`), no cuando
     * tiene solo los dos anclajes: cuando SÍ hay dato del año anterior
     * pero ninguna categoría varió (real de $año_actual idéntico al
     * de $año_anterior, categoría por categoría), armar_pareto_variacion()
     * devuelve el tramo de segmentos vacío y 'barras' se queda con
     * los dos anclajes nomás — eso sigue siendo información real
     * ("no hubo variación"), muy distinto de "no hay dato del año
     * anterior", así que no corresponde ocultarlo igual. (Este era el
     * bug real que reportó Edwin: "no se está mostrando el gráfico
     * para la moneda local que sí cuenta con información para ambos
     * años" — el chequeo anterior de la vista, `count($barras) > 2`,
     * trataba ese caso exactamente igual que "sin dato anterior" y lo
     * ocultaba también a él.)
     *
     * $monedas_disponibles la recibe tal cual de view_state() (mismo
     * Tablero::monedas_con_billetera() que ya filtra los gráficos del
     * Requisito A) y solo la reenvía a variacion_interanual(): sin
     * ninguna billetera en una moneda, esa moneda ni siquiera entra al
     * `foreach` de acá, así que $resultado no trae ninguna entrada
     * para ella — ni título ni gráfico en la vista, que se limita a
     * recorrer lo que $resultado le da.
     *
     * NUNCA combina las dos monedas entre sí, mismo motivo de siempre
     * (sin tipo de cambio no tiene sentido) — un gráfico por moneda.
     *
     * @param array<int,int> $monedas_disponibles
     * @return array<int,array{
     *   etiqueta:string,
     *   barras:array<int,array{etiqueta:string, desde:float, hasta:float, tipo:string}>,
     * }>
     */
    private function waterfall_interanual($user_id, $año_actual, $año_anterior, $mes, $monedas_disponibles)
    {
        $variaciones = $this->variacion_interanual($user_id, $año_actual, $año_anterior, $mes, $monedas_disponibles);

        $resultado = [];

        foreach ($monedas_disponibles as $moneda_id) {
            $reporte = $variaciones[$moneda_id] ?? [
                'actual_total'        => 0.0,
                'anterior_total'      => 0.0,
                'tiene_dato_anterior' => false,
                'categorias'          => [],
            ];

            if (!$reporte['tiene_dato_anterior']) {
                $resultado[$moneda_id] = [
                    'etiqueta' => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                    'barras'   => [],
                ];
                continue;
            }

            $segmentos = $this->armar_pareto_variacion($reporte['categorias']);

            $barras   = [];
            $barras[] = [
                'etiqueta' => (string) $año_anterior,
                'desde'    => 0.0,
                'hasta'    => round($reporte['anterior_total'], 2),
                'tipo'     => 'anterior',
            ];

            $cursor = $reporte['anterior_total'];
            foreach ($segmentos as $segmento) {
                $siguiente = $cursor + $segmento['variacion'];

                $barras[] = [
                    'etiqueta' => $segmento['nombre'],
                    'desde'    => round($cursor, 2),
                    'hasta'    => round($siguiente, 2),
                    'tipo'     => $segmento['variacion'] >= 0 ? 'aumento' : 'disminucion',
                ];

                $cursor = $siguiente;
            }

            $barras[] = [
                'etiqueta' => (string) $año_actual,
                'desde'    => 0.0,
                'hasta'    => round($reporte['actual_total'], 2),
                'tipo'     => 'actual',
            ];

            $resultado[$moneda_id] = [
                'etiqueta' => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                'barras'   => $barras,
            ];
        }

        return $resultado;
    }
}
