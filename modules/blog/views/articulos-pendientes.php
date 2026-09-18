<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = PostManagement::get_instance()->view_state_pendientes();
?>
<div class="container py-5">
    <h1 class="h3 mb-4"><?php esc_html_e('Artículos pendientes de publicar', 'egc'); ?></h1>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Publicado.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <?php if (empty($state['posts'])) : ?>
        <p><?php esc_html_e('No hay artículos pendientes de revisión.', 'egc'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Título', 'egc'); ?></th>
                        <th><?php esc_html_e('Autor', 'egc'); ?></th>
                        <th><?php esc_html_e('Enviado', 'egc'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($state['posts'] as $post) : ?>
                    <tr>
                        <td><?php echo esc_html($post['title']); ?></td>
                        <td><?php echo esc_html($post['author']); ?></td>
                        <td><?php echo esc_html($post['date']); ?></td>
                        <td class="text-end">
                            <?php if ($post['can_publish']) : ?>
                                <form method="post" action="<?php echo esc_url($state['form_action']); ?>" class="d-inline">
                                    <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
                                    <input type="hidden" name="action" value="egc_blog_publicar">
                                    <input type="hidden" name="post_id" value="<?php echo esc_attr($post['id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"
                                            aria-label="<?php esc_attr_e('Publicar', 'egc'); ?>"
                                            title="<?php esc_attr_e('Publicar', 'egc'); ?>">
                                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
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
