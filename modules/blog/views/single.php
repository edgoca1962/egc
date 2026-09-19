<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$back_url = PostManagement::get_instance()->back_url();
?>
<article class="container py-5">
    <a class="btn btn-outline-secondary btn-sm mb-4" href="<?php echo esc_url($back_url); ?>">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <?php esc_html_e('Regresar', 'egc'); ?>
    </a>

    <?php while (have_posts()) : the_post(); ?>
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h1 class="mb-0"><?php the_title(); ?></h1>
            <?php
            $actions = PostManagement::get_instance()->actions_for(get_the_ID());
            if ($actions['can_edit'] || $actions['can_trash']) {
                // El post en el que se está parado deja de existir si se
                // elimina: a diferencia de archive.php/panel.php (donde el
                // Referer del trash ya es la pantalla correcta a la que
                // volver), acá hace falta decirlo explícito.
                $actions['redirect_to'] = $back_url;
                include EGC_DIR . '/core/views/partials/post-actions.php';
            }
            ?>
        </div>
        <p class="text-muted mb-4"><?php echo esc_html(get_the_date()); ?></p>

        <?php if (has_post_thumbnail()) : ?>
            <div class="mb-4"><?php the_post_thumbnail('large', ['class' => 'img-fluid rounded']); ?></div>
        <?php endif; ?>

        <div class="mb-5"><?php the_content(); ?></div>

        <?php
        /**
         * comments_template(), no include: es la función nativa de
         * WordPress la que arma $wp_query->comments/comment_count a
         * partir de get_comments() — have_comments() y
         * wp_list_comments() (sin argumento $comments explícito, como
         * los usa el partial) leen de ahí, no del post actual. Un
         * include directo del partial nunca la llama, así que esas dos
         * funciones veían siempre 0 comentarios aunque el post tuviera
         * comentarios reales en la base de datos — pasaba desapercibido
         * porque "0 comentarios" es también el estado esperado sin
         * comentarios.
         *
         * El parámetro es la ruta del partial relativa a la raíz del
         * tema (comments_template() la resuelve sola contra
         * STYLESHEETPATH/TEMPLATEPATH), así que no hace falta un
         * comments.php propio en la raíz: el partial existente se usa
         * tal cual.
         */
        comments_template('/core/views/partials/comentarios.php');
        ?>
    <?php endwhile; ?>
</article>
