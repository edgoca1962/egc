<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Botón "Marcar ejecutado" de un acuerdo (módulo SCA, capa Vista).
 *
 * Espera $ejecutar, que Listado::ejecutar() arma SOLO si el usuario
 * puede marcarlo y el acuerdo no está ejecutado (si no, la vista ni
 * incluye este partial). Acá no se decide nada: se pinta un formulario
 * POST a admin-post.php. El handler (AcuerdoManagement::handle_ejecutar)
 * vuelve a validar sesión, capacidad y nonce; ocultar el botón es
 * presentación, no seguridad.
 */
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="d-inline">
    <?php wp_nonce_field($ejecutar['action'], $ejecutar['nonce_name']); ?>
    <input type="hidden" name="action" value="<?php echo esc_attr($ejecutar['action']); ?>">
    <input type="hidden" name="post_id" value="<?php echo esc_attr($ejecutar['id']); ?>">
    <input type="hidden" name="redirect_to" value="<?php echo esc_url($ejecutar['redirect_to']); ?>">
    <button type="submit" class="btn btn-sm btn-outline-success"
            aria-label="<?php esc_attr_e('Marcar ejecutado', 'egc'); ?>"
            title="<?php esc_attr_e('Marcar ejecutado', 'egc'); ?>">
        <i class="bi bi-check2-circle" aria-hidden="true"></i>
        <?php esc_html_e('Marcar ejecutado', 'egc'); ?>
    </button>
</form>
