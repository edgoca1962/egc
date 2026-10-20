<?php

use EGC\Modules\Sca\Principal;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Página "Principal" del módulo SCA (slug sca-principal; el Core la
 * encuentra por la convención modules/sca/views/<slug>.php). Todo lo que
 * pinta lo resolvió Principal::view_state().
 *
 * Mismo fondo que el login: la imagen genérica del banner con su capa
 * oscura, a pantalla completa (`min-vh-100`). index.php no pinta navbar
 * ni banner en esta página (filtro `egc_mostrar_cabecera`, ver
 * Principal), así que la navbar se incluye acá, arriba de la imagen, y
 * el contenido se centra en el espacio que queda (`flex-grow-1`).
 */
$state = Principal::get_instance()->view_state();
?>
<div class="min-vh-100 d-flex flex-column text-white<?php echo $state['tiene_imagen'] ? '' : ' bg-dark'; ?>"
     <?php echo $state['estilo'] !== '' ? 'style="' . esc_attr($state['estilo']) . '"' : ''; ?>>

    <?php get_template_part('core/views/navbar'); ?>

    <div class="flex-grow-1 d-flex align-items-center justify-content-center text-center px-3 py-5">
        <div class="container">
            <h1 class="display-4 fw-bold mb-3"><?php echo esc_html($state['titulo']); ?></h1>
            <?php if ($state['descripcion'] !== '') : ?>
                <p class="lead mb-4"><?php echo esc_html($state['descripcion']); ?></p>
            <?php endif; ?>
            <a class="btn btn-primary btn-lg" href="<?php echo esc_url($state['boton']['url']); ?>">
                <?php echo esc_html($state['boton']['texto']); ?>
            </a>
        </div>
    </div>
</div>
