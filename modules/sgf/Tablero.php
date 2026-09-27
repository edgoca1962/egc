<?php

namespace EGC\Modules\Sgf;

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
 * Presupuesto (año_seleccionado/mes_seleccionado/año_opciones/
 * mes_opciones/monedas_de).
 *
 * Cinco de sus seis secciones dependen del filtro principal (el mismo
 * de Mantenimiento, con una única regla propia: sin ningún rango de
 * fecha puesto, se asume "últimos 12 meses" — ver filtros_panel()); la
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
     *   comparativo: array<int,array>,
     * }
     */
    public function view_state()
    {
        $user_id = get_current_user_id();
        $filtros = $this->filtros_panel();

        $movimientos  = $this->movimientos_filtrados($filtros, $user_id);
        $clasificados = $this->clasificar($movimientos);
        $meses        = $this->meses_del_rango($filtros['fecha_desde'], $filtros['fecha_hasta']);

        $año_comparativo = PresupuestoManagement::get_instance()->año_seleccionado();
        $mes_comparativo = PresupuestoManagement::get_instance()->mes_seleccionado($año_comparativo);

        return [
            'filtros'                   => $filtros,
            'billetera_opciones'        => LibroManagement::get_instance()->billetera_opciones_propias($user_id),
            'categoria_opciones_filtro' => LibroManagement::get_instance()->categoria_opciones_filtro($user_id),
            'saldo_por_moneda'          => $this->saldo_por_moneda($user_id, $filtros['billetera_id']),
            'serie_mensual'             => $this->serie_mensual($clasificados['serie'], $meses),
            'paretos'                   => $this->paretos($clasificados['pareto']),
            'sin_categorizar'           => [
                'cantidad'   => $clasificados['sin_categorizar']['cantidad'],
                'monto_neto' => round($clasificados['sin_categorizar']['monto_neto'], 2),
            ],
            'año_comparativo' => $año_comparativo,
            'año_opciones'    => PresupuestoManagement::get_instance()->año_opciones(),
            'mes_comparativo' => $mes_comparativo,
            'mes_opciones'    => PresupuestoManagement::get_instance()->mes_opciones(),
            'comparativo'     => $this->comparativo($user_id, $año_comparativo, $mes_comparativo),
        ];
    }

    /**
     * Misma normalización que Mantenimiento de movimientos
     * (LibroManagement::normalizar_filtros(), ahora público), más una
     * regla de default que solo tiene sentido ACÁ: sin ningún rango de
     * fecha puesto, se asume "últimos 12 meses" (Edwin lo confirmó
     * explícito) — un tablero sin ningún recorte de fecha no puede
     * quedar agregando TODA la historia de una, a diferencia de
     * Mantenimiento, donde "sin filtro" (ver todo, para categorizar en
     * bloque) sí es un estado de partida válido y buscado.
     *
     * @return array{billetera_id:int, fecha_desde:string, fecha_hasta:string, monto_desde:string, monto_hasta:string, categoria_filtro:string, texto:string}
     */
    private function filtros_panel()
    {
        $filtros = LibroManagement::get_instance()->normalizar_filtros($_GET);

        if ($filtros['fecha_desde'] === '' && $filtros['fecha_hasta'] === '') {
            $filtros['fecha_hasta'] = gmdate('Y-m-d');

            $desde = new \DateTime($filtros['fecha_hasta']);
            $desde->modify('-11 months');
            $desde->modify('first day of this month');
            $filtros['fecha_desde'] = $desde->format('Y-m-d');
        }

        return $filtros;
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
     * @return array<int,array{etiqueta:string, meses:array<int,string>, ingresos:array<int,float>, egresos:array<int,float>}>
     */
    private function serie_mensual($serie, $meses)
    {
        $resultado = [];

        foreach ([Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA] as $moneda_id) {
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
     * @return array{ingresos:array<int,array>, egresos:array<int,array>}
     */
    private function paretos($pareto_bruto)
    {
        $tipos = [
            'ingresos' => 'Ingresos',
            'egresos'  => 'Egresos y Gastos',
        ];

        $resultado = [];

        foreach ($tipos as $clave => $tipo_nombre) {
            foreach ([Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA] as $moneda_id) {
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
     * @return array<int,array{etiqueta:string, total:float}>
     */
    private function saldo_por_moneda($user_id, $billetera_id_filtro)
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
     * Cada fila (Ingresos, Egresos y Gastos, Diferencia) lleva además
     * su variación absoluta (real − presupuestado) y relativa (esa
     * diferencia sobre el presupuestado, en %) — ver variacion() para
     * el caso especial de "sin presupuesto cargado".
     *
     * @return array<int,array{
     *   etiqueta:string,
     *   ingresos_real:float, ingresos_presupuestado:float,
     *   ingresos_variacion_absoluta:float, ingresos_variacion_relativa:float,
     *   egresos_real:float, egresos_presupuestado:float,
     *   egresos_variacion_absoluta:float, egresos_variacion_relativa:float,
     *   diferencia_real:float, diferencia_presupuestado:float,
     *   diferencia_variacion_absoluta:float, diferencia_variacion_relativa:float,
     * }>
     */
    private function comparativo($user_id, $año, $mes)
    {
        $real          = $this->comparativo_real($user_id, $año, $mes);
        $presupuestado = PresupuestoManagement::get_instance()->monedas_de($user_id, $año, $mes);

        $resultado = [];

        foreach ([Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA] as $moneda_id) {
            $reporte_real   = $real[$moneda_id] ?? ['grupos' => [], 'diferencia' => 0.0];
            $reporte_presup = $presupuestado[$moneda_id] ?? ['grupos' => [], 'diferencia' => 0.0];

            $ingresos_real   = round($this->subtotal_de($reporte_real, 'Ingresos'), 2);
            $ingresos_presup = round($this->subtotal_de($reporte_presup, 'Ingresos'), 2);
            $egresos_real    = round($this->subtotal_de($reporte_real, 'Egresos y Gastos'), 2);
            $egresos_presup  = round($this->subtotal_de($reporte_presup, 'Egresos y Gastos'), 2);
            $diferencia_real   = round($reporte_real['diferencia'] ?? 0.0, 2);
            $diferencia_presup = round($reporte_presup['diferencia'] ?? 0.0, 2);

            $variacion_ingresos   = $this->variacion($ingresos_real, $ingresos_presup);
            $variacion_egresos    = $this->variacion($egresos_real, $egresos_presup);
            $variacion_diferencia = $this->variacion($diferencia_real, $diferencia_presup);

            $resultado[$moneda_id] = [
                'etiqueta'                     => BilleteraManagement::get_instance()->moneda_label($moneda_id),
                'ingresos_real'                => $ingresos_real,
                'ingresos_presupuestado'       => $ingresos_presup,
                'ingresos_variacion_absoluta'  => $variacion_ingresos['absoluta'],
                'ingresos_variacion_relativa'  => $variacion_ingresos['relativa'],
                'egresos_real'                 => $egresos_real,
                'egresos_presupuestado'        => $egresos_presup,
                'egresos_variacion_absoluta'   => $variacion_egresos['absoluta'],
                'egresos_variacion_relativa'   => $variacion_egresos['relativa'],
                'diferencia_real'              => $diferencia_real,
                'diferencia_presupuestado'     => $diferencia_presup,
                'diferencia_variacion_absoluta' => $variacion_diferencia['absoluta'],
                'diferencia_variacion_relativa' => $variacion_diferencia['relativa'],
            ];
        }

        return $resultado;
    }

    /**
     * Subtotal de $tipo_nombre ('Ingresos' o 'Egresos y Gastos') dentro
     * de un reporte por moneda ya armado (real o presupuestado, misma
     * forma en los dos) — 0.0 si ese tipo no tiene ninguna fila. Por
     * NOMBRE y no por term_id: aunque en la práctica el term_id de
     * "Ingresos" es el mismo en los dos lados (ambos derivan del árbol
     * de ESTE mismo usuario), comparar por nombre no depende de que
     * los dos reportes hayan quedado indexados igual.
     */
    private function subtotal_de($reporte_moneda, $tipo_nombre)
    {
        foreach ($reporte_moneda['grupos'] as $grupo) {
            if ($grupo['nombre'] === $tipo_nombre) {
                return $grupo['subtotal'];
            }
        }

        return 0.0;
    }

    /**
     * Variación absoluta ($real − $presupuestado) y relativa (esa
     * diferencia sobre $presupuestado, en %) entre lo real y lo
     * presupuestado de una misma fila del Comparativo.
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
        $relativa = ($absoluta / $presupuestado) * 100;

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
     * Sin 'filas' por categoría (a diferencia de monedas_de()): el
     * Comparativo solo necesita el subtotal por tipo para confrontarlo
     * contra lo presupuestado, no el detalle categoría por categoría
     * — no hace falta reconstruir acá algo que nadie va a mostrar.
     *
     * @return array<int,array{etiqueta:string, grupos:array<int,array{nombre:string,subtotal:float}>, diferencia:float}>
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

            $reporte['diferencia'] = round($ingresos - $egresos, 2);
        }
        unset($reporte);

        return $monedas;
    }
}
