<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<article class="container py-5">
    <?php while (have_posts()) : the_post(); ?>
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h1 class="mb-0"><?php the_title(); ?></h1>
            <?php
            $actions = PostManagement::get_instance()->actions_for(get_the_ID());
            if ($actions['can_edit'] || $actions['can_trash']) {
                include EGC_DIR . '/modules/blog/views/partials/post-actions.php';
            }
            ?>
        </div>
        <p class="text-muted mb-4"><?php echo esc_html(get_the_date()); ?></p>

        <?php if (has_post_thumbnail()) : ?>
            <div class="mb-4"><?php the_post_thumbnail('large', ['class' => 'img-fluid rounded']); ?></div>
        <?php endif; ?>

        <div class="mb-5"><?php the_content(); ?></div>

        <?php if (comments_open() || get_comments_number()) : ?>
            <hr>
            <?php wp_list_comments(['style' => 'div', 'short_ping' => true]); ?>
            <?php the_comments_pagination(); ?>
            <?php comment_form(); ?>
        <?php endif; ?>
    <?php endwhile; ?>
</article>
