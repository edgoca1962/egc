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
 */
$manager = Tablero::get_instance();
$state   = $manager->view_state();
$filtros = $state['filtros'];

$datos_grafico = [
    'serie_mensual' => $state['serie_mensual'],
    'paretos'       => $state['paretos'],
];
?>
<div class="container py-5">
    <h1 class="h3 mb-4"><?php esc_html_e('Tablero', 'egc'); ?></h1>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label" for="billetera_id"><?php esc_html_e('Billetera', 'egc'); ?></label>
                    <select class="form-select" id="billetera_id" name="billetera_id">
                        <option value="0"><?php esc_html_e('Todas mis billeteras', 'egc'); ?></option>
                        <?php foreach ($state['billetera_opciones'] as $billetera_id => $titulo) : ?>
                            <option value="<?php echo esc_attr($billetera_id); ?>"
                                <?php selected($filtros['billetera_id'], $billetera_id); ?>>
                                <?php echo esc_html($titulo); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="fecha_desde"><?php esc_html_e('Fecha desde', 'egc'); ?></label>
                    <input class="form-control" type="date" id="fecha_desde" name="fecha_desde"
                           value="<?php echo esc_attr($filtros['fecha_desde']); ?>">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="fecha_hasta"><?php esc_html_e('Fecha hasta', 'egc'); ?></label>
                    <input class="form-control" type="date" id="fecha_hasta" name="fecha_hasta"
                           value="<?php echo esc_attr($filtros['fecha_hasta']); ?>">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="monto_desde"><?php esc_html_e('Monto desde', 'egc'); ?></label>
                    <input class="form-control" type="number" step="0.01" min="0" id="monto_desde" name="monto_desde"
                           value="<?php echo esc_attr($filtros['monto_desde']); ?>">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="monto_hasta"><?php esc_html_e('Monto hasta', 'egc'); ?></label>
                    <input class="form-control" type="number" step="0.01" min="0" id="monto_hasta" name="monto_hasta"
                           value="<?php echo esc_attr($filtros['monto_hasta']); ?>">
                </div>

                <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="categoria_filtro"><?php esc_html_e('Categorización', 'egc'); ?></label>
                    <select class="form-select" id="categoria_filtro" name="categoria_filtro">
                        <?php foreach ($state['categoria_opciones_filtro'] as $categoria) : ?>
                            <option value="<?php echo esc_attr($categoria['id']); ?>"
                                <?php selected($filtros['categoria_filtro'], $categoria['id']); ?>>
                                <?php echo esc_html(str_repeat('— ', $categoria['profundidad']) . $categoria['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <label class="form-label" for="texto"><?php esc_html_e('La descripción contiene', 'egc'); ?></label>
                    <input class="form-control" type="text" id="texto" name="texto"
                           value="<?php echo esc_attr($filtros['texto']); ?>">
                </div>

                <?php
                // anio/mes ocultos: preservan la selección del Comparativo
                // (ver el docblock de esta vista) al aplicar este filtro.
                ?>
                <input type="hidden" name="anio" value="<?php echo esc_attr($state['año_comparativo']); ?>">
                <input type="hidden" name="mes" value="<?php echo esc_attr($state['mes_comparativo']); ?>">

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
            <div class="col-md-6">
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
            <div class="col-lg-6">
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
                        <div class="egc-tablero-chart">
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
            <div class="col-lg-6">
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
            <div class="col-lg-6">
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

                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        <?php esc_html_e('Actualizar', 'egc'); ?>
                    </button>
                </div>
            </form>

            <?php foreach ($state['comparativo'] as $fila) : ?>
                <h3 class="h6 text-muted"><?php echo esc_html($fila['etiqueta']); ?></h3>
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
                        <tbody>
                            <tr>
                                <td><?php esc_html_e('Ingresos', 'egc'); ?></td>
                                <td class="text-end text-success"><?php echo esc_html(number_format_i18n($fila['ingresos_real'], 2)); ?></td>
                                <td class="text-end text-success"><?php echo esc_html(number_format_i18n($fila['ingresos_presupuestado'], 2)); ?></td>
                                <td class="text-end <?php echo $fila['ingresos_variacion_absoluta'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['ingresos_variacion_absoluta'], 2)); ?>
                                </td>
                                <td class="text-end <?php echo $fila['ingresos_variacion_relativa'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['ingresos_variacion_relativa'], 2)); ?>%
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Egresos y Gastos', 'egc'); ?></td>
                                <td class="text-end text-danger"><?php echo esc_html(number_format_i18n($fila['egresos_real'], 2)); ?></td>
                                <td class="text-end text-danger"><?php echo esc_html(number_format_i18n($fila['egresos_presupuestado'], 2)); ?></td>
                                <td class="text-end <?php echo $fila['egresos_variacion_absoluta'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['egresos_variacion_absoluta'], 2)); ?>
                                </td>
                                <td class="text-end <?php echo $fila['egresos_variacion_relativa'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($fila['egresos_variacion_relativa'], 2)); ?>%
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="table-group-divider">
                            <tr class="fw-semibold">
                                <td><?php esc_html_e('Diferencia', 'egc'); ?></td>
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
