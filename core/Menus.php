<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Declara las ubicaciones de menú nativas de WordPress. El contenido
 * de cada una (qué links, en qué orden) lo arma el superusuario desde
 * Apariencia > Menús — es exactamente lo que ese editor de wp-admin ya
 * resuelve, no hace falta un constructor de menús propio.
 *
 * Como Core::get_instance() ya corre dentro de after_setup_theme, este
 * constructor registra los menús directamente (no vuelve a colgarse
 * del mismo hook), igual que Setup.
 */
class Menus
{
    use Singleton;

    const LOC_PUBLICO = 'egc_publico';

    const LOC_ADMIN_GENERAL = 'egc_administrador_general';

    const LOC_ADMIN_MODULO = 'egc_administrador_modulo';

    private function __construct()
    {
        register_nav_menus([
            self::LOC_PUBLICO       => __('Público (navbar)', 'egc'),
            self::LOC_ADMIN_GENERAL => __('Administrador general (navbar)', 'egc'),
            self::LOC_ADMIN_MODULO  => __('Administrador de módulo (navbar)', 'egc'),
        ]);
    }
}
