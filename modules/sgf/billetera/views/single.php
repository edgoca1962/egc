<?php

use EGC\Modules\Sgf\Billetera\BilleteraManagement;
use EGC\Modules\Sgf\Libro\LibroManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$manager       = BilleteraManagement::get_instance();
$libro_manager = LibroManagement::get_instance();
$back_url      = $manager->back_url();
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
            <h1 class="mb-0 d-flex align-items-center gap-2">
                <?php the_title(); ?>
                <?php // Mismo ID visible que archive.php — ver su comentario. ?>
                <span class="badge text-bg-light text-primary fw-normal"
                      title="<?php esc_attr_e('ID de billetera — usalo en el CSV de Importar movimientos', 'egc'); ?>">
                    #<?php echo esc_html(get_the_ID()); ?>
                </span>
            </h1>
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

        <hr class="my-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0"><?php esc_html_e('Movimientos', 'egc'); ?></h2>
            <?php if ($libro_manager->can_create(get_the_ID())) : ?>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?php echo esc_url($libro_manager->nuevo_url_for(get_the_ID())); ?>"
                   aria-label="<?php esc_attr_e('Agregar movimiento', 'egc'); ?>"
                   title="<?php esc_attr_e('Agregar movimiento', 'egc'); ?>">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>

        <?php $movimientos = $libro_manager->movimientos_de(get_the_ID()); ?>

        <?php if (empty($movimientos)) : ?>
            <p class="text-muted"><?php esc_html_e('Todavía no hay movimientos.', 'egc'); ?></p>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Fecha', 'egc'); ?></th>
                            <th scope="col"><?php esc_html_e('Descripción', 'egc'); ?></th>
                            <th scope="col"><?php esc_html_e('Categorización', 'egc'); ?></th>
                            <th scope="col"><?php esc_html_e('Referencia', 'egc'); ?></th>
                            <th scope="col" class="text-end"><?php esc_html_e('Monto', 'egc'); ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimientos as $movimiento) : ?>
                            <tr>
                                <td><?php echo esc_html($movimiento['fecha']); ?></td>
                                <td><?php echo esc_html($movimiento['title']); ?></td>
                                <td><?php echo esc_html($movimiento['categoria']); ?></td>
                                <td><?php echo esc_html($movimiento['referencia']); ?></td>
                                <td class="text-end <?php echo $movimiento['monto'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($movimiento['monto'], 2)); ?>
                                </td>
                                <td class="text-end">
                                    <?php
                                    $actions = $libro_manager->actions_for($movimiento['id']);
                                    if ($actions['can_edit'] || $actions['can_trash']) {
                                        // A diferencia del single de la propia billetera, acá
                                        // eliminar un movimiento no borra la página en la que
                                        // se está parado: se sigue viendo la misma billetera,
                                        // así que alcanza con el ?volver= implícito de
                                        // post-actions.php... pero como este listado no pasa
                                        // por un archive.php con su propio ?volver=, se lo
                                        // fijamos explícito igual, para quedarse acá y no caer
                                        // al back_url() por defecto de LibroManagement.
                                        $actions['redirect_to'] = get_permalink();
                                        include EGC_DIR . '/core/views/partials/post-actions.php';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endwhile; ?>
</article>
