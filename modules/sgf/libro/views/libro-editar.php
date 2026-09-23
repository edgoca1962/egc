<?php

use EGC\Modules\Sgf\Libro\LibroManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = LibroManagement::get_instance()->view_state_editar();

if (!$state) {
    return;
}

$fecha_actual = $state['editing']['fecha'] ?? gmdate('Y-m-d');
?>
<div class="container py-5" style="max-width: 640px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">
                <?php echo $state['editing']
                    ? esc_html__('Editar movimiento', 'egc')
                    : esc_html__('Nuevo movimiento', 'egc'); ?>
            </h1>
            <p class="text-muted mb-0"><?php echo esc_html($state['billetera_title']); ?></p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo esc_url($state['back_url']); ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <?php esc_html_e('Regresar', 'egc'); ?>
        </a>
    </div>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
                <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
                <input type="hidden" name="action" value="egc_libro_save">
                <input type="hidden" name="billetera_id" value="<?php echo esc_attr($state['billetera_id']); ?>">
                <?php if ($state['editing']) : ?>
                    <input type="hidden" name="post_id" value="<?php echo esc_attr($state['editing']['id']); ?>">
                <?php endif; ?>
                <?php // Adónde volver DESPUÉS de guardar (no el Referer de este
                // POST, que va a ser esta misma pantalla) — ver
                // LibroManagement::redirect_target(). ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['back_url']); ?>">

                <div class="mb-3">
                    <label class="form-label" for="post_title"><?php esc_html_e('Descripción', 'egc'); ?></label>
                    <input class="form-control" type="text" id="post_title" name="post_title"
                           value="<?php echo esc_attr($state['editing']['title'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="fecha"><?php esc_html_e('Fecha', 'egc'); ?></label>
                    <input class="form-control" type="date" id="fecha" name="fecha"
                           value="<?php echo esc_attr($fecha_actual); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="monto"><?php esc_html_e('Monto', 'egc'); ?></label>
                    <input class="form-control" type="number" step="0.01" id="monto" name="monto"
                           value="<?php echo esc_attr($state['editing']['monto'] ?? ''); ?>">
                    <p class="form-text">
                        <?php esc_html_e('Positivo si es un ingreso, negativo si es un egreso.', 'egc'); ?>
                    </p>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="categoria_id"><?php esc_html_e('Categoría', 'egc'); ?></label>
                    <select class="form-select" id="categoria_id" name="categoria_id">
                        <option value="0"><?php esc_html_e('— Sin categoría —', 'egc'); ?></option>
                        <?php foreach ($state['categoria_opciones'] as $categoria) : ?>
                            <option value="<?php echo esc_attr($categoria['id']); ?>"
                                <?php selected($state['editing']['categoria_id'] ?? 0, $categoria['id']); ?>>
                                <?php echo esc_html(str_repeat('— ', $categoria['profundidad']) . $categoria['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="referencia"><?php esc_html_e('Referencia', 'egc'); ?></label>
                    <input class="form-control" type="text" id="referencia" name="referencia"
                           value="<?php echo esc_attr($state['editing']['referencia'] ?? ''); ?>">
                </div>

                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Guardar', 'egc'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
