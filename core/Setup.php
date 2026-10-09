<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

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
            'height' => 48,
            'width' => 48,
            'flex-height' => true,
            'flex-width' => true,
        ]);
    }
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
