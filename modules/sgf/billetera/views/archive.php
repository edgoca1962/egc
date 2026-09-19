<?php

use EGC\Modules\Sgf\Billetera\BilleteraManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$manager = BilleteraManagement::get_instance();
$state   = $manager->view_state_archive();
?>
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0"><?php esc_html_e('Billeteras', 'egc'); ?></h1>

        <?php if ($manager->can_create()) : ?>
            <a class="btn btn-primary"
               href="<?php echo esc_url($manager->url_editar()); ?>"
               aria-label="<?php esc_attr_e('Agregar billetera', 'egc'); ?>"
               title="<?php esc_attr_e('Agregar billetera', 'egc'); ?>">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <?php
    // Este loop ya viene filtrado por usuario (o sin filtrar si
    // administra el recurso): ver BilleteraManagement::scope_archive_query(),
    // colgado de pre_get_posts. Acá no hay ninguna decisión de a quién
    // le pertenece cada fila, solo se pinta lo que WordPress ya devolvió.
    ?>
    <?php if (have_posts()) : ?>
        <div class="row g-4">
            <?php while (have_posts()) : the_post(); ?>
                <?php
                $saldo    = (float) get_post_meta(get_the_ID(), '_saldo', true);
                $moneda   = (int) get_post_meta(get_the_ID(), '_moneda', true);
                $post_url = $manager->with_return_here(get_permalink());
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h2 class="h5 card-title">
                                <a class="text-decoration-none" href="<?php echo esc_url($post_url); ?>"><?php the_title(); ?></a>
                            </h2>
                            <p class="mb-1">
                                <span class="badge text-bg-secondary"><?php echo esc_html($manager->moneda_label($moneda)); ?></span>
                            </p>
                            <p class="card-text flex-grow-1 fs-4 <?php echo $saldo < 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo esc_html(number_format_i18n($saldo, 2)); ?>
                            </p>
                            <?php
                            $actions = $manager->actions_for(get_the_ID());
                            if ($actions['can_edit'] || $actions['can_trash']) {
                                include EGC_DIR . '/core/views/partials/post-actions.php';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="mt-4">
            <?php the_posts_pagination(); ?>
        </div>
    <?php else : ?>
        <p><?php esc_html_e('Todavía no registraste ninguna billetera.', 'egc'); ?></p>
    <?php endif; ?>
</div>
