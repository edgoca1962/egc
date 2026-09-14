<?php
/**
 * EGC — Entrypoint del tema.
 *
 * Define las constantes básicas, carga el autoload de Composer y arranca
 * el Core. No contiene lógica propia: es únicamente el punto de entrada
 * que WordPress exige.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('EGC_VERSION', wp_get_theme()->get('Version'));
define('EGC_DIR', get_template_directory());
define('EGC_URL', get_template_directory_uri());

require_once EGC_DIR . '/vendor/autoload.php';

use EGC\Core\Core;

if (!function_exists('egc_bootstrap')) {
    function egc_bootstrap()
    {
        Core::get_instance();
    }
}
add_action('after_setup_theme', 'egc_bootstrap');
