<?php

use EGC\Modules\Sgf\CategoriaManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$manager = CategoriaManagement::get_instance();
$state   = $manager->view_state();
?>
<div class="container py-5" style="max-width: 720px;">
    <h1 class="h3 mb-4"><?php esc_html_e('Mis categorías', 'egc'); ?></h1>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <?php if ($state['editando']) : ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?php esc_html_e('Renombrar categoría', 'egc'); ?></strong>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url($state['cancelar_url']); ?>">
                    <?php esc_html_e('Cancelar', 'egc'); ?>
                </a>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
                    <?php wp_nonce_field($state['action_renombrar'], $state['nonce_name']); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr($state['action_renombrar']); ?>">
                    <input type="hidden" name="term_id" value="<?php echo esc_attr($state['editando']['id']); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['cancelar_url']); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="nombre_renombrar"><?php esc_html_e('Nuevo nombre', 'egc'); ?></label>
                        <input class="form-control" type="text" id="nombre_renombrar" name="nombre"
                               value="<?php echo esc_attr($state['editando']['nombre']); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php esc_html_e('Guardar', 'egc'); ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><strong><?php esc_html_e('Agregar categoría', 'egc'); ?></strong></div>
        <div class="card-body">
            <?php if (empty($state['padre_opciones'])) : ?>
                <p class="text-muted mb-0">
                    <?php esc_html_e('Todavía no hay ninguna categoría base para colgar una nueva.', 'egc'); ?>
                </p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url($state['form_action']); ?>" class="row g-2 align-items-end">
                    <?php wp_nonce_field($state['action_crear'], $state['nonce_name']); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr($state['action_crear']); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['cancelar_url']); ?>">
                    <div class="col-sm-5">
                        <label class="form-label" for="padre_id"><?php esc_html_e('Dentro de', 'egc'); ?></label>
                        <select class="form-select" id="padre_id" name="padre_id" required>
                            <?php foreach ($state['padre_opciones'] as $padre) : ?>
                                <option value="<?php echo esc_attr($padre['id']); ?>">
                                    <?php echo esc_html(str_repeat('— ', $padre['profundidad']) . $padre['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label" for="nombre_nueva"><?php esc_html_e('Nombre', 'egc'); ?></label>
                        <input class="form-control" type="text" id="nombre_nueva" name="nombre" required>
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100"><?php esc_html_e('Agregar', 'egc'); ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($state['filas'])) : ?>
        <p><?php esc_html_e('Todavía no tenés categorías propias.', 'egc'); ?></p>
    <?php else : ?>
        <table class="table align-middle">
            <tbody>
                <?php foreach ($state['filas'] as $fila) : ?>
                    <tr>
                        <td>
                            <?php if ($fila['es_tipo']) : ?>
                                <strong><?php echo esc_html($fila['nombre']); ?></strong>
                            <?php else : ?>
                                <?php echo esc_html(str_repeat('— ', $fila['profundidad']) . $fila['nombre']); ?>
                                <?php if ($fila['uso'] > 0) : ?>
                                    <span class="badge text-bg-secondary ms-1">
                                        <?php
                                        printf(
                                            /* translators: %d: cantidad de movimientos/presupuestos que usan esta categoría */
                                            esc_html__('en uso (%d)', 'egc'),
                                            (int) $fila['uso']
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="width: 1%; white-space: nowrap;">
                            <?php if (!$fila['es_tipo']) : ?>
                                <?php include EGC_DIR . '/modules/sgf/views/partials/categoria-actions.php'; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
