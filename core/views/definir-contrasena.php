<?php

use EGC\Core\PasswordReset;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = PasswordReset::get_instance()->view_state();
?>
<div class="container py-5" style="max-width: 480px;">
    <h1 class="h3 mb-4"><?php esc_html_e('Definir contraseña', 'egc'); ?></h1>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <?php if (!$state['valid']) : ?>
        <p><?php esc_html_e('El enlace no es válido o ya fue usado.', 'egc'); ?></p>
        <a class="btn btn-primary" href="<?php echo esc_url($state['login_url']); ?>">
            <?php esc_html_e('Volver a ingresar', 'egc'); ?>
        </a>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
            <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
            <input type="hidden" name="action" value="egc_definir_contrasena">
            <input type="hidden" name="key" value="<?php echo esc_attr($state['key']); ?>">
            <input type="hidden" name="login" value="<?php echo esc_attr($state['login']); ?>">

            <div class="mb-3">
                <label class="form-label" for="pass1"><?php esc_html_e('Contraseña nueva', 'egc'); ?></label>
                <input class="form-control" type="password" id="pass1" name="pass1" required>
            </div>

            <div class="mb-3">
                <label class="form-label" for="pass2"><?php esc_html_e('Repetir contraseña', 'egc'); ?></label>
                <input class="form-control" type="password" id="pass2" name="pass2" required>
            </div>

            <button type="submit" class="btn btn-primary">
                <?php esc_html_e('Guardar', 'egc'); ?>
            </button>
        </form>
    <?php endif; ?>
</div>
