<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Meta box de wp-admin para _saldo/_moneda — incluida por
 * Billetera::render_meta_box(), que ya resolvió $estado. Nada de
 * lógica acá: mismo criterio que las vistas del front-end (ver
 * gestion-usuarios.php), solo que esta vive bajo views/admin/ porque
 * pinta dentro del editor nativo de wp-admin, no dentro de
 * index.php + ViewResolver.
 */
?>
<?php wp_nonce_field($estado['nonce_action'], $estado['nonce_name']); ?>
<p>
    <label for="saldo"><strong><?php esc_html_e('Saldo', 'egc'); ?></strong></label><br>
    <input type="number" step="0.01" id="saldo" name="saldo"
           value="<?php echo esc_attr($estado['saldo']); ?>" class="regular-text">
    <br>
    <span class="description">
        <?php esc_html_e('Un valor negativo es válido: por ejemplo, una cuenta sobregirada o el saldo pendiente de una tarjeta de crédito.', 'egc'); ?>
    </span>
</p>
<p>
    <label for="moneda"><strong><?php esc_html_e('Moneda', 'egc'); ?></strong></label><br>
    <select id="moneda" name="moneda">
        <?php foreach ($estado['moneda_opciones'] as $valor => $etiqueta) : ?>
            <option value="<?php echo esc_attr($valor); ?>" <?php selected($estado['moneda'], $valor); ?>>
                <?php echo esc_html($etiqueta); ?>
            </option>
        <?php endforeach; ?>
    </select>
</p>
