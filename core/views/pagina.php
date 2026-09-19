<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<?php while (have_posts()):
    the_post(); ?>
    <?php
    /**
     * post_class('container'): agrega "container" a la lista de
     * clases que WordPress ya arma sola (post-123, page,
     * type-page, etc.) — no se puede poner un class="container"
     * aparte, post_class() ya imprime su propio atributo class
     * completo. Es la forma nativa de sumar una clase propia sin
     * pisar las que WordPress calcula.
     */
    ?>
    <article <?php post_class('container py-5'); ?>>
        <h1><?php the_title(); ?></h1>
        <?php the_content(); ?>

        <?php
        /**
         * Ninguna Página tiene un módulo dueño (post_types en los
         * manifests son siempre CPT), así que este partial de Core es
         * el lugar que corresponde — mismo bloque, compartido, que usa
         * modules/blog/views/single.php.
         *
         * comments_template(), no include: es la función nativa de
         * WordPress la que arma $wp_query->comments/comment_count a
         * partir de get_comments() — have_comments() y
         * wp_list_comments() (sin argumento $comments explícito, como
         * los usa el partial) leen de ahí, no del post actual. Un
         * include directo del partial nunca la llama, así que esas dos
         * funciones veían siempre 0 comentarios aunque la Página
         * tuviera comentarios reales en la base de datos — pasaba
         * desapercibido porque "0 comentarios" es también el estado
         * esperado sin comentarios.
         *
         * El parámetro es la ruta del partial relativa a la raíz del
         * tema (comments_template() la resuelve sola contra
         * STYLESHEETPATH/TEMPLATEPATH), así que no hace falta un
         * comments.php propio en la raíz: el partial existente se usa
         * tal cual.
         */
        comments_template('/core/views/partials/comentarios.php');
        ?>
    </article>
<?php endwhile; ?>
