<?php

use EGC\Modules\Sca\Acta\ActaManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = ActaManagement::get_instance()->view_state_editar();

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

                <?php if ($state['editing']) : ?>
                    <?php // El comité no se cambia: de él dependen el número y el título. ?>
                    <p class="mb-3">
                        <strong><?php echo esc_html($v['titulo'] ?? ''); ?></strong>
                    </p>
                <?php else : ?>
                    <div class="mb-3">
                        <label class="form-label" for="comite_id"><?php esc_html_e('Comité', 'egc'); ?></label>
                        <select class="form-select" id="comite_id" name="comite_id" required>
                            <option value=""><?php esc_html_e('Seleccioná un comité', 'egc'); ?></option>
                            <?php foreach ($state['opciones']['comites'] as $id => $nombre) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($v['comite_id'] ?? '', (string) $id); ?>>
                                    <?php echo esc_html($nombre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-text"><?php esc_html_e('El número y el título se asignan automáticamente al guardar.', 'egc'); ?></p>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label" for="fecha_reunion"><?php esc_html_e('Fecha de la reunión', 'egc'); ?></label>
                    <input class="form-control" type="date" id="fecha_reunion" name="fecha_reunion" required
                           max="<?php echo esc_attr($state['hoy']); ?>"
                           value="<?php echo esc_attr($v['fecha_reunion'] ?? ''); ?>">
                    <p class="form-text"><?php esc_html_e('También es la fecha de todos sus acuerdos; si la cambiás, se actualizan.', 'egc'); ?></p>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="post_content"><?php esc_html_e('Contenido', 'egc'); ?></label>
                    <?php
                    wp_editor($v['post_content'] ?? '', 'post_content', [
                        'textarea_name' => 'post_content',
                        'media_buttons' => false,
                        'textarea_rows' => 14,
                    ]);
                    ?>
                </div>

                <button type="submit" class="btn btn-primary"><?php esc_html_e('Guardar', 'egc'); ?></button>
            </form>
        </div>
    </div>
</div>
