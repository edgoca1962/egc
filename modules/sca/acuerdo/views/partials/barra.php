<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Barra lateral del listado de acuerdos: búsqueda y enlaces de filtro
 * con su cantidad. Solo pinta lo que Listado::view_state_archive()
 * resolvió.
 *
 * Espera $state.
 */
?>
<form role="search" method="get" action="<?php echo esc_url($state['buscar']['action']); ?>" class="mb-4">
    <input type="hidden" name="post_type" value="<?php echo esc_attr($state['buscar']['post_type']); ?>">
    <label class="visually-hidden" for="sca-buscar"><?php esc_html_e('Buscar acuerdos', 'egc'); ?></label>
    <div class="input-group">
        <input type="search" class="form-control" id="sca-buscar" name="s"
               placeholder="<?php esc_attr_e('Buscar acuerdos', 'egc'); ?>"
               value="<?php echo esc_attr($state['buscar']['valor']); ?>">
        <button type="submit" class="btn btn-outline-secondary"
                aria-label="<?php esc_attr_e('Buscar', 'egc'); ?>">
            <i class="bi bi-search" aria-hidden="true"></i>
        </button>
    </div>
</form>

<?php foreach ($state['secciones'] as $seccion) : ?>
    <h2 class="h6 text-uppercase text-muted mb-2"><?php echo esc_html($seccion['titulo']); ?></h2>
    <div class="list-group mb-4">
        <?php foreach ($seccion['items'] as $item) : ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $item['activo'] ? 'active' : ''; ?>"
               <?php echo $item['activo'] ? 'aria-current="true"' : ''; ?>
               href="<?php echo esc_url($item['url']); ?>">
                <span><?php echo esc_html($item['label']); ?></span>
                <span class="badge <?php echo $item['activo'] ? 'text-bg-light' : 'text-bg-secondary'; ?> rounded-pill"><?php echo esc_html(number_format_i18n($item['count'])); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
