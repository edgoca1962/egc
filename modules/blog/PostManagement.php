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
 * CRUD de entradas del Blog (post nativo) sin pasar por wp-admin, en
 * dos páginas:
 * - blog-panel: alta (formulario vacío) + listado propio/ajeno según
 *   edit_others_posts.
 * - blog-editar: edición de UN post puntual (?post_id=), presentado
 *   solo, sin el listado — para no forzar al usuario a pasar por el
 *   panel para corregir una entrada.
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

    const SLUG_EDITAR = 'blog-editar';

    const ACTION_SAVE = 'egc_blog_save';

    const ACTION_TRASH = 'egc_blog_trash';

    const NONCE_NAME = '_egc_nonce';

    private $url = null;

    private $url_editar = null;

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

    public function url_editar()
    {
        if ($this->url_editar === null) {
            $id = Pages::get_instance()->find_or_create(__('Editar publicación', 'egc'), self::SLUG_EDITAR);
            $this->url_editar = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_editar;
    }

    /**
     * Nadie sin sesión y sin edit_posts llega a ninguna de las dos
     * páginas. Ocultar el link/botón es presentación; esto es lo que
     * de verdad las protege.
     */
    public function guard_access()
    {
        if (is_page(self::SLUG)) {
            $this->guard_panel();
            return;
        }

        if (is_page(self::SLUG_EDITAR)) {
            $this->guard_editar();
        }
    }

    private function guard_panel()
    {
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
     * A blog-editar solo se llega con un post_id puntual sobre el que
     * se tenga edit_post — nunca a "editar en blanco".
     */
    private function guard_editar()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_safe_redirect($this->url());
            exit;
        }
    }

    /**
     * @return array{
     *   can_publish: bool,
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
        return [
            'can_publish' => current_user_can('publish_posts'),
            'posts'       => $this->posts_rows(),
            'error'       => $this->message('error'),
            'success'     => (bool) $this->message('ok'),
            'form_action' => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION_SAVE,
            'nonce_name'  => self::NONCE_NAME,
        ];
    }

    /**
     * @return array{
     *   editing: ?array,
     *   can_publish: bool,
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   back_url: string,
     * }|null null si el post_id de la URL ya no es válido (se borró
     *        entre que se armó el link y se abrió la página): guard_access()
     *        ya filtró el caso normal, esto es solo defensivo.
     */
    public function view_state_editar()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $editing = $post_id ? $this->editable_post($post_id) : null;

        if (!$editing) {
            return null;
        }

        return [
            'editing'      => $editing,
            'can_publish'  => current_user_can('publish_posts'),
            'error'        => $this->message('error'),
            'success'      => (bool) $this->message('ok'),
            'form_action'  => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION_SAVE,
            'nonce_name'   => self::NONCE_NAME,
            'back_url'     => $this->back_url(),
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
            'edit_url'  => add_query_arg('post_id', $post_id, $this->url_editar()),
            'can_trash' => current_user_can('delete_post', $post_id),
        ];
    }

    /**
     * URL segura para "volver" a la pantalla desde la que se llegó acá:
     * el Referer que manda el navegador, validado contra el propio
     * sitio (nunca un open redirect a otro dominio), con el panel como
     * última red si no hay Referer utilizable (llegada directa, un
     * buscador, etc.).
     */
    public function back_url()
    {
        $referer = wp_get_referer();

        return $referer ? wp_validate_redirect($referer, $this->url()) : $this->url();
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
            // format_to_edit() (no wp_richedit_pre(), deprecada desde
            // WP 4.3) es lo mismo que usa wp-admin antes de pasarle
            // post_content a wp_editor(): sin esto, el HTML crudo del
            // editor de bloques no se ve bien dentro de TinyMCE.
            'content' => format_to_edit($post->post_content, true),
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
        check_admin_referer(self::ACTION_SAVE, self::NONCE_NAME);

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
        check_admin_referer(self::ACTION_TRASH, self::NONCE_NAME);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('delete_post', $post_id)) {
            $this->back_with_error('forbidden');
        }

        wp_trash_post($post_id);

        $this->back_with_ok();
    }

    private function back_with_ok()
    {
        wp_safe_redirect(add_query_arg('ok', '1', $this->redirect_target()));
        exit;
    }

    private function back_with_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->redirect_target()));
        exit;
    }

    /**
     * Adónde volver después de guardar o eliminar. Si el formulario
     * mandó un redirect_to explícito (blog-editar, o el trash de
     * single.php, que lo arman con back_url() al pintarse) se usa ese,
     * validado contra el sitio; si no, el Referer de la propia request
     * (el caso normal al eliminar desde archive.php o panel.php: te
     * quedás en la misma pantalla); y si no hay ninguno, el panel.
     *
     * Un solo redirect_to explícito hace falta porque, para
     * blog-editar, el Referer de esta request ya es blog-editar (la
     * propia pantalla del formulario), no la pantalla de dos pasos
     * atrás a la que se quiere volver.
     */
    private function redirect_target()
    {
        $requested = isset($_POST['redirect_to']) ? wp_unslash($_POST['redirect_to']) : '';

        if ($requested !== '') {
            return wp_validate_redirect($requested, $this->back_url());
        }

        return $this->back_url();
    }
}
