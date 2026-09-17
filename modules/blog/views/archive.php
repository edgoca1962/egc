<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0"><?php esc_html_e('Blog', 'egc'); ?></h1>

        <?php if (current_user_can('edit_posts')) : ?>
            <a class="btn btn-primary"
               href="<?php echo esc_url(PostManagement::get_instance()->url()); ?>"
               aria-label="<?php esc_attr_e('Crear artículo', 'egc'); ?>"
               title="<?php esc_attr_e('Crear artículo', 'egc'); ?>">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>

    <?php if (have_posts()) : ?>
        <div class="row g-4">
            <?php while (have_posts()) : the_post(); ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <?php if (has_post_thumbnail()) : ?>
                            <?php the_post_thumbnail('medium', ['class' => 'card-img-top']); ?>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <h2 class="h5 card-title">
                                <a class="text-decoration-none" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h2>
                            <p class="card-text flex-grow-1"><?php the_excerpt(); ?></p>
                            <?php
                            $actions = PostManagement::get_instance()->actions_for(get_the_ID());
                            if ($actions['can_edit'] || $actions['can_trash']) {
                                include EGC_DIR . '/modules/blog/views/partials/post-actions.php';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="mt-4">
            <?php the_posts_pagination(); ?>
        </div>
    <?php else : ?>
        <p><?php esc_html_e('Todavía no hay publicaciones.', 'egc'); ?></p>
    <?php endif; ?>
</div>
