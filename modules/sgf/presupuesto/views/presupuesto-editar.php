<?php

use EGC\Modules\Sgf\Presupuesto\PresupuestoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = PresupuestoManagement::get_instance()->view_state_editar();

if (!$state) {
    return;
}

$año_actual = $state['editing']['año'] ?? (int) gmdate('Y');
?>
<div class="container py-5" style="max-width: 640px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <?php echo $state['editing']
                ? esc_html__('Editar presupuesto', 'egc')
                : esc_html__('Nuevo presupuesto', 'egc'); ?>
        </h1>
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
                <input type="hidden" name="action" value="egc_presupuesto_save">
                <?php if ($state['editing']) : ?>
                    <input type="hidden" name="post_id" value="<?php echo esc_attr($state['editing']['id']); ?>">
                <?php endif; ?>
                <?php // Adónde volver DESPUÉS de guardar (no el Referer de este
                // POST, que va a ser esta misma pantalla) — ver
                // PresupuestoManagement::redirect_target(). ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['back_url']); ?>">

                <div class="mb-3">
                    <label class="form-label" for="categoria_id"><?php esc_html_e('Categoría', 'egc'); ?></label>
                    <select class="form-select" id="categoria_id" name="categoria_id" required>
                        <option value="0"><?php esc_html_e('— Elegir —', 'egc'); ?></option>
                        <?php foreach ($state['categoria_opciones'] as $categoria) : ?>
                            <option value="<?php echo esc_attr($categoria['id']); ?>"
                                <?php selected($state['editing']['categoria_id'] ?? 0, $categoria['id']); ?>>
                                <?php echo esc_html(str_repeat('— ', $categoria['profundidad']) . $categoria['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="monto"><?php esc_html_e('Monto mensual', 'egc'); ?></label>
                    <input class="form-control" type="number" step="0.01" min="0.01" id="monto" name="monto"
                           value="<?php echo esc_attr($state['editing']['monto'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="moneda"><?php esc_html_e('Moneda', 'egc'); ?></label>
                    <select class="form-select" id="moneda" name="moneda">
                        <?php foreach ($state['moneda_opciones'] as $valor => $etiqueta) : ?>
                            <option value="<?php echo esc_attr($valor); ?>"
                                <?php selected($state['editing']['moneda'] ?? array_key_first($state['moneda_opciones']), $valor); ?>>
                                <?php echo esc_html($etiqueta); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="anio"><?php esc_html_e('Año', 'egc'); ?></label>
                    <input class="form-control" type="number" step="1" min="2000" max="2099" id="anio" name="anio"
                           value="<?php echo esc_attr($año_actual); ?>">
                </div>

                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Guardar', 'egc'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
