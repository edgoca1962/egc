<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Encola los assets compilados del build (Bootstrap vía webpack).
 *
 * El Core no gestiona el build — solo consume su salida
 * (`assets/main.css`, `assets/main.js`), generada por un proyecto de
 * build aparte que no vive dentro del tema. `style.css` no se enqueue
 * nunca: existe solo como el header de tema que WordPress exige.
 */
class Assets
{
    use Singleton;

    private function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue()
    {
        $css_path = EGC_DIR . '/assets/main.css';
        $js_path  = EGC_DIR . '/assets/main.js';

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'egc-main',
                EGC_URL . '/assets/main.css',
                [],
                filemtime($css_path)
            );
        }

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'egc-main',
                EGC_URL . '/assets/main.js',
                [],
                filemtime($js_path),
                true
            );
        }
    }
}
