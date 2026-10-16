<?php

use EGC\Modules\Sca\Comite\ComiteManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = ComiteManagement::get_instance()->view_state_editar();

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
                    <p class="form-text"><?php esc_html_e('Forma parte del título de todas sus actas, minutas y acuerdos.', 'egc'); ?></p>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="alcance_total" name="alcance_total" value="1"
                           <?php checked(!empty($v['alcance_total'])); ?>>
                    <label class="form-check-label" for="alcance_total">
                        <?php esc_html_e('Alcance total (Junta Directiva)', 'egc'); ?>
                    </label>
                    <p class="form-text mb-0">
                        <?php esc_html_e('Sus miembros con vigencia ven todos los acuerdos de todos los comités. Sus documentos se rotulan «Acta» en vez de «Minuta» y sus puestos exigen fechas de vigencia.', 'egc'); ?>
                    </p>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="puesto_coordinador"><?php esc_html_e('Puesto que coordina el comité', 'egc'); ?></label>
                    <select class="form-select" id="puesto_coordinador" name="puesto_coordinador">
                        <option value="0" <?php selected($v['puesto_coordinador'] ?? '0', '0'); ?>>
                            <?php esc_html_e('Sin coordinador', 'egc'); ?>
                        </option>
                        <?php foreach ($state['opciones']['puestos'] as $id => $nombre) : ?>
                            <option value="<?php echo esc_attr($id); ?>" <?php selected($v['puesto_coordinador'] ?? '', (string) $id); ?>>
                                <?php echo esc_html($nombre); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-text">
                        <?php esc_html_e('Quien ocupe este puesto en este comité (con una membresía) ve todos los acuerdos del comité y recibe los avisos de comentarios. Si cambia la persona, se cambia la membresía: el comité sigue coordinado por el mismo puesto. Aparecen los puestos sin comité y los propios de este comité.', 'egc'); ?>
                    </p>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="proximo_acta"><?php esc_html_e('Próximo número de acta o minuta', 'egc'); ?></label>
                        <input class="form-control" type="number" min="1" id="proximo_acta" name="proximo_acta"
                               value="<?php echo esc_attr($v['proximo_acta'] ?? '1'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="proximo_acuerdo"><?php esc_html_e('Próximo número de acuerdo', 'egc'); ?></label>
                        <input class="form-control" type="number" min="1" id="proximo_acuerdo" name="proximo_acuerdo"
                               value="<?php echo esc_attr($v['proximo_acuerdo'] ?? '1'); ?>">
                    </div>
                    <div class="col-12">
                        <p class="form-text mb-0">
                            <?php esc_html_e('Los números los asigna el sistema. Solo se cambian a mano tras importar datos históricos, para continuar después del último. Un número ya usado se omite automáticamente.', 'egc'); ?>
                        </p>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"><?php esc_html_e('Guardar', 'egc'); ?></button>
            </form>
        </div>
    </div>
</div>
