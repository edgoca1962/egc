<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * "Mi cuenta": el usuario edita su propio nombre visible y su foto de
 * avatar. Nunca opera sobre otro user_id que no sea el propio (no lee
 * ningún id desde el formulario), así que no hace falta ninguna
 * comprobación de propiedad más allá de estar logeado.
 *
 * También es dueña del avatar autoalojado en sí: registra el filtro
 * pre_get_avatar_data que reemplaza a Gravatar en todo el sitio (por
 * privacidad — no se manda el email de nadie a un tercero), porque es
 * la misma responsabilidad que la foto que esta pantalla permite subir.
 */
class Account
{
    use Singleton;

    const SLUG = 'mi-cuenta';

    const ACTION = 'egc_account_update';

    const META_AVATAR = 'egc_avatar_id';

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_submission']);
        add_filter('pre_get_avatar_data', [$this, 'self_hosted_avatar'], 10, 2);
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
            $id = Pages::get_instance()->find_or_create(__('Mi cuenta', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * @return array{
     *   display_name: string,
     *   email: string,
     *   avatar_url: string,
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   password_change_url: string,
     * }
     */
    public function view_state()
    {
        $user = wp_get_current_user();

        return [
            'display_name'         => $user->display_name,
            'email'                => $user->user_email,
            'avatar_url'           => get_avatar_url($user->ID),
            'error'                => $this->error_message(),
            'success'              => isset($_GET['ok']),
            'form_action'          => admin_url('admin-post.php'),
            'nonce_action'         => self::ACTION,
            'nonce_name'           => '_egc_nonce',
            'password_change_url'  => PasswordChange::get_instance()->url(),
        ];
    }

    private function error_message()
    {
        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        return $error === 'avatar_failed'
            ? __('No se pudo subir la foto. Probá con otra imagen.', 'egc')
            : '';
    }

    public function handle_submission()
    {
        check_admin_referer(self::ACTION, '_egc_nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        if ($display_name !== '') {
            wp_update_user([
                'ID'           => $user_id,
                'display_name' => $display_name,
            ]);
        }

        if (!empty($_FILES['avatar']['name'])) {
            $attachment_id = $this->handle_avatar_upload($user_id);

            if (is_wp_error($attachment_id)) {
                wp_safe_redirect(add_query_arg('error', 'avatar_failed', $this->url()));
                exit;
            }
        }

        wp_safe_redirect(add_query_arg('ok', '1', $this->url()));
        exit;
    }

    /**
     * media_handle_upload() hace en una sola llamada lo que a mano
     * serían tres (wp_handle_upload, wp_insert_attachment,
     * wp_generate_attachment_metadata): es la API nativa para "un
     * formulario propio sube un archivo a la Biblioteca de medios".
     */
    private function handle_avatar_upload($user_id)
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $overrides = [
            'mimes' => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ],
        ];

        $attachment_id = media_handle_upload('avatar', 0, [], $overrides);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        $previous = (int) get_user_meta($user_id, self::META_AVATAR, true);
        if ($previous) {
            wp_delete_attachment($previous, true);
        }

        update_user_meta($user_id, self::META_AVATAR, $attachment_id);

        return $attachment_id;
    }

    /**
     * Reemplaza a Gravatar en todo get_avatar()/get_avatar_url() del
     * sitio: foto propia si el usuario subió una, si no, una imagen
     * genérica del tema, y si ni esa existe, un SVG inline para que el
     * <img> nunca quede roto.
     */
    public function self_hosted_avatar($args, $id_or_email)
    {
        $user = $this->resolve_user($id_or_email);
        if (!$user) {
            return $args;
        }

        $attachment_id = (int) get_user_meta($user->ID, self::META_AVATAR, true);
        $url           = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'thumbnail') : false;

        $args['url'] = $url ?: $this->generic_avatar_url();

        return $args;
    }

    private function resolve_user($id_or_email)
    {
        if (is_numeric($id_or_email)) {
            return get_user_by('id', $id_or_email);
        }

        if ($id_or_email instanceof \WP_User) {
            return $id_or_email;
        }

        if ($id_or_email instanceof \WP_Post) {
            return get_user_by('id', $id_or_email->post_author);
        }

        if ($id_or_email instanceof \WP_Comment) {
            return $id_or_email->user_id ? get_user_by('id', $id_or_email->user_id) : false;
        }

        if (is_string($id_or_email)) {
            return get_user_by('email', $id_or_email);
        }

        return false;
    }

    private function generic_avatar_url()
    {
        $path = EGC_DIR . '/assets/img/avatar-generico.png';

        if (file_exists($path)) {
            return EGC_URL . '/assets/img/avatar-generico.png';
        }

        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#6c757d"/><circle cx="50" cy="38" r="18" fill="#fff"/><path d="M50 62c-22 0-34 12-34 26v12h68V88c0-14-12-26-34-26z" fill="#fff"/></svg>'
        );
    }
}
