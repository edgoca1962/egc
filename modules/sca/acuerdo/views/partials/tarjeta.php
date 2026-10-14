<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Tarjeta de un acuerdo en el listado. Solo pinta lo que
 * Listado::tarjeta() resolvió; los botones de editar/eliminar llegan
 * (o no) ya autorizados en $tarjeta['acciones'].
 *
 * Espera $tarjeta.
 */
?>
<article class="card border-<?php echo esc_attr($tarjeta['color']); ?>">
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
        <h3 class="h6 card-title">
            <a class="text-decoration-none" href="<?php echo esc_url($tarjeta['url']); ?>"><?php echo esc_html($tarjeta['titulo']); ?></a>
        </h3>

        <p class="card-text"><?php echo esc_html($tarjeta['extracto']); ?></p>

        <dl class="row small text-muted mb-0">
            <dt class="col-sm-3"><?php esc_html_e('Comité', 'egc'); ?></dt>
            <dd class="col-sm-9"><?php echo esc_html($tarjeta['comite']); ?></dd>
            <dt class="col-sm-3"><?php esc_html_e('Reunión', 'egc'); ?></dt>
            <dd class="col-sm-9"><?php echo esc_html($tarjeta['reunion']); ?></dd>
            <dt class="col-sm-3"><?php esc_html_e('Compromiso', 'egc'); ?></dt>
            <dd class="col-sm-9"><?php echo esc_html($tarjeta['compromiso']); ?></dd>
            <dt class="col-sm-3"><?php esc_html_e('Asignado a', 'egc'); ?></dt>
            <dd class="col-sm-9 mb-0"><?php echo esc_html($tarjeta['asignado']); ?></dd>
        </dl>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center">
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url($tarjeta['url']); ?>">
            <?php esc_html_e('Ver detalle', 'egc'); ?>
        </a>

        <?php if ($tarjeta['acciones']) : ?>
            <?php $actions = $tarjeta['acciones']; ?>
            <?php include EGC_DIR . '/core/views/partials/post-actions.php'; ?>
        <?php endif; ?>
    </div>
</article>
