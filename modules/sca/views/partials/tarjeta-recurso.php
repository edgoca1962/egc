<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Tarjeta de un comité o de un acta en el mantenimiento (módulo SCA,
 * capa Vista). Espera $tarjeta, ya armada por Administracion:
 * titulo, url (el título es el enlace al nivel siguiente), detalles
 * (pares rótulo/valor), insignias, aviso (lo que arrastra eliminar, o
 * vacío) y acciones (editar/eliminar ya autorizadas, o null).
 */
?>
<div class="card h-100">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h3 class="h5 mb-1">
                    <a class="text-decoration-none" href="<?php echo esc_url($tarjeta['url']); ?>"><?php echo esc_html($tarjeta['titulo']); ?></a>
                    <?php foreach ($tarjeta['insignias'] as $insignia) : ?>
                        <span class="badge text-bg-<?php echo esc_attr($insignia['badge']); ?> align-middle fs-6"><?php echo esc_html($insignia['texto']); ?></span>
                    <?php endforeach; ?>
                </h3>
                <dl class="row row-cols-auto g-3 mb-0 small text-muted">
                    <?php foreach ($tarjeta['detalles'] as $detalle) : ?>
                        <div class="col">
                            <dt class="d-inline fw-normal"><?php echo esc_html($detalle[0]); ?>:</dt>
                            <dd class="d-inline mb-0 text-body"><?php echo esc_html($detalle[1]); ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </div>

            <?php if ($tarjeta['acciones']) : ?>
                <div class="text-nowrap">
                    <?php $actions = $tarjeta['acciones']; ?>
                    <?php include EGC_DIR . '/core/views/partials/post-actions.php'; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($tarjeta['aviso'] !== '' && $tarjeta['acciones'] && $tarjeta['acciones']['can_trash']) : ?>
            <p class="small text-danger mt-2 mb-0">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <?php echo esc_html($tarjeta['aviso']); ?>
            </p>
        <?php endif; ?>
    </div>
</div>
