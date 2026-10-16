<?php

use EGC\Modules\Sca\Acuerdo\Listado;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Detalle de un acuerdo. Consulta ya garantizó que el usuario lo puede
 * ver (si no, esta vista no se llega a servir: da 404). Solo pinta lo
 * que Listado::view_state_single() resolvió.
 */
$tarjeta = Listado::get_instance()->view_state_single(get_queried_object_id());
?>
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><?php echo esc_html($tarjeta['titulo']); ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo esc_url($tarjeta['volver']); ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <?php esc_html_e('Regresar', 'egc'); ?>
        </a>
    </div>

    <article class="card border-<?php echo esc_attr($tarjeta['color']); ?> mb-4">
        <div class="card-header d-flex justify-content-between align-items-center bg-light">
            <span class="fw-semibold">
                <?php esc_html_e('Estatus:', 'egc'); ?>
                <span class="badge text-bg-<?php echo esc_attr($tarjeta['color']); ?>"><?php echo esc_html($tarjeta['estatus_texto']); ?></span>
            </span>
            <span class="small <?php echo $tarjeta['plazo']['vencido'] ? 'text-danger fw-semibold' : 'text-muted'; ?>">
                <?php if ($tarjeta['plazo']['vencido']) : ?>
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <?php endif; ?>
                <?php echo esc_html($tarjeta['plazo']['texto']); ?>
            </span>
        </div>

        <div class="card-body">
            <dl class="row small text-muted mb-3">
                <dt class="col-sm-3"><?php esc_html_e('Comité', 'egc'); ?></dt>
                <dd class="col-sm-9"><?php echo esc_html($tarjeta['comite']); ?></dd>
                <dt class="col-sm-3"><?php esc_html_e('Reunión', 'egc'); ?></dt>
                <dd class="col-sm-9"><?php echo esc_html($tarjeta['reunion']); ?></dd>
                <dt class="col-sm-3"><?php esc_html_e('Compromiso', 'egc'); ?></dt>
                <dd class="col-sm-9"><?php echo esc_html($tarjeta['compromiso']); ?></dd>
                <dt class="col-sm-3"><?php esc_html_e('Asignado a', 'egc'); ?></dt>
                <dd class="col-sm-9 mb-0"><?php echo esc_html($tarjeta['asignado']); ?></dd>
            </dl>

            <div class="entry-content">
                <?php echo $tarjeta['contenido']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- salida del filtro nativo the_content. ?>
            </div>
        </div>

        <?php if ($tarjeta['ejecutar'] || $tarjeta['acciones']) : ?>
            <div class="card-footer d-flex justify-content-end gap-2">
                <?php if ($tarjeta['ejecutar']) : ?>
                    <?php $ejecutar = $tarjeta['ejecutar']; ?>
                    <?php include EGC_DIR . '/modules/sca/acuerdo/views/partials/ejecutar.php'; ?>
                <?php endif; ?>

                <?php if ($tarjeta['acciones']) : ?>
                    <?php $actions = $tarjeta['acciones']; ?>
                    <?php include EGC_DIR . '/core/views/partials/post-actions.php'; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </article>

    <?php
    // Comentarios nativos (wp-comments-post.php). Comentarios::abierto()
    // decide si el formulario aparece; la plantilla es la común del Core.
    // comments_template() trabaja sobre el post global, que se fija con the_post().
    if (have_posts()) {
        the_post();
        comments_template('/core/views/partials/comentarios.php');
    }
    ?>
</div>
