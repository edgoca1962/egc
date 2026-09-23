<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Acciones de UNA fila de "Mis categorías" — incluido por
 * mis-categorias.php dentro de su recorrido, con $fila (la categoría
 * de esa fila, ya resuelta por CategoriaManagement::view_state()) y
 * $state (el resto del estado de la pantalla) ya en el scope. No hay
 * ninguna decisión acá: $fila ya trae puede_eliminar/puede_sustituir
 * resueltos — esto solo pinta.
 *
 * No reusa core/views/partials/post-actions.php: ese partial espera
 * capacidades de post (can_edit/can_trash vía edit_post/delete_post)
 * y acá la autorización es sobre un TÉRMINO (puede_gestionar()), con
 * un campo POST distinto (term_id, no post_id) y una tercera acción
 * que post-actions.php no contempla (sustituir).
 */
?>
<div class="d-inline-flex flex-wrap gap-1 align-items-center">
    <a class="btn btn-sm btn-outline-primary"
       href="<?php echo esc_url($fila['editar_url']); ?>"
       aria-label="<?php esc_attr_e('Renombrar', 'egc'); ?>"
       title="<?php esc_attr_e('Renombrar', 'egc'); ?>">
        <i class="bi bi-pencil-square" aria-hidden="true"></i>
    </a>

    <?php if ($fila['puede_eliminar']) : ?>
        <form method="post" action="<?php echo esc_url($state['form_action']); ?>" class="d-inline">
            <?php wp_nonce_field($state['action_eliminar'], $state['nonce_name']); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($state['action_eliminar']); ?>">
            <input type="hidden" name="term_id" value="<?php echo esc_attr($fila['id']); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['redirect_to']); ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    aria-label="<?php esc_attr_e('Eliminar', 'egc'); ?>"
                    title="<?php esc_attr_e('Eliminar', 'egc'); ?>">
                <i class="bi bi-trash3" aria-hidden="true"></i>
            </button>
        </form>
    <?php elseif ($fila['puede_sustituir']) : ?>
        <?php if (empty($fila['candidatos_sustituto'])) : ?>
            <span class="text-muted small">
                <?php esc_html_e('En uso — no hay otra categoría del mismo tipo para sustituirla.', 'egc'); ?>
            </span>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url($state['form_action']); ?>" class="d-inline-flex gap-1 align-items-center">
                <?php wp_nonce_field($state['action_sustituir'], $state['nonce_name']); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($state['action_sustituir']); ?>">
                <input type="hidden" name="term_id" value="<?php echo esc_attr($fila['id']); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($state['redirect_to']); ?>">
                <select name="sustituto_id" class="form-select form-select-sm" style="width: auto;" required>
                    <option value=""><?php esc_html_e('Sustituir por…', 'egc'); ?></option>
                    <?php foreach ($fila['candidatos_sustituto'] as $candidato) : ?>
                        <option value="<?php echo esc_attr($candidato['id']); ?>">
                            <?php echo esc_html(str_repeat('— ', $candidato['profundidad']) . $candidato['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-warning"
                        aria-label="<?php esc_attr_e('Sustituir', 'egc'); ?>"
                        title="<?php esc_attr_e('Sustituir', 'egc'); ?>">
                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                </button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
