<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Vista — pantalla de wp-admin "Herramientas > Migración SGF".
 * La incluye MigracionManagement::render_page(), que ya resolvió
 * $estado: acá solo se pinta. Usa las clases nativas de wp-admin
 * (`wrap`, `notice`, `card`, `widefat`), no Bootstrap: esta pantalla
 * vive dentro de wp-admin, donde el Bootstrap compilado del front no
 * se encola.
 */

$etiquetas_grupo = [
    'categorias'   => __('Categorías', 'egc'),
    'billeteras'   => __('Billeteras', 'egc'),
    'movimientos'  => __('Movimientos', 'egc'),
    'presupuestos' => __('Presupuestos', 'egc'),
];

$etiquetas_contador = [
    'creadas'       => __('creadas', 'egc'),
    'creados'       => __('creados', 'egc'),
    'existentes'    => __('ya existían', 'egc'),
    'fallidas'      => __('fallidas', 'egc'),
    'rechazadas'    => __('rechazadas', 'egc'),
    'rechazados'    => __('rechazados', 'egc'),
    'omitidos'      => __('omitidos', 'egc'),
    'sin_categoria' => __('sin categoría', 'egc'),
];
?>
<div class="wrap">
    <h1><?php esc_html_e('Migración SGF', 'egc'); ?></h1>

    <?php if ($estado['error'] !== null) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($estado['error']); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($estado['resultado'] !== null) : ?>
        <?php $resultado = $estado['resultado']; ?>
        <div class="notice notice-success">
            <p><strong><?php esc_html_e('Importación terminada.', 'egc'); ?></strong></p>
        </div>

        <?php if ($resultado['omitidos'] > 0) : ?>
            <div class="notice notice-warning">
                <p>
                    <?php
                    printf(
                        /* translators: %d: cantidad de usuarios omitidos */
                        esc_html(_n(
                            'Se omitió %d usuario del archivo (ver el detalle abajo).',
                            'Se omitieron %d usuarios del archivo (ver el detalle abajo).',
                            $resultado['omitidos'],
                            'egc'
                        )),
                        (int) $resultado['omitidos']
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <table class="widefat striped" style="max-width: 1000px;">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Usuario', 'egc'); ?></th>
                    <?php foreach ($etiquetas_grupo as $etiqueta) : ?>
                        <th scope="col"><?php echo esc_html($etiqueta); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultado['usuarios'] as $fila) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html($fila['email'] !== '' ? $fila['email'] : __('(sin correo)', 'egc')); ?>
                            <?php if (!$fila['importado']) : ?>
                                <br><strong><?php esc_html_e('Omitido', 'egc'); ?></strong>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($etiquetas_grupo as $grupo => $etiqueta) : ?>
                            <td>
                                <?php foreach ($fila[$grupo] as $contador => $valor) : ?>
                                    <?php if ($valor > 0) : ?>
                                        <?php echo esc_html($valor . ' ' . $etiquetas_contador[$contador]); ?><br>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if (count($resultado['usuarios']) > 1) : ?>
                <tfoot>
                    <tr>
                        <th scope="row"><?php esc_html_e('Total', 'egc'); ?></th>
                        <?php foreach ($etiquetas_grupo as $grupo => $etiqueta) : ?>
                            <td>
                                <?php foreach (($resultado['totales'][$grupo] ?? []) as $contador => $valor) : ?>
                                    <?php if ($valor > 0) : ?>
                                        <?php echo esc_html($valor . ' ' . $etiquetas_contador[$contador]); ?><br>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>

        <?php if (!empty($resultado['avisos'])) : ?>
            <h2><?php esc_html_e('Detalle', 'egc'); ?></h2>
            <ul style="list-style: disc; padding-left: 1.5em; max-width: 1000px;">
                <?php foreach ($resultado['avisos'] as $aviso) : ?>
                    <li><?php echo esc_html($aviso); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($resultado['avisos_extra'] > 0) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: cantidad de mensajes que no se muestran */
                        esc_html__('… y %d mensajes más que no se muestran.', 'egc'),
                        (int) $resultado['avisos_extra']
                    );
                    ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card" style="max-width: 1000px;">
        <h2 class="title"><?php esc_html_e('Exportar', 'egc'); ?></h2>
        <p>
            <?php esc_html_e('Descarga un archivo .json con las billeteras, movimientos (con su categorización), categorías y presupuestos de SGF. Los usuarios viajan identificados por su correo electrónico.', 'egc'); ?>
        </p>

        <?php if (empty($estado['usuarios'])) : ?>
            <p><em><?php esc_html_e('Todavía no hay usuarios con datos de SGF.', 'egc'); ?></em></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url($estado['form_action']); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($estado['exportar']['accion']); ?>">
                <?php wp_nonce_field($estado['exportar']['nonce_action'], $estado['exportar']['nonce_name']); ?>

                <p>
                    <label>
                        <input type="radio" name="alcance" value="todos" checked>
                        <?php
                        printf(
                            /* translators: %d: cantidad de usuarios con datos */
                            esc_html__('Todos los usuarios con datos (%d)', 'egc'),
                            count($estado['usuarios'])
                        );
                        ?>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="radio" name="alcance" value="uno">
                        <?php esc_html_e('Solo un usuario:', 'egc'); ?>
                    </label>
                    <select name="usuario_id" aria-label="<?php esc_attr_e('Usuario a exportar', 'egc'); ?>">
                        <?php foreach ($estado['usuarios'] as $usuario) : ?>
                            <option value="<?php echo esc_attr($usuario['id']); ?>">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: 1: nombre, 2: correo, 3: billeteras, 4: movimientos */
                                    __('%1$s <%2$s> — %3$d billeteras, %4$d movimientos', 'egc'),
                                    $usuario['nombre'],
                                    $usuario['email'],
                                    $usuario['resumen']['billeteras'],
                                    $usuario['resumen']['movimientos']
                                ));
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <?php submit_button(__('Descargar exportación', 'egc'), 'primary', 'submit', false); ?>
            </form>
        <?php endif; ?>
    </div>

    <div class="card" style="max-width: 1000px;">
        <h2 class="title"><?php esc_html_e('Importar', 'egc'); ?></h2>
        <p>
            <?php esc_html_e('Sube un archivo exportado desde esta misma pantalla. Antes de importar:', 'egc'); ?>
        </p>
        <ul style="list-style: disc; padding-left: 1.5em;">
            <li><?php esc_html_e('Los usuarios del archivo tienen que existir en esta instalación (se buscan por correo). Los que no existan se omiten y se te avisa; nunca se crean cuentas.', 'egc'); ?></li>
            <li><?php esc_html_e('Es seguro repetirla: lo ya importado se reconoce y no se duplica, así que si se corta o falta un usuario podés volver a subir el mismo archivo.', 'egc'); ?></li>
            <li><?php esc_html_e('No se pisa ni se modifica nada existente: solo se agrega lo que falta.', 'egc'); ?></li>
        </ul>

        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($estado['form_action']); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($estado['importar']['accion']); ?>">
            <?php wp_nonce_field($estado['importar']['nonce_action'], $estado['importar']['nonce_name']); ?>

            <p>
                <label for="egc-migracion-archivo" class="screen-reader-text">
                    <?php esc_html_e('Archivo .json a importar', 'egc'); ?>
                </label>
                <input type="file" id="egc-migracion-archivo" name="archivo" accept=".json,application/json" required>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: tamaño máximo de subida, ej. "2 MB" */
                    esc_html__('Tamaño máximo de subida: %s.', 'egc'),
                    esc_html($estado['tamano_maximo'])
                );
                ?>
            </p>

            <?php submit_button(__('Importar', 'egc'), 'primary', 'submit', false); ?>
        </form>
    </div>
</div>
