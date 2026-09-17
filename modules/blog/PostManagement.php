<?php

namespace EGC\Modules\Blog;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use WP_Query;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Panel privado del Blog: alta, edición y baja de entradas propias (o
 * de todas, para quien tenga edit_others_posts) sin pasar por
 * wp-admin.
 *
 * `post` es el CPT nativo de WordPress — no se registra nada acá, y la
 * distinción propio/ajeno la resuelve WordPress solo, vía
 * current_user_can('edit_post', $id) / ('delete_post', $id), que
 * map_meta_cap deriva de edit_posts/edit_others_posts según quién sea
 * el autor. Nada de comparar post_author en este archivo.
 *
 * Contrato de clase página-back, igual al de las páginas del Core:
 * const SLUG, url() memoizado vía Pages::find_or_create(), view_state()
 * (todo pre-resuelto para la vista), handle_*() colgado de
 * admin_post_{action}, guard_access() en template_redirect.
 */
class PostManagement
{
    use Singleton;

    const SLUG = 'blog-panel';

    const ACTION_SAVE = 'egc_blog_save';

    const ACTION_TRASH = 'egc_blog_trash';

    const NONCE_NAME = '_egc_nonce';

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save']);
        add_action('admin_post_' . self::ACTION_TRASH, [$this, 'handle_trash']);
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Mis publicaciones', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * Nadie sin sesión y sin edit_posts llega a esta página. Ocultar
     * el link del menú es presentación; esto es lo que de verdad la
     * protege.
     */
    public function guard_access()
    {
        if (!is_page(self::SLUG)) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        if (!current_user_can('edit_posts')) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * @return array{
     *   can_publish: bool,
     *   editing: ?array,
     *   posts: array<int,array>,
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     * }
     */
    public function view_state()
    {
        $editing_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        return [
            'can_publish' => current_user_can('publish_posts'),
            'editing'     => $editing_id ? $this->editable_post($editing_id) : null,
            'posts'       => $this->posts_rows(),
            'error'       => $this->message('error'),
            'success'     => (bool) $this->message('ok'),
            'form_action' => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION_SAVE,
            'nonce_name'  => self::NONCE_NAME,
        ];
    }

    /**
     * Autorización + acción para UN post puntual: lo que puede hacer el
     * usuario actual con ese post (editar, eliminar) y el link para
     * hacerlo. Único lugar donde se decide esto — archive.php, single.php
     * y panel.php (vía posts_rows()) lo consumen ya resuelto, en vez de
     * repetir current_user_can() en cada vista.
     *
     * @return array{id:int, can_edit:bool, edit_url:string, can_trash:bool}
     */
    public function actions_for($post_id)
    {
        return [
            'id'        => $post_id,
            'can_edit'  => current_user_can('edit_post', $post_id),
            'edit_url'  => add_query_arg('post_id', $post_id, $this->url()),
            'can_trash' => current_user_can('delete_post', $post_id),
        ];
    }

    private function editable_post($post_id)
    {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post' || !current_user_can('edit_post', $post_id)) {
            return null;
        }

        return [
            'id'      => $post->ID,
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'status'  => $post->post_status,
        ];
    }

    private function posts_rows()
    {
        // Sin filtro por post_author acá a propósito: WP_Query devuelve
        // todo, y es current_user_can('edit_post'/'delete_post', $id) —
        // no una condición de post_author en este archivo — lo que
        // decide, fila por fila, qué puede tocar quién.
        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => ['publish', 'draft', 'pending', 'future'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $rows = [];
        foreach ($query->posts as $post) {
            $rows[] = array_merge($this->actions_for($post->ID), [
                'title'     => $post->post_title !== '' ? $post->post_title : __('(sin título)', 'egc'),
                'status'    => $post->post_status,
                'permalink' => get_permalink($post),
            ]);
        }

        return $rows;
    }

    private function message($param)
    {
        if ($param === 'ok') {
            return isset($_GET['ok']);
        }

        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        switch ($error) {
            case 'forbidden':
                return __('No tenés permiso para hacer eso.', 'egc');
            case 'empty_title':
                return __('El título no puede quedar vacío.', 'egc');
            default:
                return '';
        }
    }

    public function handle_save()
    {
        check_admin_referer(self::ACTION_SAVE, '_egc_nonce');

        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            $this->back_with_error('forbidden');
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $title   = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        $content = isset($_POST['post_content']) ? wp_kses_post(wp_unslash($_POST['post_content'])) : '';
        $status  = isset($_POST['post_status']) && $_POST['post_status'] === 'publish' ? 'publish' : 'draft';

        if ($title === '') {
            $this->back_with_error('empty_title');
        }

        // Quien no puede publicar, no publica aunque lo mande en el
        // formulario: se manda a revisión, igual que hace WordPress
        // nativo con el rol Contributor.
        if ($status === 'publish' && !current_user_can('publish_posts')) {
            $status = 'pending';
        }

        $data = [
            'post_type'    => 'post',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
        ];

        if ($post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                $this->back_with_error('forbidden');
            }
            $data['ID'] = $post_id;
            $result = wp_update_post($data, true);
        } else {
            $data['post_author'] = get_current_user_id();
            $result = wp_insert_post($data, true);
        }

        if (is_wp_error($result)) {
            $this->back_with_error('forbidden');
        }

        $this->back_with_ok();
    }

    public function handle_trash()
    {
        check_admin_referer(self::ACTION_TRASH, '_egc_nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('delete_post', $post_id)) {
            $this->back_with_error('forbidden');
        }

        wp_trash_post($post_id);

        $this->back_with_ok();
    }

    private function back_with_ok()
    {
        wp_safe_redirect(add_query_arg('ok', '1', $this->url()));
        exit;
    }

    private function back_with_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->url()));
        exit;
    }
}
