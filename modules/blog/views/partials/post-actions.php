<?php

use EGC\Modules\Blog\PostManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Botones de acción (editar / eliminar) para UN post. Reutilizado por
 * archive.php, single.php y panel.php: la única presentación de este
 * control en todo el módulo, para no repetir el mismo <form> con nonce
 * tres veces.
 *
 * Espera $actions con las claves que arma
 * PostManagement::actions_for(): id, can_edit, edit_url, can_trash. No
 * hay lógica de autorización acá: current_user_can() ya se resolvió
 * antes de llegar a esta vista; esto solo pinta lo que vino decidido.
 *
 * $actions['redirect_to'] es opcional: adónde volver DESPUÉS de
 * eliminar. Si no se manda, PostManagement::redirect_target() cae al
 * Referer de esta misma request — que ya es la pantalla correcta
 * cuando el botón está en archive.php o en blog-panel.php (uno se
 * queda en esa misma lista). single.php sí lo manda explícito, porque
 * ahí el Referer de este POST es el propio post que se está borrando.
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
            <?php wp_nonce_field(PostManagement::ACTION_TRASH, PostManagement::NONCE_NAME); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(PostManagement::ACTION_TRASH); ?>">
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
