<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Botones de acción (editar / eliminar) para UN recurso. Vive en
 * core/views/partials/ porque más de un módulo lo necesita (Blog y
 * SGF, y cualquier otro que use el mismo patrón de CRUD) — Core
 * siempre está presente, así que ningún módulo depende de otro para
 * pintarlo. Antes vivía duplicado dentro de Blog; se movió acá apenas
 * apareció el segundo módulo que lo necesitaba, tal como pide
 * SRP APLICADO ("una abstracción se extrae cuando existe el segundo
 * módulo que la necesita, no antes").
 *
 * Espera $actions con: id, can_edit, edit_url, can_trash, trash_action
 * (el 'action' de admin-post.php para el trash de ESE recurso),
 * nonce_name. No hay lógica de autorización acá: current_user_can() ya
 * se resolvió antes de llegar a esta vista; esto solo pinta lo que
 * vino decidido. El 'action' del nonce (primer argumento de
 * wp_nonce_field()) es el mismo trash_action, misma convención que ya
 * usaban Blog y SGF por separado.
 *
 * $actions['redirect_to'] es opcional: adónde volver después de
 * eliminar. Si no se manda, el handler de trash de ese módulo cae a su
 * propio back_url() por defecto.
 *
 * Íconos de Bootstrap Icons en vez de texto, con aria-label + title
 * para que el control siga siendo comprensible sin la etiqueta visible.
 */
?>
<div class="d-inline-flex gap-1">
    <?php if ($actions['can_edit']) : ?>
        <a class="btn btn-sm btn-outline-primary"
           href="<?php echo esc_url($actions['edit_url']); ?>"
           aria-label="<?php esc_attr_e('Editar', 'egc'); ?>"
           title="<?php esc_attr_e('Editar', 'egc'); ?>">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
        </a>
    <?php endif; ?>

    <?php if ($actions['can_trash']) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="d-inline">
            <?php wp_nonce_field($actions['trash_action'], $actions['nonce_name']); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($actions['trash_action']); ?>">
            <input type="hidden" name="post_id" value="<?php echo esc_attr($actions['id']); ?>">
            <?php if (!empty($actions['redirect_to'])) : ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($actions['redirect_to']); ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    aria-label="<?php esc_attr_e('Eliminar', 'egc'); ?>"
                    title="<?php esc_attr_e('Eliminar', 'egc'); ?>">
                <i class="bi bi-trash3" aria-hidden="true"></i>
            </button>
        </form>
    <?php endif; ?>
</div>
