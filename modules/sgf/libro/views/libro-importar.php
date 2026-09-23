<?php

use EGC\Modules\Sgf\Libro\LibroImportacion;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Vista de "Importar movimientos" — formulario de subida (con
 * enctype="multipart/form-data", el único de todo el módulo que lo
 * necesita) + el resultado de la última carga, si lo hay (ver
 * LibroImportacion::view_state()). Nada de HTML condicional decidido
 * acá: todo lo que se pinta ya viene resuelto por esa clase.
 */
$manager = LibroImportacion::get_instance();
$state   = $manager->view_state();
?>
<div class="container py-5" style="max-width: 720px;">
    <h1 class="h3 mb-4"><?php esc_html_e('Importar movimientos', 'egc'); ?></h1>

    <?php if ($state['resultado']) : ?>
        <?php $resultado = $state['resultado']; ?>

        <?php if ($resultado['error_archivo']) : ?>
            <div class="alert alert-danger"><?php echo esc_html($resultado['error_archivo']); ?></div>
        <?php endif; ?>

        <?php if ((int) $resultado['insertados'] > 0) : ?>
            <div class="alert alert-success">
                <?php
                printf(
                    /* translators: %d: cantidad de movimientos insertados */
                    esc_html__('Se insertaron %d movimientos.', 'egc'),
                    (int) $resultado['insertados']
                );
                ?>
            </div>
        <?php endif; ?>

        <?php
        /**
         * Filas rechazadas AGRUPADAS por tipo de error (Edwin lo pidió
         * así): cada grupo trae su propio mensaje una sola vez y la
         * lista de números de fila, en vez de repetir el mismo motivo
         * fila por fila suelta en el orden del archivo.
         */
        ?>
        <?php if (!empty($resultado['grupos'])) : ?>
            <div class="alert alert-danger">
                <p class="mb-2"><?php esc_html_e('Estas filas no se importaron:', 'egc'); ?></p>
                <ul class="mb-0">
                    <?php foreach ($resultado['grupos'] as $grupo) : ?>
                        <li>
                            <?php echo esc_html($grupo['mensaje']); ?>
                            <?php
                            printf(
                                /* translators: %s: números de fila separados por coma */
                                esc_html__(' Filas: %s.', 'egc'),
                                esc_html(implode(', ', $grupo['filas']))
                            );
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php
        /**
         * "Billetera no existe" queda aparte de los grupos de arriba
         * a propósito (Edwin lo pidió explícito): no se lista fila por
         * fila — si el archivo tiene la columna ID Billetera mal desde
         * el vamos, eso puede afectar a TODAS las filas, y listarlas
         * una por una sería puro ruido. Queda como una única alerta
         * con el total y los IDs que no se pudieron resolver.
         */
        ?>
        <?php if (!empty($resultado['billeteras_invalidas'])) : ?>
            <div class="alert alert-warning">
                <?php
                printf(
                    /* translators: 1: cantidad de filas afectadas, 2: IDs de billetera sin resolver, separados por coma */
                    esc_html__('%1$d filas no se procesaron porque el ID de billetera no existe o no es tuyo (ID: %2$s).', 'egc'),
                    (int) $resultado['billeteras_invalidas']['cantidad'],
                    esc_html(implode(', ', $resultado['billeteras_invalidas']['ids']))
                );
                ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <p class="mb-2"><?php esc_html_e('El archivo tiene que ser un CSV con estas columnas (en cualquier orden):', 'egc'); ?></p>
            <ul>
                <li>
                    <strong><?php esc_html_e('ID Billetera', 'egc'); ?></strong> —
                    <?php esc_html_e('el número que ves junto al nombre de cada billetera, en el listado y en su detalle. Tiene que ser una billetera tuya.', 'egc'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Fecha', 'egc'); ?></strong> —
                    <?php esc_html_e('formato AAAA-MM-DD (por ejemplo, 2026-09-23). Cualquier otro formato se rechaza.', 'egc'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Descripción', 'egc'); ?></strong> —
                    <?php esc_html_e('no puede quedar vacía.', 'egc'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Debe', 'egc'); ?></strong> <?php esc_html_e('y', 'egc'); ?>
                    <strong><?php esc_html_e('Haber', 'egc'); ?></strong> —
                    <?php esc_html_e('ambos positivos (o vacíos); el monto del movimiento se calcula como Haber menos Debe.', 'egc'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Referencia', 'egc'); ?></strong> —
                    <?php esc_html_e('opcional.', 'egc'); ?>
                </li>
            </ul>

            <form method="post" action="<?php echo esc_url($state['form_action']); ?>" enctype="multipart/form-data" class="mt-3">
                <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($state['nonce_action']); ?>">

                <div class="mb-3">
                    <label class="form-label" for="archivo"><?php esc_html_e('Archivo CSV', 'egc'); ?></label>
                    <input class="form-control" type="file" id="archivo" name="archivo" accept=".csv,text/csv" required>
                </div>

                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Importar', 'egc'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
