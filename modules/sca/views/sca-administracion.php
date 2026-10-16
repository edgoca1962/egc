<?php

use EGC\Modules\Sca\Administracion;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = Administracion::get_instance()->view_state();
?>
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><?php esc_html_e('Administración de acuerdos', 'egc'); ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo esc_url($state['acuerdos_url']); ?>">
            <i class="bi bi-list-check" aria-hidden="true"></i>
            <?php esc_html_e('Ver acuerdos', 'egc'); ?>
        </a>
    </div>

    <ul class="nav nav-tabs mb-4">
        <?php foreach ($state['tabs'] as $tab) : ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab['activa'] ? 'active' : ''; ?>"
                   <?php echo $tab['activa'] ? 'aria-current="page"' : ''; ?>
                   href="<?php echo esc_url($tab['url']); ?>"><?php echo esc_html($tab['label']); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php $migas = $state['migas']; include EGC_DIR . '/modules/sca/views/partials/migas.php'; ?>

    <?php if ($state['ok']) : ?>
        <div class="alert alert-success">
            <?php
            if ($state['cantidad']) {
                printf(
                    /* translators: %s: cantidad de acuerdos reasignados */
                    esc_html(_n('Se reasignó %s acuerdo.', 'Se reasignaron %s acuerdos.', $state['cantidad'], 'egc')),
                    esc_html(number_format_i18n($state['cantidad']))
                );
            } else {
                esc_html_e('Cambios guardados.', 'egc');
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <?php if ($state['herramientas']) : ?>
        <?php $h = $state['herramientas']; ?>
        <div class="card">
            <div class="card-body">
                <h2 class="h5"><?php esc_html_e('Reasignar todos los acuerdos de una persona', 'egc'); ?></h2>
                <p class="text-muted">
                    <?php esc_html_e('Usala cuando cambia la persona que ocupa un puesto (por ejemplo, un nuevo Tesorero): todos los acuerdos de la persona de origen —también los ya ejecutados— pasan a la de destino. El destino debe tener una cuenta activa.', 'egc'); ?>
                </p>

                <?php if (empty($h['origenes'])) : ?>
                    <p class="mb-0"><?php esc_html_e('Todavía no hay acuerdos asignados a ninguna persona.', 'egc'); ?></p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url($h['form_action']); ?>" class="row g-3 align-items-end">
                        <?php wp_nonce_field($h['nonce_action'], $h['nonce_name']); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr($h['action']); ?>">

                        <div class="col-md-5">
                            <label class="form-label" for="de_user"><?php esc_html_e('De', 'egc'); ?></label>
                            <select class="form-select" id="de_user" name="de_user" required>
                                <option value=""><?php esc_html_e('Seleccioná una persona', 'egc'); ?></option>
                                <?php foreach ($h['origenes'] as $id => $etiqueta) : ?>
                                    <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($etiqueta); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="a_user"><?php esc_html_e('A', 'egc'); ?></label>
                            <select class="form-select" id="a_user" name="a_user" required>
                                <option value=""><?php esc_html_e('Seleccioná una persona', 'egc'); ?></option>
                                <?php foreach ($h['destinos'] as $id => $etiqueta) : ?>
                                    <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($etiqueta); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><?php esc_html_e('Reasignar', 'egc'); ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php else : ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0"><?php echo esc_html($state['titulo']); ?></h2>

            <?php if ($state['nuevo_url']) : ?>
                <a class="btn btn-primary btn-sm" href="<?php echo esc_url($state['nuevo_url']); ?>">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <?php echo esc_html($state['nuevo_etiqueta']); ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($state['modo'] === 'tarjetas') : ?>
            <?php if (empty($state['tarjetas'])) : ?>
                <p class="text-muted"><?php echo esc_html($state['vacio']); ?></p>
            <?php else : ?>
                <div class="row g-3">
                    <?php foreach ($state['tarjetas'] as $tarjeta) : ?>
                        <div class="col-12">
                            <?php include EGC_DIR . '/modules/sca/views/partials/tarjeta-recurso.php'; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php elseif (empty($state['filas'])) : ?>
            <p class="text-muted"><?php echo esc_html($state['vacio']); ?></p>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <?php foreach ($state['columnas'] as $columna) : ?>
                                <th scope="col"><?php echo esc_html($columna); ?></th>
                            <?php endforeach; ?>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($state['filas'] as $fila) : ?>
                            <tr>
                                <?php foreach ($fila['celdas'] as $celda) : ?>
                                    <td>
                                        <?php if (is_array($celda)) : ?>
                                            <span class="badge <?php echo esc_attr($celda['clase']); ?>"><?php echo esc_html($celda['texto']); ?></span>
                                        <?php else : ?>
                                            <?php echo esc_html($celda); ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-end text-nowrap">
                                    <?php if ($fila['acciones']) : ?>
                                        <?php $actions = $fila['acciones']; ?>
                                        <?php include EGC_DIR . '/core/views/partials/post-actions.php'; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <?php if ($state['paginacion']) : ?>
            <nav class="navigation pagination mt-4" aria-label="<?php esc_attr_e('Paginación', 'egc'); ?>">
                <div class="nav-links">
                    <?php
                    echo paginate_links([
                        'base'      => $state['paginacion']['base'],
                        'format'    => '',
                        'current'   => $state['paginacion']['actual'],
                        'total'     => $state['paginacion']['total'],
                        'prev_text' => __('« Anterior', 'egc'),
                        'next_text' => __('Siguiente »', 'egc'),
                    ]);
                    ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
