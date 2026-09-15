<?php

use EGC\Core\Account;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = Account::get_instance()->view_state();
?>
<div class="container py-5" style="max-width: 480px;">
    <h1 class="h3 mb-4"><?php esc_html_e('Mi cuenta', 'egc'); ?></h1>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <div class="text-center mb-4">
        <img src="<?php echo esc_url($state['avatar_url']); ?>" alt="" id="avatar-preview"
             class="rounded-circle" width="96" height="96" style="object-fit:cover;">
    </div>

    <form method="post" action="<?php echo esc_url($state['form_action']); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
        <input type="hidden" name="action" value="egc_account_update">

        <div class="mb-3">
            <label class="form-label" for="display_name"><?php esc_html_e('Nombre visible', 'egc'); ?></label>
            <input class="form-control" type="text" id="display_name" name="display_name"
                   value="<?php echo esc_attr($state['display_name']); ?>">
        </div>

        <div class="mb-3">
            <label class="form-label" for="email"><?php esc_html_e('Correo', 'egc'); ?></label>
            <input class="form-control" type="email" id="email" value="<?php echo esc_attr($state['email']); ?>" disabled>
        </div>

        <div class="mb-3">
            <label class="form-label" for="avatar"><?php esc_html_e('Foto', 'egc'); ?></label>
            <input class="form-control" type="file" id="avatar" name="avatar" accept="image/*">
        </div>

        <button type="submit" class="btn btn-primary">
            <?php esc_html_e('Guardar', 'egc'); ?>
        </button>
    </form>

    <hr>

    <p>
        <a href="<?php echo esc_url($state['password_change_url']); ?>">
            <?php esc_html_e('Cambiar contraseña', 'egc'); ?>
        </a>
    </p>
</div>
