<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Botón de sembrado/reinicio de categorías, para una fila de "Gestión
 * de usuarios" (core/views/gestion-usuarios.php) — incluido por
 * Categoria::render_user_row_actions(), que ya resolvió $estado (ver
 * Categoria::view_state_fila_usuario()). Acá no hay ninguna decisión
 * de autorización ni de negocio, solo se pinta.
 */
?>
<form method="post" action="<?php echo esc_url($estado['form_action']); ?>" class="d-inline">
    <?php wp_nonce_field($estado['nonce_action'], $estado['nonce_name']); ?>
    <input type="hidden" name="action" value="<?php echo esc_attr($estado['action']); ?>">
    <input type="hidden" name="user_id" value="<?php echo esc_attr($estado['user_id']); ?>">
    <input type="hidden" name="redirect_to" value="<?php echo esc_url($estado['redirect_to']); ?>">
    <?php
    $categoria_label = $estado['tiene_categorias']
        ? __('Reiniciar categorías a la base', 'egc')
        : __('Sembrar categorías base', 'egc');
    ?>
    <button
        type="submit"
        class="btn btn-sm <?php echo $estado['tiene_categorias'] ? 'btn-outline-danger' : 'btn-outline-primary'; ?>"
        title="<?php echo esc_attr($categoria_label); ?>"
        aria-label="<?php echo esc_attr($categoria_label); ?>"
    >
        <i class="bi <?php echo $estado['tiene_categorias'] ? 'bi-tags-fill' : 'bi-tags'; ?>" aria-hidden="true"></i>
    </button>
</form>
