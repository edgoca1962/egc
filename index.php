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

    <main>
        <?php get_template_part(ViewResolver::get_instance()->resolve()); ?>
    </main>

    <?php wp_footer(); ?>
</body>

</html>
