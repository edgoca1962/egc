<?php

use EGC\Modules\Sca\Acuerdo\Listado;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Listado de acuerdos (CPT acuerdo, archivo nativo /acuerdo/ y búsqueda
 * `?post_type=acuerdo&s=`). Todo lo que pinta lo resolvió
 * Listado::view_state_archive(); acá no hay consultas ni reglas de
 * acceso (Consulta ya limitó los acuerdos que llegan). Con `?sca_acta=`
 * es el tercer nivel del mantenimiento (migas + agregar acuerdo).
 */
$state = Listado::get_instance()->view_state_archive();
?>
<div class="container py-5">
    <?php if ($state['contexto']) : ?>
        <?php $migas = $state['contexto']['migas']; include EGC_DIR . '/modules/sca/views/partials/migas.php'; ?>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><?php esc_html_e('Acuerdos', 'egc'); ?></h1>
        <?php if ($state['admin_url']) : ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo esc_url($state['admin_url']); ?>">
                <i class="bi bi-gear" aria-hidden="true"></i>
                <?php esc_html_e('Administración de acuerdos', 'egc'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($state['sin_acceso']) : ?>
        <div class="alert alert-warning">
            <?php esc_html_e('Tu acceso a los acuerdos ya no está vigente. Si creés que es un error, comunicate con la secretaría.', 'egc'); ?>
        </div>
    <?php else : ?>
        <div class="row g-4">
            <aside class="col-lg-3">
                <?php include EGC_DIR . '/modules/sca/acuerdo/views/partials/barra.php'; ?>
            </aside>

            <div class="col-lg-9">
                <?php if ($state['contexto'] && $state['contexto']['nuevo_url']) : ?>
                    <div class="mb-3 text-end">
                        <a class="btn btn-primary btn-sm" href="<?php echo esc_url($state['contexto']['nuevo_url']); ?>">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            <?php echo esc_html($state['contexto']['nuevo_etiqueta']); ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($state['filtro']) : ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <p class="mb-0">
                            <span class="badge text-bg-secondary"><?php echo esc_html($state['filtro']['texto']); ?></span>
                            <span class="text-muted ms-2">
                                <?php
                                printf(
                                    /* translators: %s: cantidad de acuerdos */
                                    esc_html(_n('%s acuerdo', '%s acuerdos', $state['total'], 'egc')),
                                    esc_html(number_format_i18n($state['total']))
                                );
                                ?>
                            </span>
                        </p>
                        <div class="d-flex gap-2">
                            <?php if ($state['filtro']['volver_url'] !== '' && !$state['nivel']) : ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url($state['filtro']['volver_url']); ?>">
                                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                                    <?php esc_html_e('Cambiar estatus', 'egc'); ?>
                                </a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url($state['filtro']['quitar_url']); ?>">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                <?php esc_html_e('Quitar filtro', 'egc'); ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($state['nivel']) : ?>
                    <p class="text-muted"><?php esc_html_e('Elegí un estatus para ver los acuerdos:', 'egc'); ?></p>
                    <div class="row g-3">
                        <?php foreach ($state['nivel']['estatus'] as $estatus) : ?>
                            <div class="col-6 col-md-3">
                                <a class="card h-100 text-decoration-none border-<?php echo esc_attr($estatus['color']); ?>"
                                   href="<?php echo esc_url($estatus['url']); ?>">
                                    <div class="card-body text-center">
                                        <div class="display-6 text-<?php echo esc_attr($estatus['color']); ?>"><?php echo esc_html(number_format_i18n($estatus['count'])); ?></div>
                                        <div class="text-body"><?php echo esc_html($estatus['label']); ?></div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (empty($state['tarjetas'])) : ?>
                    <?php if ($state['vacio']['tipo'] === 'success') : ?>
                        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
                            <span>
                                <i class="bi bi-check-circle" aria-hidden="true"></i>
                                <?php echo esc_html($state['vacio']['texto']); ?>
                            </span>
                            <?php if ($state['vacio']['todos_url'] !== '') : ?>
                                <a class="btn btn-sm btn-outline-success" href="<?php echo esc_url($state['vacio']['todos_url']); ?>">
                                    <?php esc_html_e('Ver todos', 'egc'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <p class="text-muted"><?php echo esc_html($state['vacio']['texto']); ?></p>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="row g-3">
                        <?php foreach ($state['tarjetas'] as $tarjeta) : ?>
                            <div class="col-12">
                                <?php include EGC_DIR . '/modules/sca/acuerdo/views/partials/tarjeta.php'; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($state['paginacion']) : ?>
                        <div class="mt-4">
                            <?php echo wp_kses_post($state['paginacion']); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
