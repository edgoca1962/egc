<?php

use EGC\Core\PasswordChange;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = PasswordChange::get_instance()->view_state();
?>
<div class="container py-5" style="max-width: 480px;">
    <h1 class="h3 mb-4"><?php esc_html_e('Cambiar contraseña', 'egc'); ?></h1>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Contraseña actualizada.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
        <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
        <input type="hidden" name="action" value="egc_password_change">

        <div class="mb-3">
            <label class="form-label" for="current_password"><?php esc_html_e('Contraseña actual', 'egc'); ?></label>
            <input class="form-control" type="password" id="current_password" name="current_password" required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="pass1"><?php esc_html_e('Contraseña nueva', 'egc'); ?></label>
            <input class="form-control" type="password" id="pass1" name="pass1" required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="pass2"><?php esc_html_e('Repetir contraseña nueva', 'egc'); ?></label>
            <input class="form-control" type="password" id="pass2" name="pass2" required>
        </div>

        <button type="submit" class="btn btn-primary">
            <?php esc_html_e('Guardar', 'egc'); ?>
        </button>
    </form>

    <hr>

    <p>
        <a href="<?php echo esc_url($state['account_url']); ?>">
            <?php esc_html_e('Volver a mi cuenta', 'egc'); ?>
        </a>
    </p>
</div>
