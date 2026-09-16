<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = PostManagement::get_instance()->view_state();
?>
<div class="container py-5">

    <?php if ($state['success']): ?>
        <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']): ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <div class="card mb-5">
        <div class="card-body">
            <h2 class="h5 mb-3">
                <?php echo $state['editing']
                    ? esc_html__('Editar publicación', 'egc')
                    : esc_html__('Nueva publicación', 'egc'); ?>
            </h2>

            <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
                <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
                <input type="hidden" name="action" value="egc_blog_save">
                <?php if ($state['editing']): ?>
                    <input type="hidden" name="post_id" value="<?php echo esc_attr($state['editing']['id']); ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label" for="post_title"><?php esc_html_e('Título', 'egc'); ?></label>
                    <input class="form-control" type="text" id="post_title" name="post_title"
                        value="<?php echo esc_attr($state['editing']['title'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="post_content"><?php esc_html_e('Contenido', 'egc'); ?></label>
                    <?php
                    wp_editor($state['editing']['content'] ?? '', 'post_content', [
                        'textarea_name' => 'post_content',
                    ]);
                    ?>
                </div>

                <?php if ($state['can_publish']): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="post_status" name="post_status" value="publish"
                            <?php checked(($state['editing']['status'] ?? '') === 'publish'); ?>>
                        <label class="form-check-label" for="post_status">
                            <?php esc_html_e('Publicar', 'egc'); ?>
                        </label>
                    </div>
                <?php else: ?>
                    <p class="form-text"><?php esc_html_e('Tu publicación queda pendiente de revisión.', 'egc'); ?></p>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Guardar', 'egc'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php if (empty($state['posts'])): ?>
        <p><?php esc_html_e('Todavía no hay publicaciones.', 'egc'); ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Título', 'egc'); ?></th>
                        <th><?php esc_html_e('Estado', 'egc'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($state['posts'] as $post): ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url($post['permalink']); ?>"><?php echo esc_html($post['title']); ?></a>
                            </td>
                            <td><span class="badge text-bg-secondary"><?php echo esc_html($post['status']); ?></span></td>
                            <td class="text-end">
                                <?php if ($post['can_edit']): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo esc_url($post['edit_url']); ?>">
                                        <?php esc_html_e('Editar', 'egc'); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($post['can_trash']): ?>
                                    <form method="post" action="<?php echo esc_url($state['trash_form_action']); ?>"
                                        class="d-inline">
                                        <?php wp_nonce_field($state['trash_nonce_action'], $state['nonce_name']); ?>
                                        <input type="hidden" name="action" value="egc_blog_trash">
                                        <input type="hidden" name="post_id" value="<?php echo esc_attr($post['id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <?php esc_html_e('Eliminar', 'egc'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
