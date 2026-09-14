<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Redirige las pantallas de wp-login.php que el sitio reemplaza por
 * páginas propias (login, registro, definir contraseña) hacia esas
 * páginas — sin tocar las que siguen siendo nativas.
 *
 * Se deja sin tocar a propósito:
 * - action=logout: es un link con nonce, no una pantalla; wp_logout_url()
 *   ya lo genera y WordPress ya sabe adónde redirigir después.
 * - action=lostpassword/retrievepassword: el primer paso de "olvidé mi
 *   contraseña" (pedir el email) se deja nativo por ahora; solo el
 *   paso final (definir la contraseña nueva, action=rp) tiene vista
 *   propia, porque es ahí donde vive el formulario con el que el
 *   usuario realmente interactúa después de hacer clic en el correo.
 */
class LoginGuard
{
    use Singleton;

    private function __construct()
    {
        add_action('login_init', [$this, 'redirect_native_screens']);
    }

    public function redirect_native_screens()
    {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';

        switch ($action) {
            case 'login':
                $this->redirect_to(LoginPage::get_instance()->url());
                break;

            case 'register':
                $this->redirect_to(UserRegistration::get_instance()->url());
                break;

            case 'rp':
            case 'resetpass':
                $this->redirect_to($this->reset_password_url());
                break;
        }
    }

    /**
     * Reconstruye el link de "definir contraseña" hacia nuestra propia
     * página, preservando key y login: son los datos con los que
     * PasswordReset valida el enlace (ver su view_state()).
     */
    private function reset_password_url()
    {
        $url = PasswordReset::get_instance()->url();

        $key   = isset($_REQUEST['key']) ? wp_unslash($_REQUEST['key']) : '';
        $login = isset($_REQUEST['login']) ? wp_unslash($_REQUEST['login']) : '';

        if ($key === '' || $login === '') {
            return $url;
        }

        return add_query_arg([
            'key'   => rawurlencode($key),
            'login' => rawurlencode($login),
        ], $url);
    }

    private function redirect_to($url)
    {
        if (!$url) {
            return;
        }

        wp_safe_redirect($url);
        exit;
    }
}
