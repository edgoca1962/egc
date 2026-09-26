<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Meta box de wp-admin para _saldo_inicial/_moneda — incluida por
 * Billetera::render_meta_box(), que ya resolvió $estado. Nada de
 * lógica acá: mismo criterio que las vistas del front-end (ver
 * gestion-usuarios.php), solo que esta vive bajo views/admin/ porque
 * pinta dentro del editor nativo de wp-admin, no dentro de
 * index.php + ViewResolver.
 *
 * El saldo actual (`$estado['saldo_actual']`) se muestra de solo
 * lectura, nunca como `<input>`: es un valor derivado que
 * Libro::recalcular_saldo_de() recalcula solo apenas se guarda este
 * meta box (ver Billetera::guardar_meta_box()) — ofrecerlo editable
 * sería un control que el propio guardado vuelve a pisar. No se
 * muestra en absoluto para una billetera todavía sin guardar
 * (`$estado['es_nueva']`): antes del primer guardado no hay nada
 * calculado todavía, solo el saldo inicial que se está por cargar.
 */
?>
<?php wp_nonce_field($estado['nonce_action'], $estado['nonce_name']); ?>
<p>
    <label for="saldo_inicial"><strong><?php esc_html_e('Saldo inicial', 'egc'); ?></strong></label><br>
    <input type="number" step="0.01" id="saldo_inicial" name="saldo_inicial"
           value="<?php echo esc_attr($estado['saldo_inicial']); ?>" class="regular-text">
    <br>
    <span class="description">
        <?php esc_html_e('El saldo con el que arrancó esta billetera, antes de empezar a registrar movimientos acá. Un valor negativo es válido: por ejemplo, una cuenta sobregirada o el saldo pendiente de una tarjeta de crédito.', 'egc'); ?>
    </span>
</p>
<?php if (!$estado['es_nueva']) : ?>
    <p>
        <strong><?php esc_html_e('Saldo actual', 'egc'); ?></strong><br>
        <?php echo esc_html(number_format_i18n($estado['saldo_actual'], 2)); ?>
        <br>
        <span class="description">
            <?php esc_html_e('Se calcula solo (saldo inicial + movimientos) y no se edita acá.', 'egc'); ?>
        </span>
    </p>
<?php endif; ?>
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
