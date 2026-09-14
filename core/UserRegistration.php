<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * "Solicitar ingreso": alta pidiendo solo el correo. Crea el usuario
 * con una contraseña aleatoria e inutilizable (nadie la conoce) y
 * queda Pendiente vía el hook nativo user_register (UserStatus se
 * encarga de eso, esta clase no le escribe el meta directamente).
 * El usuario recién puede entrar cuando un administrador lo pasa a
 * Activo y ActivationNotice le manda el enlace para definir clave.
 */
class UserRegistration
{
    use Singleton;

    const SLUG = 'solicitar-ingreso';

    const ACTION = 'egc_solicitar_ingreso';

    private $url = null;

    private function __construct()
    {
        add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handle_submission']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_submission']);
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Solicitar ingreso', 'egc'), self::SLUG);
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
     *   login_url: string,
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
            'login_url'    => LoginPage::get_instance()->url(),
        ];
    }

    private function error_message()
    {
        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        switch ($error) {
            case 'invalid':
                return __('Ingresá un correo válido.', 'egc');
            case 'exists':
                return __('Ese correo ya tiene una cuenta.', 'egc');
            case 'failed':
                return __('No se pudo registrar la solicitud. Probá de nuevo.', 'egc');
            default:
                return '';
        }
    }

    public function handle_submission()
    {
        check_admin_referer(self::ACTION, '_egc_nonce');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if (!is_email($email)) {
            $this->back_with_error('invalid');
        }

        if (email_exists($email)) {
            $this->back_with_error('exists');
        }

        $user_id = wp_insert_user([
            'user_login' => $this->unique_username_for($email),
            'user_email' => $email,
            'user_pass'  => wp_generate_password(32, true, true),
            'role'       => 'subscriber',
        ]);

        if (is_wp_error($user_id)) {
            $this->back_with_error('failed');
        }

        wp_safe_redirect(add_query_arg('ok', '1', $this->url()));
        exit;
    }

    /**
     * WordPress necesita un user_login además del email. Se deriva del
     * correo y se desambigua con un sufijo numérico si hace falta; el
     * usuario nunca lo ve ni lo usa (entra con su correo).
     */
    private function unique_username_for($email)
    {
        $local = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($email, '@', true)));
        if ($local === '') {
            $local = 'usuario';
        }

        $username = $local;
        $suffix   = 1;

        while (username_exists($username)) {
            $username = $local . $suffix;
            $suffix++;
        }

        return $username;
    }

    private function back_with_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->url()));
        exit;
    }
}
