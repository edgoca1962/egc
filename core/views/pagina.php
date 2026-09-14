<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<?php while (have_posts()):
    the_post(); ?>
    <article class="container py-5">
        <h1><?php the_title(); ?></h1>
        <?php the_content(); ?>
    </article>
<?php endwhile; ?>
