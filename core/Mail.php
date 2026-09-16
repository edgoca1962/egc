<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Remitente de todo correo saliente del sitio (wp_mail_from /
 * wp_mail_from_name): no-reply@{dominio}, en vez del genérico
 * wordpress@{dominio} que pone WordPress por defecto. Aplica a
 * cualquier wp_mail() del sitio (incluido el "olvidé mi contraseña"
 * nativo), no solo al de ActivationNotice — por eso vive acá y no en
 * esa clase.
 */
class Mail
{
    use Singleton;

    private function __construct()
    {
        add_filter('wp_mail_from', [$this, 'from_address']);
        add_filter('wp_mail_from_name', [$this, 'from_name']);
    }

    public function from_address($original)
    {
        $domain = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';

        // Sin "www.": más prolijo, y evita que algunos servidores de
        // correo desconfíen de un From con ese subdominio.
        $domain = preg_replace('/^www\./', '', $domain);

        return 'no-reply@' . $domain;
    }

    public function from_name($original)
    {
        return get_bloginfo('name');
    }
}
