<?php

use EGC\Modules\Sgf\Billetera\BilleteraManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$manager  = BilleteraManagement::get_instance();
$back_url = $manager->back_url();
?>
<article class="container py-5" style="max-width: 640px;">
    <a class="btn btn-outline-secondary btn-sm mb-4" href="<?php echo esc_url($back_url); ?>">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <?php esc_html_e('Regresar', 'egc'); ?>
    </a>

    <?php
    // guard_single() (template_redirect, en BilleteraManagement) ya
    // sacó de acá a cualquiera que no sea el dueño o quien administra
    // el recurso — si el loop llegó a pintarse, ya está autorizado.
    ?>
    <?php while (have_posts()) : the_post(); ?>
        <?php
        $saldo  = (float) get_post_meta(get_the_ID(), '_saldo', true);
        $moneda = (int) get_post_meta(get_the_ID(), '_moneda', true);
        ?>
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h1 class="mb-0"><?php the_title(); ?></h1>
            <?php
            $actions = $manager->actions_for(get_the_ID());
            if ($actions['can_edit'] || $actions['can_trash']) {
                // El single en el que se está parado deja de existir si
                // se elimina: a diferencia de archive.php (donde el
                // ?volver= ya es la pantalla correcta a la que volver),
                // acá hace falta decirlo explícito.
                $actions['redirect_to'] = $back_url;
                include EGC_DIR . '/core/views/partials/post-actions.php';
            }
            ?>
        </div>

        <p class="mb-1">
            <span class="badge text-bg-secondary"><?php echo esc_html($manager->moneda_label($moneda)); ?></span>
        </p>
        <p class="fs-2 mb-0 <?php echo $saldo < 0 ? 'text-danger' : 'text-success'; ?>">
            <?php echo esc_html(number_format_i18n($saldo, 2)); ?>
        </p>
    <?php endwhile; ?>
</article>
