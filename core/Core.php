<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Orquestador del Core.
 *
 * Punto único de arranque del tema, instanciado desde `functions.php`
 * en `after_setup_theme`. Por ahora no coordina nada: los pasos
 * siguientes (configuración de WordPress, guard de administración,
 * assets, module loader, role sync, resolver de vistas) se van a ir
 * agregando acá, cada uno delegado a su propia clase — nunca como
 * lógica propia de Core.
 */
class Core
{
    use Singleton;

    private function __construct()
    {
        // Intencionalmente vacío por ahora.
    }
}
