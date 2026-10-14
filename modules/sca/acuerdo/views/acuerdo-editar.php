<?php

use EGC\Modules\Sca\Acuerdo\AcuerdoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = AcuerdoManagement::get_instance()->view_state_editar();

if (!$state) {
    return;
}

$v = $state['valores'];
?>
<div class="container py-5">
    <?php include EGC_DIR . '/modules/sca/views/partials/editar-encabezado.php'; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
                <?php include EGC_DIR . '/modules/sca/views/partials/editar-campos-ocultos.php'; ?>
                <input type="hidden" name="acta_id" value="<?php echo esc_attr($v['acta_id'] ?? ''); ?>">

                <p class="mb-1 text-muted"><?php esc_html_e('Acta o minuta', 'egc'); ?></p>
                <p class="mb-3"><strong><?php echo esc_html($v['acta_titulo'] ?? ''); ?></strong></p>

                <?php if ($state['editing']) : ?>
                    <p class="mb-3 text-muted"><?php echo esc_html($v['titulo'] ?? ''); ?></p>
                <?php else : ?>
                    <p class="form-text"><?php esc_html_e('El número y el título se asignan automáticamente al guardar, y la fecha es la de la reunión.', 'egc'); ?></p>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label" for="post_content"><?php esc_html_e('Acuerdo', 'egc'); ?></label>
                    <?php
                    wp_editor($v['post_content'] ?? '', 'post_content', [
                        'textarea_name' => 'post_content',
                        'media_buttons' => false,
                        'textarea_rows' => 10,
                    ]);
                    ?>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="fecha_compromiso"><?php esc_html_e('Fecha de compromiso', 'egc'); ?></label>
                        <input class="form-control" type="date" id="fecha_compromiso" name="fecha_compromiso" required
                               value="<?php echo esc_attr($v['fecha_compromiso'] ?? ''); ?>">
                        <p class="form-text mb-0"><?php esc_html_e('Vence cuando pasa este día.', 'egc'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="fecha_ejecucion"><?php esc_html_e('Fecha de ejecución', 'egc'); ?></label>
                        <input class="form-control" type="date" id="fecha_ejecucion" name="fecha_ejecucion"
                               max="<?php echo esc_attr($state['hoy']); ?>"
                               value="<?php echo esc_attr($v['fecha_ejecucion'] ?? ''); ?>">
                        <p class="form-text mb-0"><?php esc_html_e('Dejala vacía mientras el acuerdo no esté ejecutado.', 'egc'); ?></p>
                    </div>
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6"><?php esc_html_e('Asignado a', 'egc'); ?></legend>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="asignado_user"><?php esc_html_e('Usuario', 'egc'); ?></label>
                            <select class="form-select" id="asignado_user" name="asignado_user">
                                <option value="0"><?php esc_html_e('— Sin asignar —', 'egc'); ?></option>
                                <?php foreach ($state['opciones']['usuarios'] as $id => $etiqueta) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($v['asignado_user'] ?? '0', (string) $id); ?>>
                                        <?php echo esc_html($etiqueta); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="asignado_email"><?php esc_html_e('o correo de quien aún no tiene cuenta', 'egc'); ?></label>
                            <input class="form-control" type="email" id="asignado_email" name="asignado_email"
                                   value="<?php echo esc_attr($v['asignado_email'] ?? ''); ?>">
                        </div>
                    </div>
                    <p class="form-text mb-0">
                        <?php esc_html_e('Con un correo sin cuenta se le envía una invitación para solicitar su ingreso; el acuerdo queda a su nombre cuando lo aprueben. Para quitar la asignación, dejá ambos campos vacíos.', 'egc'); ?>
                    </p>
                </fieldset>

                <button type="submit" class="btn btn-primary"><?php esc_html_e('Guardar', 'egc'); ?></button>
            </form>
        </div>
    </div>
</div>
