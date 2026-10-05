<?php
/**
 * EGC — Entrypoint del tema.
 *
 * Define las constantes básicas, registra el autoloader del Core y
 * arranca el Core. No contiene lógica propia: es únicamente el punto de
 * entrada que WordPress exige.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('EGC_VERSION', wp_get_theme()->get('Version'));
define('EGC_DIR', get_template_directory());
define('EGC_URL', get_template_directory_uri());

/**
 * Autoloader de `EGC\Core\` -> `core/` (PSR-4: el namespace relativo
 * es la ruta y el último segmento, el nombre del archivo, tal cual).
 *
 * Reemplaza al autoload de Composer, que acá solo cumplía ese mapeo: el
 * proyecto no tiene ninguna dependencia externa, así que exigir
 * `composer install` (y subir `vendor/` al servidor) era un paso de
 * despliegue sin nada a cambio. Se registra acá y no en ModuleLoader
 * porque el Core tiene que poder cargarse ANTES de que exista cualquier
 * módulo; los módulos tienen su propio autoloader (ver
 * ModuleLoader::register_autoloading()).
 */
spl_autoload_register(function ($class) {
    $prefix = 'EGC\\Core\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $path = EGC_DIR . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

use EGC\Core\Core;

if (!function_exists('egc_bootstrap')) {
    function egc_bootstrap()
    {
        Core::get_instance();
    }
}
add_action('after_setup_theme', 'egc_bootstrap');
