<?php

use EGC\Core\ViewResolver;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?> data-bs-theme="dark">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header>
    <?php get_template_part('core/views/navbar'); ?>
    <?php get_template_part('core/views/banner'); ?>
</header>

<main>
    <?php
    $view = ViewResolver::get_instance()->resolve();

    /**
     * Contenido de la Página + plantilla propia, complementarios: si es
     * una Página (del Core o de un módulo) con post_content real
     * cargado, se muestra primero y la plantilla se pinta debajo. No
     * aplica a 'core/views/pagina' (nivel 4: esa vista YA es
     * exclusivamente the_content(), no hay nada que anidarle debajo) ni
     * a single/archive de un módulo (no son Páginas; single.php ya
     * llama a the_content() por su cuenta para el cuerpo del post).
     */
    $es_pagina_con_plantilla_propia = is_page()
        && !in_array($view, ['core/views/pagina', 'core/views/sin-contenido'], true);

    if ($es_pagina_con_plantilla_propia && have_posts()) {
        the_post();
        if (trim(get_the_content()) !== '') :
            ?>
            <div class="container py-4">
                <?php the_content(); ?>
            </div>
            <?php
        endif;
    }

    get_template_part($view);
    ?>
</main>

<?php wp_footer(); ?>
</body>
</html>
