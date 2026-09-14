<?php

use EGC\Core\UserRegistration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = UserRegistration::get_instance()->view_state();
?>
<div class="container py-5" style="max-width: 480px;">
    <h1 class="h3 mb-4"><?php esc_html_e('Solicitar ingreso', 'egc'); ?></h1>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success">
            <?php esc_html_e('Tu solicitud fue recibida. Te avisaremos por correo cuando esté activa.', 'egc'); ?>
        </div>
        <a class="btn btn-primary" href="<?php echo esc_url($state['login_url']); ?>">
            <?php esc_html_e('Volver a ingresar', 'egc'); ?>
        </a>
    <?php else : ?>
        <?php if ($state['error']) : ?>
            <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
            <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
            <input type="hidden" name="action" value="egc_solicitar_ingreso">

            <div class="mb-3">
                <label class="form-label" for="email"><?php esc_html_e('Correo', 'egc'); ?></label>
                <input class="form-control" type="email" id="email" name="email" required>
            </div>

            <button type="submit" class="btn btn-primary">
                <?php esc_html_e('Solicitar ingreso', 'egc'); ?>
            </button>
        </form>

        <hr>

        <p>
            <a href="<?php echo esc_url($state['login_url']); ?>">
                <?php esc_html_e('Ya tengo cuenta, ingresar', 'egc'); ?>
            </a>
        </p>
    <?php endif; ?>
</div>
