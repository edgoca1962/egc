<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Campos ocultos comunes de los formularios de SCA: nonce, action de
 * admin-post.php, el post_id (si se edita) y adónde volver DESPUÉS de
 * guardar (no el Referer de este POST, que sería esta misma pantalla).
 *
 * Espera $state.
 */
wp_nonce_field($state['nonce_action'], $state['nonce_name']);
?>
<input type="hidden" name="action" value="<?php echo esc_attr($state['action']); ?>">
<?php if ($state['editing']) : ?>
    <input type="hidden" name="post_id" value="<?php echo esc_attr($state['editing']['id']); ?>">
<?php endif; ?>
<input type="hidden" name="redirect_to" value="<?php echo esc_url($state['back_url']); ?>">
