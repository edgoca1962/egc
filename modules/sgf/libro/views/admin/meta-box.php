<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Meta box de wp-admin para el movimiento — incluida por
 * Libro::render_meta_box(), que ya resolvió $estado. Debe/Haber no
 * aparecen acá: son internos, se derivan del signo de Monto al guardar
 * (ver Libro::guardar_meta_box()) — quien carga esto siempre trabaja
 * con un solo valor con signo, nunca con los dos campos por separado.
 */
?>
<?php wp_nonce_field($estado['nonce_action'], $estado['nonce_name']); ?>
<p>
    <label for="billetera_id"><strong><?php esc_html_e('Billetera', 'egc'); ?></strong></label><br>
    <select id="billetera_id" name="billetera_id" class="widefat">
        <option value="0"><?php esc_html_e('— Elegir —', 'egc'); ?></option>
        <?php foreach ($estado['billetera_opciones'] as $billetera_id => $etiqueta) : ?>
            <option value="<?php echo esc_attr($billetera_id); ?>" <?php selected($estado['billetera_id'], $billetera_id); ?>>
                <?php echo esc_html($etiqueta); ?>
            </option>
        <?php endforeach; ?>
    </select>
</p>
<p>
    <label for="monto"><strong><?php esc_html_e('Monto', 'egc'); ?></strong></label><br>
    <input type="number" step="0.01" id="monto" name="monto"
           value="<?php echo esc_attr($estado['monto']); ?>" class="regular-text">
    <br>
    <span class="description">
        <?php esc_html_e('Positivo = haber (ingreso). Negativo = debe (egreso).', 'egc'); ?>
    </span>
</p>
<p>
    <label for="referencia"><strong><?php esc_html_e('Referencia', 'egc'); ?></strong></label><br>
    <input type="text" id="referencia" name="referencia"
           value="<?php echo esc_attr($estado['referencia']); ?>" class="regular-text">
</p>
