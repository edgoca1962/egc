<?php

use EGC\Core\Banner;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$atributos = Banner::get_instance()->attributes();
$style = $atributos['image']
    ? 'background-image:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5)),url(' . esc_url($atributos['image']) . ');background-size:cover;background-position:center; height:60dvh;'
    : '';
?>
<div class="egc-banner text-white text-center py-5<?php echo $atributos['image'] ? '' : ' bg-dark'; ?>" <?php echo $style ? 'style="' . esc_attr($style) . '"' : ''; ?>>
    <div class="container justify-content-center d-flex flex-column align-items-center h-100">
        <h1 class="mb-0"><?php echo esc_html($atributos['title']); ?></h1>
        <?php if (!empty($atributos['subtitle'])): ?>
            <p class="lead mb-0"><?php echo esc_html($atributos['subtitle']); ?></p>
        <?php endif; ?>
    </div>
</div>
