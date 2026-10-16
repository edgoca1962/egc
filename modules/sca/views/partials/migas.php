<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Migas de pan del mantenimiento comité → acta → acuerdo (módulo SCA,
 * capa Vista). Espera $migas: lista de ['label' => texto, 'url' => enlace];
 * la última (url vacía) es el nivel actual y no lleva enlace. Lo
 * decidido (qué niveles hay y adónde llevan) lo preparó el Service; acá
 * solo se pinta. Marcado estándar de Bootstrap.
 */
if (empty($migas)) {
    return;
}
?>
<nav aria-label="<?php esc_attr_e('Ubicación', 'egc'); ?>">
    <ol class="breadcrumb">
        <?php foreach ($migas as $miga) : ?>
            <?php if ($miga['url'] !== '') : ?>
                <li class="breadcrumb-item"><a href="<?php echo esc_url($miga['url']); ?>"><?php echo esc_html($miga['label']); ?></a></li>
            <?php else : ?>
                <li class="breadcrumb-item active" aria-current="page"><?php echo esc_html($miga['label']); ?></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
