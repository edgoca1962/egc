<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Configuración base de WordPress.
 *
 * Registra los `add_theme_support` genéricos que cualquier instalación
 * del framework necesita, y el textdomain para traducciones. Cero
 * conocimiento de páginas, módulos o contenido de negocio — son
 * soportes de plataforma, no de negocio.
 *
 * Se instancia desde `Core`, que ya corre dentro de `after_setup_theme`
 * (ver `functions.php`); por eso acá se registra todo directo en el
 * constructor, sin volver a engancharse a `after_setup_theme` — hacerlo
 * sería agregar un callback a un hook que ya está en medio de su propia
 * ejecución, algo que depende del orden interno de WordPress en vez de
 * ser explícito.
 */
class Setup
{
    use Singleton;

    private function __construct()
    {
        load_theme_textdomain('egc', EGC_DIR . '/languages');

        add_theme_support('title-tag');
        add_theme_support('automatic-feed-links');
        add_theme_support('post-thumbnails');
        add_theme_support('html5', [
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
            'style',
            'script',
        ]);
        add_theme_support('customize-selective-refresh-widgets');
        add_theme_support('wp-block-styles');
        add_theme_support('align-wide');
        add_theme_support('custom-logo', [
            'height' => 48,
            'width' => 48,
            'flex-height' => true,
            'flex-width' => true,
        ]);
    }
}
