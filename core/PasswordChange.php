<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Cambio de contraseña autenticado (distinto de PasswordReset, que es
 * para cuando no se tiene contraseña o se olvidó). Pide la contraseña
 * actual para confirmar identidad, y como wp_set_password() invalida
 * la sesión activa, se vuelve a autenticar con wp_signon() al final
 * para que el usuario no quede deslogeado por cambiar su propia clave.
 */
class PasswordChange
{
    use Singleton;

    const SLUG = 'cambiar-contrasena';

    const ACTION = 'egc_password_change';

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_submission']);
    }

    public function guard_access()
    {
        if (!is_page(self::SLUG)) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Cambiar contraseña', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * @return array{
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   account_url: string,
     * }
     */
    public function view_state()
    {
        return [
            'error'        => $this->error_message(),
            'success'      => isset($_GET['ok']),
            'form_action'  => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION,
            'nonce_name'   => '_egc_nonce',
            'account_url'  => Account::get_instance()->url(),
        ];
    }

    private function error_message()
    {
        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        switch ($error) {
            case 'current_invalid':
                return __('La contraseña actual no es correcta.', 'egc');
            case 'empty':
                return __('Ingresá una contraseña nueva.', 'egc');
            case 'nomatch':
                return __('Las contraseñas no coinciden.', 'egc');
            default:
                return '';
        }
    }

    public function handle_submission()
    {
        check_admin_referer(self::ACTION, '_egc_nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $user    = wp_get_current_user();
        $current = isset($_POST['current_password']) ? (string) wp_unslash($_POST['current_password']) : '';
        $pass1   = isset($_POST['pass1']) ? (string) wp_unslash($_POST['pass1']) : '';
        $pass2   = isset($_POST['pass2']) ? (string) wp_unslash($_POST['pass2']) : '';

        if (!wp_check_password($current, $user->user_pass, $user->ID)) {
            $this->back_with_error('current_invalid');
        }

        if ($pass1 === '') {
            $this->back_with_error('empty');
        }

        if ($pass1 !== $pass2) {
            $this->back_with_error('nomatch');
        }

        wp_set_password($pass1, $user_id);
        wp_password_change_notification($user);

        // wp_set_password() invalida la sesión: se reautentica con la
        // contraseña recién puesta para que el usuario siga logeado.
        wp_signon([
            'user_login'    => $user->user_login,
            'user_password' => $pass1,
            'remember'      => true,
        ]);

        wp_safe_redirect(add_query_arg('ok', '1', Account::get_instance()->url()));
        exit;
    }

    private function back_with_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->url()));
        exit;
    }
}
