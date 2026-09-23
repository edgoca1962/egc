<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Meta box de wp-admin para el presupuesto — incluida por
 * Presupuesto::render_meta_box(), que ya resolvió $estado. El Autor
 * (dueño del presupuesto) no aparece acá: es el meta box nativo de
 * WordPress, gratis por declarar `'author'` en `supports` — ver el
 * docblock de Presupuesto::register_post_type().
 *
 * El `<select>` de Categoría solo aparece si $estado['categoria_opciones']
 * no viene vacío — con un presupuesto nuevo (todavía sin Autor
 * guardado) no hay de quién sacar categorías, así que se muestra un
 * aviso en su lugar (ver el docblock de Presupuesto::render_meta_box()).
 */
?>
<?php wp_nonce_field($estado['nonce_action'], $estado['nonce_name']); ?>
<p>
    <label for="categoria_id"><strong><?php esc_html_e('Categoría', 'egc'); ?></strong></label><br>
    <?php if (!empty($estado['categoria_opciones'])) : ?>
        <select id="categoria_id" name="categoria_id" class="widefat">
            <option value="0"><?php esc_html_e('— Elegir —', 'egc'); ?></option>
            <?php foreach ($estado['categoria_opciones'] as $categoria) : ?>
                <option value="<?php echo esc_attr($categoria['id']); ?>"
                    <?php selected($estado['categoria_id'], $categoria['id']); ?>>
                    <?php echo esc_html(str_repeat('— ', $categoria['profundidad']) . $categoria['nombre']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php else : ?>
        <span class="description">
            <?php esc_html_e('Elegí y guardá primero un Autor. Una vez guardado el presupuesto, volvé a abrirlo para asignarle una categoría.', 'egc'); ?>
        </span>
    <?php endif; ?>
</p>
<p>
    <label for="monto"><strong><?php esc_html_e('Monto mensual', 'egc'); ?></strong></label><br>
    <input type="number" step="0.01" min="0" id="monto" name="monto"
           value="<?php echo esc_attr($estado['monto']); ?>" class="regular-text">
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
<p>
    <label for="anio"><strong><?php esc_html_e('Año', 'egc'); ?></strong></label><br>
    <input type="number" step="1" min="2000" max="2099" id="anio" name="anio"
           value="<?php echo esc_attr($estado['año']); ?>" class="regular-text">
</p>
