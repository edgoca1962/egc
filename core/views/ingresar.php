<?php

use EGC\Core\LoginPage;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = LoginPage::get_instance()->view_state();
?>
<div class="container py-5" style="max-width: 480px;">

    <?php if ($state['error']): ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
        <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
        <input type="hidden" name="action" value="egc_login">
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($state['redirect_to']); ?>">

        <div class="mb-3">
            <label class="form-label" for="log"><?php esc_html_e('Usuario o correo', 'egc'); ?></label>
            <input class="form-control" type="text" id="log" name="log" required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="pwd"><?php esc_html_e('Contraseña', 'egc'); ?></label>
            <input class="form-control" type="password" id="pwd" name="pwd" required>
        </div>

        <div class="mb-3 form-check">
            <input class="form-check-input" type="checkbox" id="rememberme" name="rememberme" value="forever">
            <label class="form-check-label" for="rememberme"><?php esc_html_e('Recordarme', 'egc'); ?></label>
        </div>

        <button type="submit" class="btn btn-primary">
            <?php esc_html_e('Ingresar', 'egc'); ?>
        </button>
    </form>

    <hr>

    <p>
        <a href="<?php echo esc_url($state['lost_password_url']); ?>">
            <?php esc_html_e('¿Olvidaste tu contraseña?', 'egc'); ?>
        </a>
    </p>
    <p>
        <a href="<?php echo esc_url($state['register_url']); ?>">
            <?php esc_html_e('Solicitar ingreso', 'egc'); ?>
        </a>
    </p>
</div>
