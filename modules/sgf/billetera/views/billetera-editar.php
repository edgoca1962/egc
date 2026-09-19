<?php

use EGC\Modules\Sgf\Billetera\BilleteraManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = BilleteraManagement::get_instance()->view_state_editar();

if (!$state) {
    return;
}

$moneda_actual = $state['editing']['moneda'] ?? array_key_first($state['moneda_opciones']);
?>
<div class="container py-5" style="max-width: 640px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <?php echo $state['editing']
                ? esc_html__('Editar billetera', 'egc')
                : esc_html__('Nueva billetera', 'egc'); ?>
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
                <input type="hidden" name="action" value="egc_billetera_save">
                <?php if ($state['editing']) : ?>
                    <input type="hidden" name="post_id" value="<?php echo esc_attr($state['editing']['id']); ?>">
                <?php endif; ?>
                <?php // Adónde volver DESPUÉS de guardar (no el Referer de este
                // POST, que va a ser esta misma pantalla) — ver
                // BilleteraManagement::redirect_target(). ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['back_url']); ?>">

                <div class="mb-3">
                    <label class="form-label" for="post_title"><?php esc_html_e('Nombre', 'egc'); ?></label>
                    <input class="form-control" type="text" id="post_title" name="post_title"
                           value="<?php echo esc_attr($state['editing']['title'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="saldo"><?php esc_html_e('Saldo', 'egc'); ?></label>
                    <input class="form-control" type="number" step="0.01" id="saldo" name="saldo"
                           value="<?php echo esc_attr($state['editing']['saldo'] ?? '0'); ?>">
                    <p class="form-text">
                        <?php esc_html_e('Un valor negativo es válido: por ejemplo, una cuenta sobregirada o el saldo pendiente de una tarjeta de crédito.', 'egc'); ?>
                    </p>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="moneda"><?php esc_html_e('Moneda', 'egc'); ?></label>
                    <select class="form-select" id="moneda" name="moneda">
                        <?php foreach ($state['moneda_opciones'] as $valor => $etiqueta) : ?>
                            <option value="<?php echo esc_attr($valor); ?>" <?php selected($moneda_actual, $valor); ?>>
                                <?php echo esc_html($etiqueta); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php // El estatus no es una opción del formulario: se decide en
                // el servidor según las facultades de quien guarda (ver
                // BilleteraManagement::handle_save()). Esto es solo
                // informativo, mismo criterio que ya aplica Blog. ?>
                <?php if ($state['can_publish']) : ?>
                    <p class="form-text"><?php esc_html_e('Se guardará directamente.', 'egc'); ?></p>
                <?php else : ?>
                    <p class="form-text"><?php esc_html_e('Tu billetera queda pendiente de revisión.', 'egc'); ?></p>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Guardar', 'egc'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
