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
 *
 * Ya NO existe una ubicación nativa "Administrador de módulo": esos
 * enlaces pasaron a ser dinámicos (ver UserScope::modulo_links() /
 * UserScope::autor_links() y el dropdown del avatar en navbar.php) —
 * varían según los módulos presentes y el rol del usuario en cada uno,
 * algo que un menú armado a mano en Apariencia > Menús no puede
 * expresar. "Administrador general" sí sigue siendo un menú nativo
 * porque su contenido es genuinamente fijo: quien lo ve administra
 * todo, sin depender de qué módulos estén instalados.
 */
class Menus
{
    use Singleton;

    const LOC_PUBLICO = 'egc_publico';

    const LOC_ADMIN_GENERAL = 'egc_administrador_general';

    private function __construct()
    {
        register_nav_menus([
            self::LOC_PUBLICO       => __('Público (navbar)', 'egc'),
            self::LOC_ADMIN_GENERAL => __('Administrador general (navbar)', 'egc'),
        ]);
    }
}
