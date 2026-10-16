<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Encabezado común de las pantallas de alta/edición de SCA: título,
 * botón de regreso y los mensajes (guardado, aviso, error). Solo pinta
 * lo que RecursoManagement::view_state_editar() ya resolvió.
 *
 * Espera $state.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><?php echo esc_html($state['titulo']); ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo esc_url($state['back_url']); ?>">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <?php esc_html_e('Regresar', 'egc'); ?>
    </a>
</div>

<?php if ($state['success']) : ?>
    <div class="alert alert-success"><?php esc_html_e('Cambios guardados.', 'egc'); ?></div>
<?php endif; ?>

<?php if ($state['aviso']) : ?>
    <div class="alert alert-info"><?php echo esc_html($state['aviso']); ?></div>
<?php endif; ?>

<?php if ($state['error']) : ?>
    <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
<?php endif; ?>
