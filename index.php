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

    <?php
    /**
     * Filtro en vez de que index.php pregunte is_page(...) directo: hoy
     * solo LoginPage lo usa (ver su docblock), para pintarse a pantalla
     * completa sin navbar ni banner — ver core/views/ingresar.php. Pero
     * index.php no tiene por qué conocer esa página puntual ni ninguna
     * futura que quiera lo mismo: cualquier clase de página, de cualquier
     * módulo, puede enganchar 'egc_mostrar_cabecera' y decidir sola,
     * mirando su propio is_page(), sin que index.php ni el Core se
     * enteren de que existe — mismo patrón que ya usa 'egc_banner_atributos'
     * (y que show_admin_bar nativo de WordPress: filtro booleano,
     * default true, cualquiera lo puede apagar).
     */
    if (apply_filters('egc_mostrar_cabecera', true)):
        ?>
        <header>
            <?php get_template_part('core/views/navbar'); ?>
            <?php get_template_part('core/views/banner'); ?>
        </header>
    <?php endif; ?>

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
            if (trim(get_the_content()) !== ''):
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
<?php
$movimientos = get_posts(array(
    'post_type' => 'libro',
    'posts_per_page' => -1,
    'post_status' => 'publish',
));
foreach ($movimientos as $movimiento) {
    // echo $movimiento->ID . ' - ' . $movimiento->post_title . '<br>';
    // wp_delete_post($movimiento->ID,true);
}