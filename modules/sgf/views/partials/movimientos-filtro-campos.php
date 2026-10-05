<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Campos del filtro de movimientos — el mismo en Mantenimiento de
 * movimientos (libro-mantenimiento.php), en el Tablero (tablero.php) y
 * en el detalle de una billetera (billetera/views/single.php). Tercer
 * uso real, por eso recién acá se extrae a un partial: antes eran dos
 * copias del mismo bloque (ver SRP APLICADO: se extrae cuando existe el
 * segundo módulo que lo necesita, no antes).
 *
 * Imprime SOLO los campos (cada uno en su `<div class="col-…">`), no el
 * `<form>` ni el botón de enviar: cada vista arma su propio `<form
 * method="get">` y decide qué más lleva adentro (el Tablero suma
 * campos ocultos para preservar los selectores de sus Comparativos; el
 * detalle de billetera suma `volver`).
 *
 * Variables que espera del scope de la vista que lo incluye:
 *
 * - $filtros                   Filtros ya normalizados (los devuelve el
 *                              view_state de cada pantalla).
 * - $categoria_opciones_filtro Opciones de LibroManagement::categoria_opciones_filtro().
 * - $billetera_opciones        OPCIONAL. Si viene (array id => título),
 *                              se pinta el `<select>` de billetera; si no
 *                              está definida o es null, no se pinta —
 *                              es el caso del detalle de billetera, donde
 *                              la billetera ya está elegida por la propia
 *                              página en la que se está parado.
 */
?>
<?php if (isset($billetera_opciones)) : ?>
    <div class="col-sm-4 col-lg-3">
        <label class="form-label" for="billetera_id"><?php esc_html_e('Billetera', 'egc'); ?></label>
        <select class="form-select" id="billetera_id" name="billetera_id">
            <option value="0"><?php esc_html_e('Todas mis billeteras', 'egc'); ?></option>
            <?php foreach ($billetera_opciones as $billetera_id => $titulo) : ?>
                <option value="<?php echo esc_attr($billetera_id); ?>"
                    <?php selected($filtros['billetera_id'], $billetera_id); ?>>
                    <?php echo esc_html($titulo); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
<?php endif; ?>

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
        <?php foreach ($categoria_opciones_filtro as $categoria) : ?>
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
