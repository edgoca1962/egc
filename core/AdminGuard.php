<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Bloquea el acceso a /wp-admin/ a todo el que no sea superusuario.
 *
 * Único mecanismo de permisos: `current_user_can('manage_options')` —
 * la capacidad exclusiva del superusuario, nadie más la tiene salvo que
 * se la asignen explícitamente. Se excluye por capacidad, nunca por
 * comparar nombres de rol o IDs de usuario.
 */
class AdminGuard
{
    use Singleton;

    private function __construct()
    {
        add_action('admin_init', [$this, 'block_dashboard']);
        add_filter('show_admin_bar', [$this, 'hide_admin_bar']);
    }

    public function block_dashboard()
    {
        // admin_init también dispara en admin-ajax.php y admin-post.php
        // — ahí no corresponde bloquear, cualquier usuario logueado
        // necesita poder usarlos.
        if (wp_doing_ajax()) {
            return;
        }

        global $pagenow;
        if ($pagenow === 'admin-post.php') {
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        wp_safe_redirect(home_url('/'));
        exit;
    }

    public function hide_admin_bar($show)
    {
        if (current_user_can('manage_options')) {
            return $show;
        }

        return false;
    }
}
