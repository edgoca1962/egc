<?php

use EGC\Modules\Sgf\Tablero;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Vista del Tablero (panel de control) de SGF — todo lo que aparece
 * acá ya viene resuelto por Tablero::view_state(); esta vista no
 * consulta nada ni decide nada, solo pinta (SEPARACIÓN DE CAPAS).
 *
 * Dos `<form method="get">` separados a propósito (mismo motivo que ya
 * documenta libro-mantenimiento.php para sus dos `<form>`): el filtro
 * principal y el selector de Año/Mes del Comparativo son
 * independientes entre sí (Edwin: "el comparativo no hereda el
 * filtro"), pero viven en la misma URL — como un `<form method="get">`
 * reemplaza TODA la querystring por sus propios campos al enviarse,
 * cada uno lleva los valores actuales del OTRO como campos ocultos,
 * para que aplicar uno no resetee al otro.
 *
 * Los `<canvas>` de esta vista no dibujan nada por sí solos: el
 * cálculo (montos, meses, segmentos del Pareto) ya lo hizo
 * Tablero::view_state() del lado del servidor, y acá se imprime tal
 * cual dentro de un `<script type="application/json">` — es
 * tablero.js (JS de presentación, sin lógica de negocio) quien lee ese
 * bloque y dibuja con Chart.js. Ningún monto se calcula ni se suma en
 * el navegador.
 *
 * Cada `<canvas>` va envuelto en `.egc-tablero-chart` (clase propia,
 * definida en el SCSS del proyecto — no hay utilidad nativa de
 * Bootstrap para "alto fijo en píxeles"): Chart.js con
 * `maintainAspectRatio: false` (ver tablero.js) necesita que el
 * CONTENEDOR tenga una altura en CSS ya fijada de antemano, o entra en
 * un loop de resize (el canvas crece, eso agranda al padre, Chart.js
 * lo vuelve a agrandar) — por eso ya no lleva el atributo `height`
 * suelto en el propio `<canvas>`, que solo fija la resolución inicial
 * y no alcanza para contener el resize responsivo.
 *
 * Dos variables de conteo deciden, cada una en su propia sección, si se
 * centra una columna sola en vez de mostrar dos — ninguna de las dos
 * decide texto ni oculta nada por su cuenta: el ocultamiento ya lo
 * resolvió Tablero::view_state() del lado del servidor (una moneda
 * ausente de $state simplemente no imprime su `foreach`); acá solo se
 * decide CÓMO se acomoda lo que SÍ llegó.
 *
 * $monedas_disponibles_cantidad (= count($state['saldo_por_moneda']))
 * es la más laxa: Tablero::monedas_con_billetera() solo exige tener al
 * menos una billetera en esa moneda, billetera sin movimientos
 * incluida (Edwin: "si las hay y no tiene movimiento, se debe mostrar
 * cero") — gobierna únicamente el Saldo por moneda.
 *
 * $monedas_con_graficos_cantidad (= count($state['serie_mensual']), que
 * trae las mismas claves que $state['paretos']['ingresos'/'egresos']:
 * las tres vienen de Tablero::view_state() filtradas por el mismo
 * $monedas_con_graficos) es más estricta —
 * Tablero::moneda_tiene_actividad_categorizada() exige además al menos
 * un movimiento categorizado (no-Transferencia) — y gobierna la Serie
 * mensual y los 2 Pareto: `mx-auto` para centrar los pie (Pareto, que
 * mantienen su tamaño propio), `col-12` + el modificador
 * `.egc-tablero-chart--ancho` para que la línea (Serie mensual) ocupe
 * todo el ancho disponible cuando la otra moneda está oculta.
 *
 * El Comparativo real vs. presupuestado no usa ninguna de las dos: su
 * propio ocultamiento por moneda ya viene resuelto en
 * Tablero::comparativo_real() para el Año/Mes puntual de su selector
 * (ver el docblock de Tablero::comparativo()) — su `foreach` más abajo
 * ya no imprime una moneda sin datos, sin necesitar ningún conteo acá.
 *
 * El waterfall del Requisito B ($state['waterfall_presupuesto'], un
 * canvas por moneda) se imprime DENTRO del mismo `foreach` que ya
 * arma la tabla de cada moneda, justo antes de ella — así comparte su
 * `$moneda_id` sin tener que recorrer el Comparativo dos veces.
 *
 * El Comparativo interanual (real acumulado de este año vs. el mismo
 * acumulado del año anterior) es su propia sección, ANTES de la
 * tarjeta "Comparativo: real vs. presupuestado" — con su propio
 * `<form>` (un solo <select>, de mes; el año no se elige, ver el
 * docblock de Tablero::mes_interanual_seleccionado()) que también
 * preserva como ocultos los valores del filtro principal y del
 * selector Año/Mes del otro Comparativo, mismo criterio de arriba.
 * Sin `$state['interanual_año_actual']` (ningún movimiento que cuente
 * en todo el historial) esta sección solo imprime su título, ni
 * siquiera el <select> — no hay ningún mes entre el cual elegir.
 *
 * A diferencia del Comparativo real vs. presupuestado, el Comparativo
 * interanual SÍ queda adentro de la misma regla que la Serie mensual y
 * los 2 Pareto: `$state['interanual_waterfall']` ya viene de
 * Tablero::waterfall_interanual() filtrado por $monedas_con_graficos
 * (ver Tablero::view_state()), así que el `foreach` de más abajo solo
 * recorre las monedas con actividad categorizada real — sin ella, ni
 * su título ni su gráfico aparecen acá (Edwin lo pidió explícito para
 * esta sección en particular).
 */
$manager = Tablero::get_instance();
$state   = $manager->view_state();
$filtros = $state['filtros'];

$datos_grafico = [
    'serie_mensual'          => $state['serie_mensual'],
    'paretos'                => $state['paretos'],
    'waterfall_presupuesto'  => $state['waterfall_presupuesto'],
    // OJO: la clave de $state es 'interanual_waterfall' (ver
    // Tablero::view_state()), no 'waterfall_interanual' — ese
    // desajuste de nombre era el bug real de esta sección: $state['waterfall_interanual']
    // no existe, así que acá quedaba `null`, tablero.js lo recibía
    // como `datos.waterfall_interanual === null` y su guard
    // `if (!datos[datosClave]) { return; }` cortaba en silencio, sin
    // ningún error en consola — el <canvas> quedaba en el DOM (el
    // título y el "if" de 'barras' de más abajo SÍ usan la clave
    // correcta de $state) pero nada intentaba dibujar adentro. La
    // clave del lado JS ('waterfall_interanual', la que arma este
    // array) no tiene por qué coincidir con la de $state; lo que
    // tenía que coincidir era el valor, y no coincidía.
    'waterfall_interanual'   => $state['interanual_waterfall'],
];

$monedas_disponibles_cantidad  = count($state['saldo_por_moneda']);
$monedas_con_graficos_cantidad = count($state['serie_mensual']);
?>
<div class="container py-5">
    <h1 class="h3 mb-4"><?php esc_html_e('Tablero', 'egc'); ?></h1>

    <?php
    /**
     * "Primeros pasos" (Tablero::primeros_pasos()): banner arriba de
     * todo, cuando corresponde — Edwin fue explícito en que el resto
     * de las secciones se sigue mostrando debajo tal cual (vacías o
     * con lo poco que haya), este aviso no las reemplaza. Un solo
     * bloque para los tres casos: cambia la leyenda y el destino del
     * enlace, no la estructura.
     */
    ?>
    <?php if ($state['primeros_pasos']) : ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <span><?php echo esc_html($state['primeros_pasos']['leyenda']); ?></span>
            <a class="btn btn-primary btn-sm text-nowrap" href="<?php echo esc_url($state['primeros_pasos']['url']); ?>">
                <?php echo esc_html($state['primeros_pasos']['boton']); ?>
            </a>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <?php
                // Campos compartidos con Mantenimiento y el detalle de
                // billetera (ver el docblock del partial).
                $billetera_opciones        = $state['billetera_opciones'];
                $categoria_opciones_filtro = $state['categoria_opciones_filtro'];
                include EGC_DIR . '/modules/sgf/views/partials/movimientos-filtro-campos.php';
                ?>

                <?php
                // anio/mes/mes_interanual ocultos: preservan la
                // selección de los dos Comparativo (ver el docblock de
                // esta vista) al aplicar este filtro.
                ?>
                <input type="hidden" name="anio" value="<?php echo esc_attr($state['año_comparativo']); ?>">
                <input type="hidden" name="mes" value="<?php echo esc_attr($state['mes_comparativo']); ?>">
                <?php if ($state['interanual_mes_seleccionado'] !== null) : ?>
                    <input type="hidden" name="mes_interanual" value="<?php echo esc_attr($state['interanual_mes_seleccionado']); ?>">
                <?php endif; ?>

                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        <?php esc_html_e('Filtrar', 'egc'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($state['sin_categorizar']['cantidad'] > 0) : ?>
        <?php
        /**
         * Aviso pedido explícito por Edwin: cuántos movimientos del
         * filtro actual no tienen categorización y su monto neto —
         * esos movimientos NO están incluidos en ninguno de los
         * gráficos de abajo (ver Tablero::clasificar()), así que el
         * aviso es la única forma de que no queden invisibles del
         * todo.
         */
        ?>
        <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <span>
                <?php
                printf(
                    /* translators: %d: cantidad de movimientos sin categorización que coinciden con el filtro actual */
                    esc_html__('%d movimientos del filtro actual no tienen categorización — no se incluyen en los gráficos de abajo.', 'egc'),
                    (int) $state['sin_categorizar']['cantidad']
                );
                ?>
            </span>
            <span class="fw-semibold <?php echo $state['sin_categorizar']['monto_neto'] < 0 ? 'text-danger' : 'text-success'; ?>">
                <?php
                printf(
                    /* translators: %s: monto neto ya formateado de esos movimientos sin categorización */
                    esc_html__('Monto neto: %s', 'egc'),
                    esc_html(number_format_i18n($state['sin_categorizar']['monto_neto'], 2))
                );
                ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <?php foreach ($state['saldo_por_moneda'] as $saldo) : ?>
            <div class="col-md-6<?php echo $monedas_disponibles_cantidad === 1 ? ' mx-auto' : ''; ?>">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-muted"><?php echo esc_html($saldo['etiqueta']); ?></h2>
                        <p class="fs-3 mb-0 <?php echo $saldo['total'] < 0 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo esc_html(number_format_i18n($saldo['total'], 2)); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <?php foreach ($state['serie_mensual'] as $moneda_id => $serie) : ?>
            <div class="<?php echo $monedas_con_graficos_cantidad === 1 ? 'col-12' : 'col-lg-6'; ?>">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6">
                            <?php
                            printf(
                                /* translators: %s: etiqueta de la moneda (Moneda Local o Moneda Extranjera) */
                                esc_html__('Ingresos vs. Egresos y Gastos — %s', 'egc'),
                                esc_html($serie['etiqueta'])
                            );
                            ?>
                        </h2>
                        <div class="egc-tablero-chart<?php echo $monedas_con_graficos_cantidad === 1 ? ' egc-tablero-chart--ancho' : ''; ?>">
                            <canvas id="egc-tablero-linea-<?php echo esc_attr($moneda_id); ?>"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-2">
        <div class="col-12">
            <h2 class="h5"><?php esc_html_e('Pareto de Ingresos', 'egc'); ?></h2>
        </div>
        <?php foreach ($state['paretos']['ingresos'] as $moneda_id => $pareto) : ?>
            <div class="col-lg-6<?php echo $monedas_con_graficos_cantidad === 1 ? ' mx-auto' : ''; ?>">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted"><?php echo esc_html($pareto['etiqueta']); ?></h3>
                        <?php if (empty($pareto['segmentos'])) : ?>
                            <p class="text-muted mb-0"><?php esc_html_e('Sin datos para este filtro.', 'egc'); ?></p>
                        <?php else : ?>
                            <div class="egc-tablero-chart">
                                <canvas id="egc-tablero-pareto-ingresos-<?php echo esc_attr($moneda_id); ?>"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12">
            <h2 class="h5"><?php esc_html_e('Pareto de Egresos y Gastos', 'egc'); ?></h2>
        </div>
        <?php foreach ($state['paretos']['egresos'] as $moneda_id => $pareto) : ?>
            <div class="col-lg-6<?php echo $monedas_con_graficos_cantidad === 1 ? ' mx-auto' : ''; ?>">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted"><?php echo esc_html($pareto['etiqueta']); ?></h3>
                        <?php if (empty($pareto['segmentos'])) : ?>
                            <p class="text-muted mb-0"><?php esc_html_e('Sin datos para este filtro.', 'egc'); ?></p>
                        <?php else : ?>
                            <div class="egc-tablero-chart">
                                <canvas id="egc-tablero-pareto-egresos-<?php echo esc_attr($moneda_id); ?>"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3"><?php esc_html_e('Comparativo interanual — acumulado real', 'egc'); ?></h2>

            <?php if ($state['interanual_año_actual'] === null) : ?>
                <?php
                /**
                 * Edwin fue explícito sobre este caso puntual: sin
                 * ningún movimiento que cuente en todo el historial
                 * (Tablero::ultimo_periodo_con_datos() no encontró
                 * nada), esta sección se queda solo con el título —
                 * ni <select> (no hay ningún mes entre el que elegir)
                 * ni gráfico.
                 */
                ?>
                <p class="text-muted mb-0"><?php esc_html_e('Todavía no hay movimientos para comparar.', 'egc'); ?></p>
            <?php else : ?>
                <form method="get" class="row g-3 align-items-end mb-3">
                    <div class="col-sm-5 col-lg-3">
                        <label class="form-label" for="mes_interanual"><?php esc_html_e('Acumulado hasta', 'egc'); ?></label>
                        <select class="form-select" id="mes_interanual" name="mes_interanual">
                            <?php foreach ($state['interanual_mes_opciones'] as $mes_valor => $mes_etiqueta) : ?>
                                <option value="<?php echo esc_attr($mes_valor); ?>"
                                    <?php selected($state['interanual_mes_seleccionado'], $mes_valor); ?>>
                                    <?php echo esc_html($mes_etiqueta); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php
                    // El filtro principal y el selector del otro
                    // Comparativo viajan ocultos, mismo motivo que el
                    // resto de esta vista (ver su docblock).
                    ?>
                    <input type="hidden" name="billetera_id" value="<?php echo esc_attr($filtros['billetera_id']); ?>">
                    <input type="hidden" name="fecha_desde" value="<?php echo esc_attr($filtros['fecha_desde']); ?>">
                    <input type="hidden" name="fecha_hasta" value="<?php echo esc_attr($filtros['fecha_hasta']); ?>">
                    <input type="hidden" name="monto_desde" value="<?php echo esc_attr($filtros['monto_desde']); ?>">
                    <input type="hidden" name="monto_hasta" value="<?php echo esc_attr($filtros['monto_hasta']); ?>">
                    <input type="hidden" name="categoria_filtro" value="<?php echo esc_attr($filtros['categoria_filtro']); ?>">
                    <input type="hidden" name="texto" value="<?php echo esc_attr($filtros['texto']); ?>">
                    <input type="hidden" name="anio" value="<?php echo esc_attr($state['año_comparativo']); ?>">
                    <input type="hidden" name="mes" value="<?php echo esc_attr($state['mes_comparativo']); ?>">

                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="bi bi-funnel" aria-hidden="true"></i>
                            <?php esc_html_e('Actualizar', 'egc'); ?>
                        </button>
                    </div>
                </form>

                <p class="text-muted">
                    <?php
                    printf(
                        /* translators: 1: mes hasta el que se acumula, 2: año anterior, 3: año actual */
                        esc_html__('Acumulado de enero a %1$s: %2$d vs. %3$d.', 'egc'),
                        esc_html($state['interanual_mes_opciones'][$state['interanual_mes_seleccionado']] ?? ''),
                        (int) $state['interanual_año_anterior'],
                        (int) $state['interanual_año_actual']
                    );
                    ?>
                </p>

                <?php foreach ($state['interanual_waterfall'] as $moneda_id => $reporte) : ?>
                    <h3 class="h6 text-muted"><?php echo esc_html($reporte['etiqueta']); ?></h3>
                    <?php if (!empty($reporte['barras'])) : ?>
                        <div class="egc-tablero-chart mb-4">
                            <canvas id="egc-tablero-waterfall-interanual-<?php echo esc_attr($moneda_id); ?>"></canvas>
                        </div>
                    <?php else : ?>
                        <p class="text-muted mb-4"><?php esc_html_e('Sin movimientos del año anterior para comparar.', 'egc'); ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3"><?php esc_html_e('Comparativo: real vs. presupuestado', 'egc'); ?></h2>

            <form method="get" class="row g-3 align-items-end mb-4">
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="anio"><?php esc_html_e('Año', 'egc'); ?></label>
                    <select class="form-select" id="anio" name="anio">
                        <?php foreach ($state['año_opciones'] as $año_valor => $año_etiqueta) : ?>
                            <option value="<?php echo esc_attr($año_valor); ?>"
                                <?php selected($state['año_comparativo'], $año_valor); ?>>
                                <?php echo esc_html($año_etiqueta); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="mes"><?php esc_html_e('Acumulado hasta', 'egc'); ?></label>
                    <select class="form-select" id="mes" name="mes">
                        <?php foreach ($state['mes_opciones'] as $mes_valor => $mes_etiqueta) : ?>
                            <option value="<?php echo esc_attr($mes_valor); ?>"
                                <?php selected($state['mes_comparativo'], $mes_valor); ?>>
                                <?php echo esc_html($mes_etiqueta); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php
                // El filtro principal viaja oculto por el mismo motivo,
                // a la inversa (ver el docblock de esta vista): este
                // form (GET) también reemplaza toda la querystring al
                // enviarse.
                ?>
                <input type="hidden" name="billetera_id" value="<?php echo esc_attr($filtros['billetera_id']); ?>">
                <input type="hidden" name="fecha_desde" value="<?php echo esc_attr($filtros['fecha_desde']); ?>">
                <input type="hidden" name="fecha_hasta" value="<?php echo esc_attr($filtros['fecha_hasta']); ?>">
                <input type="hidden" name="monto_desde" value="<?php echo esc_attr($filtros['monto_desde']); ?>">
                <input type="hidden" name="monto_hasta" value="<?php echo esc_attr($filtros['monto_hasta']); ?>">
                <input type="hidden" name="categoria_filtro" value="<?php echo esc_attr($filtros['categoria_filtro']); ?>">
                <input type="hidden" name="texto" value="<?php echo esc_attr($filtros['texto']); ?>">
                <?php if ($state['interanual_mes_seleccionado'] !== null) : ?>
                    <input type="hidden" name="mes_interanual" value="<?php echo esc_attr($state['interanual_mes_seleccionado']); ?>">
                <?php endif; ?>

                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        <?php esc_html_e('Actualizar', 'egc'); ?>
                    </button>
                </div>
            </form>

            <?php foreach ($state['comparativo'] as $moneda_id => $fila) : ?>
                <h3 class="h6 text-muted"><?php echo esc_html($fila['etiqueta']); ?></h3>

                <?php
                /**
                 * Waterfall (Requisito B) — ANTES de la tabla numérica,
                 * tal como pidió Edwin: arranca en Real, pasa por la
                 * variación de cada categoría (Ingresos y Egresos y
                 * Gastos ya combinados, ver
                 * Tablero::variacion_por_categoria()) y termina en
                 * Presupuestado. Un gráfico por moneda, nunca las dos
                 * montos mezclados entre sí (ver el docblock de
                 * Tablero::waterfall_presupuesto()) — esta sección no
                 * se ve afectada por el Requisito A: sigue mostrando
                 * las dos monedas siempre, aunque el usuario no tenga
                 * billetera en una de ellas.
                 *
                 * Sin presupuesto cargado para esta moneda,
                 * waterfall_presupuesto() ya devuelve 'barras' vacío
                 * (Edwin: "cuando el usuario no tenga presupuesto no
                 * se muestre el gráfico waterfall") — alcanza con
                 * chequear que 'barras' no esté vacío, sin que la
                 * vista tenga que mirar tiene_presupuesto por su
                 * cuenta. A propósito NO se exige más de los dos
                 * anclajes: Real == Presupuestado sin ninguna
                 * categoría que varió también es presupuesto real
                 * cargado, solo que sin diferencia que graficar (ver
                 * el docblock de Tablero::waterfall_presupuesto()).
                 */
                $waterfall = $state['waterfall_presupuesto'][$moneda_id] ?? ['barras' => []];
                ?>
                <?php if (!empty($waterfall['barras'])) : ?>
                    <div class="egc-tablero-chart mb-4">
                        <canvas id="egc-tablero-waterfall-<?php echo esc_attr($moneda_id); ?>"></canvas>
                    </div>
                <?php endif; ?>

                <?php
                /**
                 * Tabla numérica — fila por CATEGORÍA (nivel
                 * "categoría", Requisito 3), agrupadas por tipo con un
                 * subtotal cada una: $fila['tipos'] ya viene en ese
                 * orden y ya trae el Real siempre presente, aunque esa
                 * categoría no tenga presupuesto (ver el docblock de
                 * Tablero::comparativo()) — esta vista solo pinta,
                 * ninguna cuenta se hace acá.
                 *
                 * Los colores de Real/Presupuestado siguen el tipo de
                 * la fila (verde en Ingresos, rojo en Egresos y
                 * Gastos), igual que antes cuando la tabla mostraba
                 * una sola fila por tipo — ahora esa misma regla se
                 * aplica a cada categoría y a su subtotal. Los de
                 * Variación siguen el signo de esa fila puntual, sin
                 * relación con el tipo — mismo criterio que ya usaba
                 * la fila del pie, ahora rotulada "Superávit(Déficit)"
                 * en vez de "Diferencia" (Edwin lo pidió explícito) —
                 * el monto de esa fila sale de
                 * Tablero::comparativo_real(), que lo suma (Ingresos +
                 * Egresos y Gastos, nunca resta: Egresos y Gastos ya
                 * es negativo) para netear bien los dos lados.
                 */
                ?>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th scope="col"></th>
                                <th scope="col" class="text-end"><?php esc_html_e('Real', 'egc'); ?></th>
                                <th scope="col" class="text-end"><?php esc_html_e('Presupuestado', 'egc'); ?></th>
                                <th scope="col" class="text-end"><?php esc_html_e('Variación absoluta', 'egc'); ?></th>
                                <th scope="col" class="text-end"><?php esc_html_e('Variación relativa', 'egc'); ?></th>
                            </tr>
                        </thead>
                        <?php foreach ($fila['tipos'] as $tipo_fila) : ?>
                            <?php $color_tipo = $tipo_fila['nombre'] === 'Ingresos' ? 'text-success' : 'text-danger'; ?>
                            <tbody>
                                <tr class="table-light">
                                    <th scope="rowgroup" colspan="5"><?php echo esc_html($tipo_fila['nombre']); ?></th>
                                </tr>
                                <?php foreach ($tipo_fila['categorias'] as $categoria_fila) : ?>
                                    <tr>
                                        <td class="ps-4"><?php echo esc_html($categoria_fila['nombre']); ?></td>
                                        <td class="text-end <?php echo esc_attr($color_tipo); ?>"><?php echo esc_html(number_format_i18n($categoria_fila['real'], 2)); ?></td>
                                        <td class="text-end <?php echo esc_attr($color_tipo); ?>"><?php echo esc_html(number_format_i18n($categoria_fila['presupuestado'], 2)); ?></td>
                                        <td class="text-end <?php echo $categoria_fila['variacion_absoluta'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo esc_html(number_format_i18n($categoria_fila['variacion_absoluta'], 2)); ?>
                                        </td>
                                        <td class="text-end <?php echo $categoria_fila['variacion_relativa'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo esc_html(number_format_i18n($categoria_fila['variacion_relativa'], 2)); ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="fw-semibold table-group-divider">
                                    <td>
                                        <?php
                                        printf(
                                            /* translators: %s: nombre del tipo (Ingresos o Egresos y Gastos) */
                                            esc_html__('Subtotal %s', 'egc'),
                                            esc_html($tipo_fila['nombre'])
                                        );
                                        ?>
                                    </td>
                                    <td class="text-end <?php echo esc_attr($color_tipo); ?>"><?php echo esc_html(number_format_i18n($tipo_fila['subtotal_real'], 2)); ?></td>
                                    <td class="text-end <?php echo esc_attr($color_tipo); ?>"><?php echo esc_html(number_format_i18n($tipo_fila['subtotal_presupuestado'], 2)); ?></td>
                                    <td class="text-end <?php echo $tipo_fila['subtotal_variacion_absoluta'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo esc_html(number_format_i18n($tipo_fila['subtotal_variacion_absoluta'], 2)); ?>
                                    </td>
                                    <td class="text-end <?php echo $tipo_fila['subtotal_variacion_relativa'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo esc_html(number_format_i18n($tipo_fila['subtotal_variacion_relativa'], 2)); ?>%
                                    </td>
                                </tr>
                            </tbody>
                        <?php endforeach; ?>
                        <tfoot class="table-group-divider">
                            <tr class="fw-semibold">
                                <td><?php esc_html_e('Superávit(Déficit)', 'egc'); ?></td>
                                <td class="text-end <?php echo $fila['diferencia_real'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['diferencia_real'], 2)); ?>
                                </td>
                                <td class="text-end <?php echo $fila['diferencia_presupuestado'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['diferencia_presupuestado'], 2)); ?>
                                </td>
                                <td class="text-end <?php echo $fila['diferencia_variacion_absoluta'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['diferencia_variacion_absoluta'], 2)); ?>
                                </td>
                                <td class="text-end <?php echo $fila['diferencia_variacion_relativa'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['diferencia_variacion_relativa'], 2)); ?>%
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
/**
 * Bloque de datos para tablero.js — JSON_HEX_TAG/JSON_HEX_AMP/etc.
 * para que ningún nombre de categoría con caracteres especiales pueda
 * cerrar este `<script>` antes de tiempo. `type="application/json"`
 * (no `text/javascript`): el navegador nunca lo ejecuta como código,
 * solo lo deja disponible para que tablero.js lo lea con
 * JSON.parse(elemento.textContent) — mismo criterio que separa
 * SIEMPRE datos de comportamiento en este framework.
 */
?>
<script type="application/json" id="egc-tablero-datos">
<?php echo wp_json_encode($datos_grafico, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>
