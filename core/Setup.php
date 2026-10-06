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
 * Formato de cifras: coma como separador de miles y punto como
 * decimal ("1,234.56") en TODO el sitio. WordPress ya resuelve el
 * formato numérico con number_format_i18n(), que lee los dos
 * separadores de `$wp_locale->number_format` — y esos valores salen
 * de la traducción del idioma del sitio (en español: "1.234,56"). Se
 * fijan acá, en ese mismo punto nativo, en vez de reemplazar las
 * llamadas a number_format_i18n() de cada vista o escribir una
 * función de formato propia: así todos los módulos (y el propio
 * wp-admin) muestran el mismo formato sin tocar una sola vista, y
 * es coherente con lo que ya exigen los formularios (`type=number`
 * envía siempre punto decimal) y la importación de CSV
 * (LibroImportacion::parsear_monto()). Los gráficos del Tablero
 * (JavaScript) no pasan por PHP: su formato se fija aparte, en
 * tablero.js.
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

        $this->set_number_format();

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
            'height'      => 48,
            'width'       => 48,
            'flex-height' => true,
            'flex-width'  => true,
        ]);
    }

    /**
     * Pisa solo los dos separadores (miles y decimales) del locale ya
     * cargado; el resto del idioma queda como está. `$wp_locale` existe
     * cuando corre after_setup_theme (WordPress lo crea antes de cargar
     * el functions.php del tema), pero se protege igual: sin él,
     * number_format_i18n() ya cae a number_format() de PHP, que usa
     * justamente este formato.
     */
    private function set_number_format()
    {
        global $wp_locale;

        if (!isset($wp_locale)) {
            return;
        }

        $wp_locale->number_format['thousands_sep'] = ',';
        $wp_locale->number_format['decimal_point'] = '.';
    }
}
