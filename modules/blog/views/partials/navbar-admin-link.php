<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * <li> del dropdown del navbar para quien administra el Blog. Incluido
 * por PostManagement::render_navbar_link(), colgado del hook
 * egc_navbar_admin_dropdown que dispara core/views/navbar.php — la
 * visibilidad (quién llega a verlo) ya se decidió ahí, esto solo pinta.
 */
?>
<li>
    <a class="dropdown-item" href="<?php echo esc_url(PostManagement::get_instance()->url_pendientes()); ?>">
        <?php esc_html_e('Artículos pendientes de publicar', 'egc'); ?>
    </a>
</li>
