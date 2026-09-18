<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = PostManagement::get_instance()->view_state_editar();

if (!$state) {
    return;
}
?>
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><?php esc_html_e('Editar publicación', 'egc'); ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo esc_url($state['back_url']); ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <?php esc_html_e('Regresar', 'egc'); ?>
        </a>
    </div>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
                <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
                <input type="hidden" name="action" value="egc_blog_save">
                <input type="hidden" name="post_id" value="<?php echo esc_attr($state['editing']['id']); ?>">
                <?php // Adónde volver DESPUÉS de guardar: la pantalla de la que
                // se llegó a blog-editar (no el Referer de este POST, que va
                // a ser esta misma pantalla). Ver PostManagement::redirect_target(). ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['back_url']); ?>">

                <div class="mb-3">
                    <label class="form-label" for="post_title"><?php esc_html_e('Título', 'egc'); ?></label>
                    <input class="form-control" type="text" id="post_title" name="post_title"
                           value="<?php echo esc_attr($state['editing']['title']); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="post_content"><?php esc_html_e('Contenido', 'egc'); ?></label>
                    <?php
                    wp_editor($state['editing']['content'], 'post_content', [
                        'textarea_name' => 'post_content',
                    ]);
                    ?>
                </div>

                <?php if ($state['can_publish']) : ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="post_status" name="post_status"
                               value="publish"<?php checked($state['editing']['status'] === 'publish'); ?>>
                        <label class="form-check-label" for="post_status">
                            <?php esc_html_e('Publicar', 'egc'); ?>
                        </label>
                    </div>
                <?php else : ?>
                    <p class="form-text"><?php esc_html_e('Tu publicación queda pendiente de revisión.', 'egc'); ?></p>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Guardar', 'egc'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
