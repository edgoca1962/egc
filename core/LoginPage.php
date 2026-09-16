<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Página propia de login, reemplazando wp-login.php (LoginGuard
 * redirige acá el action=login nativo).
 *
 * wp_signon() sin argumentos lee $_POST['log']/['pwd']/['rememberme']
 * directamente: es la convención nativa de nombres de campo, así que
 * el formulario los usa tal cual en vez de armar credenciales a mano.
 */
class LoginPage
{
    use Singleton;

    const SLUG = 'ingresar';

    const ACTION = 'egc_login';

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handle_submission']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_submission']);
    }

    /**
     * Un usuario ya logueado no tiene nada que hacer en la pantalla de
     * login — lo saca de acá antes de que la vea, igual que las
     * páginas que sí exigen sesión hacen lo inverso.
     */
    public function guard_access()
    {
        if (!is_page(self::SLUG)) {
            return;
        }

        if (is_user_logged_in()) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Ingresar', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * @return array{
     *   error: string,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   redirect_to: string,
     *   lost_password_url: string,
     *   register_url: string,
     * }
     */
    public function view_state()
    {
        return [
            'error'              => $this->error_message(),
            'form_action'        => admin_url('admin-post.php'),
            'nonce_action'       => self::ACTION,
            'nonce_name'         => '_egc_nonce',
            'redirect_to'        => home_url('/'),
            'lost_password_url'  => wp_lostpassword_url(),
            'register_url'       => UserRegistration::get_instance()->url(),
        ];
    }

    private function error_message()
    {
        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        switch ($error) {
            case 'invalid':
                return __('Usuario o contraseña incorrectos.', 'egc');
            case 'inactive':
                return __('Tu acceso todavía no está activo.', 'egc');
            default:
                return '';
        }
    }

    public function handle_submission()
    {
        check_admin_referer(self::ACTION, '_egc_nonce');

        $user = wp_signon();

        if (is_wp_error($user)) {
            $this->back_with_error('invalid');
        }

        // El superusuario (manage_options) es el único que no pasa por
        // el estado de aduana: es el único con acceso a wp-admin y no
        // lo crea el flujo de "solicitar ingreso".
        if (!user_can($user, 'manage_options') && UserStatus::get_instance()->get_status($user->ID) !== UserStatus::ACTIVO) {
            wp_logout();
            $this->back_with_error('inactive');
        }

        $redirect_to = isset($_POST['redirect_to']) ? wp_unslash($_POST['redirect_to']) : home_url('/');
        $redirect_to = wp_validate_redirect($redirect_to, home_url('/'));

        wp_safe_redirect($redirect_to);
        exit;
    }

    private function back_with_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->url()));
        exit;
    }
}
