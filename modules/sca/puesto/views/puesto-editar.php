<?php

use EGC\Modules\Sca\Puesto\PuestoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = PuestoManagement::get_instance()->view_state_editar();

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

                <div class="mb-3">
                    <label class="form-label" for="post_title"><?php esc_html_e('Nombre', 'egc'); ?></label>
                    <input class="form-control" type="text" id="post_title" name="post_title" required
                           value="<?php echo esc_attr($v['post_title'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="comite_id"><?php esc_html_e('Comité', 'egc'); ?></label>
                    <select class="form-select" id="comite_id" name="comite_id">
                        <option value="0" <?php selected($v['comite_id'] ?? '0', '0'); ?>>
                            <?php esc_html_e('Todos los comités', 'egc'); ?>
                        </option>
                        <?php foreach ($state['opciones']['comites'] as $id => $nombre) : ?>
                            <option value="<?php echo esc_attr($id); ?>" <?php selected($v['comite_id'] ?? '', (string) $id); ?>>
                                <?php echo esc_html($nombre); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-text"><?php esc_html_e('Un puesto con comité solo se puede ocupar en ese comité (por ejemplo, Presidente de la Junta Directiva).', 'egc'); ?></p>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="coordina" name="coordina" value="1"
                           <?php checked(!empty($v['coordina'])); ?>>
                    <label class="form-check-label" for="coordina"><?php esc_html_e('Coordina el comité', 'egc'); ?></label>
                    <p class="form-text mb-0"><?php esc_html_e('Quien ocupa este puesto ve todos los acuerdos de su comité.', 'egc'); ?></p>
                </div>

                <button type="submit" class="btn btn-primary"><?php esc_html_e('Guardar', 'egc'); ?></button>
            </form>
        </div>
    </div>
</div>
