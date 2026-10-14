<?php

use EGC\Modules\Sca\Miembro\MiembroManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = MiembroManagement::get_instance()->view_state_editar();

if (!$state) {
    return;
}

$v = $state['valores'];
$o = $state['opciones'];
?>
<div class="container py-5">
    <?php include EGC_DIR . '/modules/sca/views/partials/editar-encabezado.php'; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
                <?php include EGC_DIR . '/modules/sca/views/partials/editar-campos-ocultos.php'; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="comite_id"><?php esc_html_e('Comité', 'egc'); ?></label>
                        <select class="form-select" id="comite_id" name="comite_id" required>
                            <option value=""><?php esc_html_e('Seleccioná un comité', 'egc'); ?></option>
                            <?php foreach ($o['comites'] as $id => $nombre) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($v['comite_id'] ?? '', (string) $id); ?>>
                                    <?php echo esc_html($nombre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="puesto_id"><?php esc_html_e('Puesto', 'egc'); ?></label>
                        <select class="form-select" id="puesto_id" name="puesto_id" required>
                            <option value=""><?php esc_html_e('Seleccioná un puesto', 'egc'); ?></option>
                            <?php foreach ($o['puestos'] as $id => $nombre) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($v['puesto_id'] ?? '', (string) $id); ?>>
                                    <?php echo esc_html($nombre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6"><?php esc_html_e('Persona', 'egc'); ?></legend>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="user_id"><?php esc_html_e('Usuario existente', 'egc'); ?></label>
                            <select class="form-select" id="user_id" name="user_id">
                                <option value="0"><?php esc_html_e('— Ninguno —', 'egc'); ?></option>
                                <?php foreach ($o['usuarios'] as $id => $etiqueta) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($v['user_id'] ?? '0', (string) $id); ?>>
                                        <?php echo esc_html($etiqueta); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email"><?php esc_html_e('o correo de quien aún no tiene cuenta', 'egc'); ?></label>
                            <input class="form-control" type="email" id="email" name="email"
                                   value="<?php echo esc_attr($v['email'] ?? ''); ?>">
                        </div>
                    </div>
                    <p class="form-text mb-0">
                        <?php esc_html_e('Si elegís un usuario, el correo se ignora. Con un correo sin cuenta, la membresía queda enlazada y se activa sola cuando esa persona solicite su ingreso y sea aprobada.', 'egc'); ?>
                    </p>
                </fieldset>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="desde"><?php esc_html_e('Vigente desde', 'egc'); ?></label>
                        <input class="form-control" type="date" id="desde" name="desde" value="<?php echo esc_attr($v['desde'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="hasta"><?php esc_html_e('Vigente hasta', 'egc'); ?></label>
                        <input class="form-control" type="date" id="hasta" name="hasta" value="<?php echo esc_attr($v['hasta'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <p class="form-text mb-0">
                            <?php esc_html_e('La vigencia es obligatoria en los comités de alcance total (Junta Directiva): fuera de esas fechas la persona no ve acuerdos. Para reelegir a alguien, extendé estas fechas. Si cambia la persona del puesto, creá la membresía nueva y reasigná los acuerdos desde Herramientas.', 'egc'); ?>
                        </p>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"><?php esc_html_e('Guardar', 'egc'); ?></button>
            </form>
        </div>
    </div>
</div>
