<?php

use EGC\Modules\Sgf\Libro\LibroManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Vista de "Mantenimiento de movimientos" — categorización masiva.
 * Dos formularios separados a propósito, no uno anidado dentro del
 * otro (HTML no permite `<form>` dentro de `<form>`):
 *
 * 1. Filtro (GET, sin nonce: no escribe nada, solo cambia qué se
 *    lista — mismo criterio que el filtro Año/Mes de Presupuesto).
 * 2. Resultado + recategorización (POST hacia admin-post.php), con
 *    los filtros actuales viajando como querystring dentro de la
 *    propia URL de la página (no hacen falta como campos ocultos: el
 *    listado ya se pidió con esos filtros al cargar esta vista) más
 *    el `redirect_to` para volver a este mismo listado filtrado
 *    después de aplicar el cambio.
 *
 * Todo lo que aparece acá ya viene resuelto por
 * LibroManagement::view_state_mantenimiento() — esta vista no
 * consulta nada ni decide nada, solo pinta.
 */
$manager = LibroManagement::get_instance();
$state   = $manager->view_state_mantenimiento();
$filtros = $state['filtros'];
?>
<div class="container py-5">
    <h1 class="h3 mb-4"><?php esc_html_e('Mantenimiento de movimientos', 'egc'); ?></h1>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success">
            <?php
            printf(
                /* translators: %d: cantidad de movimientos recategorizados */
                esc_html__('Se recategorizaron %d movimientos.', 'egc'),
                (int) $state['recategorizados']
            );
            ?>
        </div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label" for="billetera_id"><?php esc_html_e('Billetera', 'egc'); ?></label>
                    <select class="form-select" id="billetera_id" name="billetera_id">
                        <option value="0"><?php esc_html_e('Todas mis billeteras', 'egc'); ?></option>
                        <?php foreach ($state['billetera_opciones'] as $billetera_id => $titulo) : ?>
                            <option value="<?php echo esc_attr($billetera_id); ?>"
                                <?php selected($filtros['billetera_id'], $billetera_id); ?>>
                                <?php echo esc_html($titulo); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="fecha_desde"><?php esc_html_e('Fecha desde', 'egc'); ?></label>
                    <input class="form-control" type="date" id="fecha_desde" name="fecha_desde"
                           value="<?php echo esc_attr($filtros['fecha_desde']); ?>">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="fecha_hasta"><?php esc_html_e('Fecha hasta', 'egc'); ?></label>
                    <input class="form-control" type="date" id="fecha_hasta" name="fecha_hasta"
                           value="<?php echo esc_attr($filtros['fecha_hasta']); ?>">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="monto_desde"><?php esc_html_e('Monto desde', 'egc'); ?></label>
                    <input class="form-control" type="number" step="0.01" min="0" id="monto_desde" name="monto_desde"
                           value="<?php echo esc_attr($filtros['monto_desde']); ?>">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label class="form-label" for="monto_hasta"><?php esc_html_e('Monto hasta', 'egc'); ?></label>
                    <input class="form-control" type="number" step="0.01" min="0" id="monto_hasta" name="monto_hasta"
                           value="<?php echo esc_attr($filtros['monto_hasta']); ?>">
                </div>

                <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="categoria_filtro"><?php esc_html_e('Categorización', 'egc'); ?></label>
                    <select class="form-select" id="categoria_filtro" name="categoria_filtro">
                        <?php foreach ($state['categoria_opciones_filtro'] as $categoria) : ?>
                            <option value="<?php echo esc_attr($categoria['id']); ?>"
                                <?php selected($filtros['categoria_filtro'], $categoria['id']); ?>>
                                <?php echo esc_html(str_repeat('— ', $categoria['profundidad']) . $categoria['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <label class="form-label" for="texto"><?php esc_html_e('La descripción contiene', 'egc'); ?></label>
                    <input class="form-control" type="text" id="texto" name="texto"
                           value="<?php echo esc_attr($filtros['texto']); ?>">
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        <?php esc_html_e('Filtrar', 'egc'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($state['movimientos'])) : ?>
        <p class="text-muted"><?php esc_html_e('No hay movimientos que coincidan con esos filtros.', 'egc'); ?></p>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
            <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($state['nonce_action']); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['redirect_to']); ?>">

            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 1%;"></th>
                            <th scope="col"><?php esc_html_e('Billetera', 'egc'); ?></th>
                            <th scope="col"><?php esc_html_e('Fecha', 'egc'); ?></th>
                            <th scope="col"><?php esc_html_e('Descripción', 'egc'); ?></th>
                            <th scope="col"><?php esc_html_e('Categorización actual', 'egc'); ?></th>
                            <th scope="col" class="text-end"><?php esc_html_e('Monto', 'egc'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($state['movimientos'] as $movimiento) : ?>
                            <tr>
                                <td>
                                    <input class="form-check-input" type="checkbox" name="movimiento_ids[]"
                                           value="<?php echo esc_attr($movimiento['id']); ?>" checked>
                                </td>
                                <td><?php echo esc_html($movimiento['billetera_title']); ?></td>
                                <td><?php echo esc_html($movimiento['fecha']); ?></td>
                                <td><?php echo esc_html($movimiento['titulo']); ?></td>
                                <td>
                                    <?php if ($movimiento['categoria'] !== '') : ?>
                                        <?php echo esc_html($movimiento['categoria']); ?>
                                    <?php else : ?>
                                        <span class="text-muted"><?php esc_html_e('— Sin categorización —', 'egc'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?php echo $movimiento['monto'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo esc_html(number_format_i18n($movimiento['monto'], 2)); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <div class="card-body d-flex flex-wrap gap-2 align-items-end">
                    <div class="flex-grow-1" style="max-width: 360px;">
                        <label class="form-label" for="categoria_id"><?php esc_html_e('Nueva categorización', 'egc'); ?></label>
                        <?php // Sin opción de "quitar categorización": Edwin fue
                        // explícito en que esta pantalla siempre ASIGNA — el
                        // placeholder no es una opción válida, solo obliga a
                        // elegir una categoría real antes de poder aplicar (ver
                        // LibroManagement::handle_recategorizar()). ?>
                        <select class="form-select" id="categoria_id" name="categoria_id" required>
                            <option value="" disabled selected>
                                <?php esc_html_e('Seleccionar Categorización', 'egc'); ?>
                            </option>
                            <?php foreach ($state['categoria_opciones_destino'] as $categoria) : ?>
                                <option value="<?php echo esc_attr($categoria['id']); ?>">
                                    <?php echo esc_html(str_repeat('— ', $categoria['profundidad']) . $categoria['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?php esc_html_e('Aplicar a los movimientos tildados', 'egc'); ?>
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>
