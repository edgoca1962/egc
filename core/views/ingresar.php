<?php

use EGC\Core\Banner;
use EGC\Core\LoginPage;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = LoginPage::get_instance()->view_state();

/**
 * Única vista del tema sin navbar ni banner estándar (le apaga
 * 'egc_mostrar_cabecera' a index.php desde LoginPage::ocultar_cabecera()
 * — ver su docblock): ocupa toda la pantalla (`min-vh-100`) con la
 * misma imagen de fondo genérica que usa el banner del resto del
 * sitio (Banner::generic_image_url(), el mismo archivo, no una copia)
 * — sin duplicar esa imagen en una franja de banner arriba y otra vez
 * acá.
 *
 * El isologo (si existe) no es parte del formulario: es un elemento
 * propio, posicionado con las utilidades nativas de Bootstrap para
 * "insignia sobre una esquina/borde" (`position-absolute top-0
 * start-50 translate-middle`, el mismo mecanismo con el que Bootstrap
 * arma un badge sobre la esquina de un botón) — pero acá el borde de
 * referencia es el de la TARJETA (`.card`, con su propio
 * `position-relative`), no el del fondo a pantalla completa: `top-0`
 * lo ubica sobre el borde superior de la tarjeta, `start-50` lo centra
 * horizontalmente respecto a ella, y `translate-middle` lo corre la
 * mitad de su propio ancho/alto hacia arriba e izquierda, dejando la
 * mitad de la imagen dentro de la tarjeta y la otra mitad afuera,
 * arriba y centrada. Nada de esto es `style` a mano: son las mismas
 * clases con las que Bootstrap resuelve este patrón. `pt-5` extra en
 * el `card-body` le deja aire abajo al isologo para que no se solape
 * con la alerta de error o el primer campo del formulario.
 */
$imagen_fondo = Banner::get_instance()->generic_image_url();
$style = $imagen_fondo
    ? 'background-image:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5)),url(' . esc_url($imagen_fondo) . ');background-size:cover;background-position:center;'
    : '';
?>
<div class="min-vh-100 d-flex align-items-center justify-content-center px-3 py-4<?php echo $imagen_fondo ? '' : ' bg-dark'; ?>"
    style="<?php echo esc_attr($style); ?>">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-11 col-sm-8 col-md-6 col-lg-4">
                <div class="card bg-transparent border-0 position-relative bg-login shadow">
                    <?php if (has_custom_logo()): ?>
                        <div class="position-absolute top-0 start-50 translate-middle">
                            <a href="<?php echo esc_url(home_url('/')); ?>">
                                <?php
                                echo wp_get_attachment_image(get_theme_mod('custom_logo'), 'full', false, [
                                    'width' => 60,
                                    'height' => 60,
                                    'class' => 'object-fit-contain',
                                ]);
                                ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="card-body p-4 pt-5">
                        <?php if ($state['error']): ?>
                            <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url($state['form_action']); ?>">
                            <?php wp_nonce_field($state['nonce_action'], $state['nonce_name']); ?>
                            <input type="hidden" name="action" value="egc_login">
                            <input type="hidden" name="redirect_to"
                                value="<?php echo esc_attr($state['redirect_to']); ?>">

                            <div class="mb-3">
                                <label class="form-label"
                                    for="log"><?php esc_html_e('Usuario o correo', 'egc'); ?></label>
                                <input class="form-control" type="text" id="log" name="log" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="pwd"><?php esc_html_e('Contraseña', 'egc'); ?></label>
                                <input class="form-control" type="password" id="pwd" name="pwd" required>
                            </div>

                            <div class="mb-3 form-check">
                                <input class="form-check-input" type="checkbox" id="rememberme" name="rememberme"
                                    value="forever">
                                <label class="form-check-label"
                                    for="rememberme"><?php esc_html_e('Recordarme', 'egc'); ?></label>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?php esc_html_e('Ingresar', 'egc'); ?>
                            </button>
                        </form>

                        <hr>

                        <p class="text-center mb-2">
                            <a href="<?php echo esc_url($state['lost_password_url']); ?>">
                                <?php esc_html_e('¿Olvidaste tu contraseña?', 'egc'); ?>
                            </a>
                        </p>
                        <p class="text-center mb-0">
                            <a href="<?php echo esc_url($state['register_url']); ?>">
                                <?php esc_html_e('Solicitar ingreso', 'egc'); ?>
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
