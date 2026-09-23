<?php

use EGC\Modules\Sgf\Presupuesto\PresupuestoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$manager = PresupuestoManagement::get_instance();
$state   = $manager->view_state_listado();
?>
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0"><?php esc_html_e('Mi presupuesto', 'egc'); ?></h1>

        <?php if ($state['can_create']) : ?>
            <a class="btn btn-primary"
               href="<?php echo esc_url($manager->with_return_here($manager->url_editar())); ?>"
               aria-label="<?php esc_attr_e('Agregar presupuesto', 'egc'); ?>"
               title="<?php esc_attr_e('Agregar presupuesto', 'egc'); ?>">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <?php // Form GET, sin nonce: no escribe nada, solo cambia qué se lista
    // — mismo criterio que el filtro de usuario de Billetera. ?>
    <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-4">
        <div>
            <label class="form-label" for="anio"><?php esc_html_e('Año', 'egc'); ?></label>
            <select class="form-select form-select-sm" id="anio" name="anio">
                <?php foreach ($state['año_opciones'] as $año => $etiqueta) : ?>
                    <option value="<?php echo esc_attr($año); ?>" <?php selected($state['año'], $año); ?>>
                        <?php echo esc_html($etiqueta); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label" for="mes"><?php esc_html_e('Acumulado hasta', 'egc'); ?></label>
            <select class="form-select form-select-sm" id="mes" name="mes">
                <?php foreach ($state['mes_opciones'] as $mes => $etiqueta) : ?>
                    <option value="<?php echo esc_attr($mes); ?>" <?php selected($state['mes'], $mes); ?>>
                        <?php echo esc_html($etiqueta); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($state['puede_filtrar_usuario'] && !empty($state['usuario_opciones'])) : ?>
            <div class="flex-grow-1" style="max-width: 280px;">
                <label class="form-label" for="usuario"><?php esc_html_e('Usuario', 'egc'); ?></label>
                <select class="form-select form-select-sm" id="usuario" name="usuario">
                    <?php foreach ($state['usuario_opciones'] as $user_id => $email) : ?>
                        <option value="<?php echo esc_attr($user_id); ?>" <?php selected($state['usuario_seleccionado'], $user_id); ?>>
                            <?php echo esc_html($email); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-sm btn-outline-secondary"
                aria-label="<?php esc_attr_e('Filtrar', 'egc'); ?>"
                title="<?php esc_attr_e('Filtrar', 'egc'); ?>">
            <i class="bi bi-funnel" aria-hidden="true"></i>
        </button>
    </form>

    <?php if (empty($state['monedas'])) : ?>
        <p><?php esc_html_e('Todavía no hay presupuesto cargado para este año.', 'egc'); ?></p>
    <?php endif; ?>

    <?php foreach ($state['monedas'] as $reporte) : ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong><?php echo esc_html($reporte['etiqueta']); ?></strong>
                    <div class="text-muted small">
                        <?php
                        printf(
                            /* translators: 1: mes hasta el que se acumula, 2: año */
                            esc_html__('Acumulado a %1$s de %2$d', 'egc'),
                            esc_html($state['mes_opciones'][$state['mes']] ?? ''),
                            (int) $state['año']
                        );
                        ?>
                    </div>
                </div>

                <?php // La diferencia se redondea a 2 decimales desde
                // PresupuestoManagement::monedas_de(), así que compararla
                // contra 0.0 acá es seguro — mismo criterio de precisión
                // que ya usa el resto del módulo (ver Libro::guardar_saldo()). ?>
                <?php if ((float) $reporte['diferencia'] !== 0.0) : ?>
                    <span class="badge text-bg-danger">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <?php
                        printf(
                            /* translators: %s: diferencia entre Ingresos y Egresos, con signo */
                            esc_html__('Presupuesto desbalanceado: la diferencia entre Ingresos y Egresos es %s, debería ser 0.', 'egc'),
                            esc_html(number_format_i18n($reporte['diferencia'], 2))
                        );
                        ?>
                    </span>
                <?php else : ?>
                    <span class="badge text-bg-success">
                        <?php esc_html_e('Presupuesto balanceado.', 'egc'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php foreach ($reporte['grupos'] as $grupo) : ?>
                    <h2 class="h6 mt-3"><?php echo esc_html($grupo['nombre']); ?></h2>
                    <table class="table table-sm align-middle">
                        <tbody>
                            <?php foreach ($grupo['filas'] as $fila) : ?>
                                <tr>
                                    <td><?php echo esc_html($fila['categoria']); ?></td>
                                    <td class="text-end"><?php echo esc_html(number_format_i18n($fila['monto'], 2)); ?></td>
                                    <td class="text-end" style="width: 1%;">
                                        <?php
                                        $actions = $manager->actions_for($fila['id']);
                                        if ($actions['can_edit'] || $actions['can_trash']) {
                                            $actions['redirect_to'] = $state['listado_url'];
                                            include EGC_DIR . '/core/views/partials/post-actions.php';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light fw-bold">
                                <td><?php esc_html_e('Subtotal', 'egc'); ?></td>
                                <td class="text-end"><?php echo esc_html(number_format_i18n($grupo['subtotal'], 2)); ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
