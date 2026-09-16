<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<div class="container py-5">
    <h1 class="mb-4"><?php esc_html_e('Blog', 'egc'); ?></h1>

    <?php if (have_posts()) : ?>
        <div class="row g-4">
            <?php while (have_posts()) : the_post(); ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <?php if (has_post_thumbnail()) : ?>
                            <?php the_post_thumbnail('medium', ['class' => 'card-img-top']); ?>
                        <?php endif; ?>
                        <div class="card-body">
                            <h2 class="h5 card-title">
                                <a class="text-decoration-none" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h2>
                            <p class="card-text"><?php the_excerpt(); ?></p>
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
