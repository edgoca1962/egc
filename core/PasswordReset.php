<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Página propia de "definir tu contraseña", llegando desde el correo
 * de ActivationNotice o desde el paso nativo de "olvidé mi contraseña"
 * (LoginGuard redirige acá el action=rp de wp-login.php).
 *
 * Reutiliza check_password_reset_key() / reset_password(), las mismas
 * funciones nativas que usa wp-login.php: solo se reemplaza la vista,
 * no la validación de la clave ni el guardado de la contraseña.
 */
class PasswordReset
{
    use Singleton;

    const SLUG = 'definir-contrasena';

    const ACTION = 'egc_definir_contrasena';

    private $url = null;

    private function __construct()
    {
        add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handle_submission']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_submission']);
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Definir contraseña', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * Todo lo que la vista necesita, ya resuelto: nada de $_GET ni de
     * llamadas a la API de reseteo de contraseña en el archivo de vista.
     *
     * @return array{
     *   valid: bool,
     *   key: string,
     *   login: string,
     *   error: string,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   login_url: string,
     * }
     */
    public function view_state()
    {
        $key   = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $login = isset($_GET['login']) ? sanitize_user(wp_unslash($_GET['login']), true) : '';

        $user  = ($key !== '' && $login !== '') ? check_password_reset_key($key, $login) : false;
        $valid = $user && !is_wp_error($user);

        return [
            'valid'        => $valid,
            'key'          => $key,
            'login'        => $login,
            'error'        => $this->error_message(),
            'form_action'  => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION,
            'nonce_name'   => '_egc_nonce',
            'login_url'    => LoginPage::get_instance()->url(),
        ];
    }

    private function error_message()
    {
        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        switch ($error) {
            case 'empty':
                return __('Ingresá una contraseña.', 'egc');
            case 'nomatch':
                return __('Las contraseñas no coinciden.', 'egc');
            case 'invalid':
                return __('El enlace no es válido o ya fue usado.', 'egc');
            default:
                return '';
        }
    }

    public function handle_submission()
    {
        check_admin_referer(self::ACTION, '_egc_nonce');

        $key   = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $login = isset($_POST['login']) ? sanitize_user(wp_unslash($_POST['login']), true) : '';
        $pass1 = isset($_POST['pass1']) ? (string) wp_unslash($_POST['pass1']) : '';
        $pass2 = isset($_POST['pass2']) ? (string) wp_unslash($_POST['pass2']) : '';

        $user = check_password_reset_key($key, $login);

        if (!$user || is_wp_error($user)) {
            $this->back_with_error('invalid', $key, $login);
        }

        if ($pass1 === '') {
            $this->back_with_error('empty', $key, $login);
        }

        if ($pass1 !== $pass2) {
            $this->back_with_error('nomatch', $key, $login);
        }

        reset_password($user, $pass1);
        wp_password_change_notification($user);

        wp_safe_redirect(LoginPage::get_instance()->url());
        exit;
    }

    private function back_with_error($error, $key, $login)
    {
        $url = add_query_arg([
            'error' => $error,
            'key'   => rawurlencode($key),
            'login' => rawurlencode($login),
        ], $this->url());

        wp_safe_redirect($url);
        exit;
    }
}
