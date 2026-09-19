<?php

namespace EGC\Modules\Blog;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use WP_Query;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * CRUD de entradas del Blog (post nativo) sin pasar por wp-admin, en
 * dos páginas:
 * - blog-editar: alta Y edición de UN post (con ?post_id= es edición,
 *   sin él es "nueva publicación"). Es el único formulario: se llega
 *   acá desde el botón "Crear artículo" de archive.php o desde el
 *   ícono de editar de cualquier post.
 * - articulos-pendientes: cola de revisión — todo lo que está en
 *   `pending` (lo que un blog_contributor manda a revisar, porque no
 *   tiene publish_posts), visible solo para quien administra el
 *   recurso (UserScope::manages('post'): Administrador General o
 *   blog_editor). No es una pantalla de autoservicio para el autor:
 *   es la cola que revisa un administrador.
 *
 * `post` es el CPT nativo de WordPress — no se registra nada acá, y la
 * distinción propio/ajeno la resuelve WordPress solo, vía
 * current_user_can('edit_post', $id) / ('delete_post', $id), que
 * map_meta_cap deriva de edit_posts/edit_others_posts según quién sea
 * el autor. Nada de comparar post_author en este archivo.
 *
 * Contrato de clase página-back, igual al de las páginas del Core:
 * const SLUG_*, url_*() memoizado vía Pages::find_or_create(),
 * view_state_*() (todo pre-resuelto para la vista), handle_*()
 * colgado de admin_post_{action}, guard_access() en template_redirect.
 */
class PostManagement
{
    use Singleton;

    const SLUG_EDITAR = 'blog-editar';

    const SLUG_PENDIENTES = 'articulos-pendientes';

    const ACTION_SAVE = 'egc_blog_save';

    const ACTION_TRASH = 'egc_blog_trash';

    const ACTION_PUBLICAR = 'egc_blog_publicar';

    const NONCE_NAME = '_egc_nonce';

    private $url_editar = null;

    private $url_pendientes = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save']);
        add_action('admin_post_' . self::ACTION_TRASH, [$this, 'handle_trash']);
        add_action('admin_post_' . self::ACTION_PUBLICAR, [$this, 'handle_publicar']);

        // El enlace al archive de 'post' en el dropdown del avatar ya
        // sale solo (UserScope::modulo_links()/autor_links() lo arman
        // genéricamente a partir del manifest) — este filtro solo suma
        // "Artículos pendientes de publicar", que no es el archive de
        // ningún CPT sino una Página propia de Blog, así que no hay
        // forma de que el mecanismo genérico la infiera sola. Si esta
        // carpeta se saca, el filtro simplemente deja de engancharse,
        // nada en Core queda referenciando una clase que ya no existe.
        add_filter('egc_dropdown_items_post', [$this, 'add_navbar_items'], 10, 2);
    }

    /**
     * @param  array  $items Lo que UserScope::links_by() ya armó para
     *                       'post' en este bloque.
     * @param  string $tier  'modulo' o 'autor' — la cola de revisión es
     *                       una tarea de administración, así que solo
     *                       se suma en 'modulo'. Sin chequeo de
     *                       capacidad acá: si este filtro se está
     *                       llamando para 'modulo', UserScope ya
     *                       comprobó manages('post') antes de llegar acá.
     * @return array
     */
    public function add_navbar_items($items, $tier)
    {
        if ($tier !== 'modulo') {
            return $items;
        }

        $items[] = [
            'label' => __('Artículos pendientes de publicar', 'egc'),
            'url'   => $this->url_pendientes(),
        ];

        return $items;
    }

    public function url_editar()
    {
        if ($this->url_editar === null) {
            $id = Pages::get_instance()->find_or_create(__('Publicación', 'egc'), self::SLUG_EDITAR);
            $this->url_editar = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_editar;
    }

    public function url_pendientes()
    {
        if ($this->url_pendientes === null) {
            $id = Pages::get_instance()->find_or_create(__('Artículos pendientes de publicar', 'egc'), self::SLUG_PENDIENTES);
            $this->url_pendientes = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_pendientes;
    }

    public function guard_access()
    {
        if (is_page(self::SLUG_EDITAR)) {
            $this->guard_editar();
            return;
        }

        if (is_page(self::SLUG_PENDIENTES)) {
            $this->guard_pendientes();
        }
    }

    /**
     * Sin post_id es "nueva publicación": alcanza con edit_posts. Con
     * post_id es edición de una entrada puntual: hace falta edit_post
     * sobre ESE post — nunca "editar en blanco" con un id ajeno.
     */
    private function guard_editar()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if ($post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                wp_safe_redirect($this->archive_url());
                exit;
            }
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_safe_redirect($this->archive_url());
            exit;
        }
    }

    /**
     * Cola de revisión: solo para quien administra el recurso 'post'
     * (Administrador General o blog_editor) — nunca para el autor del
     * post pendiente, aunque sea el suyo.
     */
    private function guard_pendientes()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        if (!UserScope::get_instance()->manages('post')) {
            wp_safe_redirect($this->archive_url());
            exit;
        }
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
     * }|null 'editing' es null en modo "nueva publicación". El propio
     *        array puede ser null si el post_id de la URL ya no es
     *        válido (se borró entre que se armó el link y se abrió la
     *        página): guard_access() ya filtró el caso normal, esto es
     *        solo defensivo.
     */
    public function view_state_editar()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $editing = $post_id ? $this->editable_post($post_id) : null;

        if ($post_id && !$editing) {
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
     * @return array{
     *   posts: array<int,array>,
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     * }
     */
    public function view_state_pendientes()
    {
        return [
            'posts'        => $this->pending_rows(),
            'error'        => $this->message('error'),
            'success'      => (bool) $this->message('ok'),
            'form_action'  => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION_PUBLICAR,
            'nonce_name'   => self::NONCE_NAME,
        ];
    }

    /**
     * Autorización + acción para UN post puntual: lo que puede hacer el
     * usuario actual con ese post (editar, eliminar) y el link para
     * hacerlo. Único lugar donde se decide esto — archive.php y
     * single.php lo consumen ya resuelto, en vez de repetir
     * current_user_can() en cada vista. articulos-pendientes.php no lo
     * usa: esa pantalla no edita ni elimina, solo publica.
     *
     * @return array{id:int, can_edit:bool, edit_url:string, can_trash:bool, trash_action:string, nonce_name:string}
     */
    public function actions_for($post_id)
    {
        $edit_url = add_query_arg('post_id', $post_id, $this->url_editar());

        return [
            'id'           => $post_id,
            'can_edit'     => current_user_can('edit_post', $post_id),
            'edit_url'     => $this->with_return_here($edit_url),
            'can_trash'    => current_user_can('delete_post', $post_id),
            'trash_action' => self::ACTION_TRASH,
            'nonce_name'   => self::NONCE_NAME,
        ];
    }

    /**
     * URL para "volver" a la pantalla desde la que se llegó acá: lo que
     * traiga ?volver= en ESTA request, validado contra el propio sitio
     * (nunca un open redirect a otro dominio), con el archivo del blog
     * como última red si no hay ninguno (llegada directa, un buscador).
     *
     * No usa el header Referer del navegador (wp_get_referer()): en la
     * práctica no es confiable acá — política de referrer del propio
     * navegador, y en entornos como LocalWP el host que ve el navegador
     * puede no ser exactamente el mismo que home_url(), con lo que
     * wp_validate_redirect() termina rechazando un Referer legítimo del
     * mismo sitio. ?volver= es una URL que arma el propio EGC (siempre
     * con home_url()), así que no tiene ese problema.
     */
    public function back_url()
    {
        $requested = isset($_GET['volver']) ? wp_unslash($_GET['volver']) : '';

        return $requested !== '' ? wp_validate_redirect($requested, $this->archive_url()) : $this->archive_url();
    }

    /**
     * Le agrega a $url el ?volver= que hace que, si $url es blog-editar
     * o cualquier otra pantalla con botón "Regresar", ese botón vuelva
     * exactamente a la pantalla actual (con su paginación, filtros,
     * etc., porque es la URL completa de esta request). Encadena solo:
     * si la URL actual YA trae su propio ?volver= (por ejemplo,
     * single.php al que se llegó desde el archivo), ese valor viaja
     * adentro sin que haga falta nada especial — así "regresar" funciona
     * salto por salto en vez de ir siempre al mismo lugar.
     */
    public function with_return_here($url)
    {
        return add_query_arg('volver', rawurlencode($this->current_url()), $url);
    }

    private function current_url()
    {
        return home_url(add_query_arg(null, null));
    }

    /**
     * URL del listado público del blog (el post_type nativo 'post' no
     * tiene "archivo" registrado como un CPT propio — es la página de
     * entradas que WordPress ya resuelve solo, estática o la portada).
     */
    private function archive_url()
    {
        if (get_option('show_on_front') === 'page' && get_option('page_for_posts')) {
            return get_permalink(get_option('page_for_posts'));
        }

        return home_url('/');
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

    /**
     * Filas de la cola de revisión: únicamente lo necesario para
     * cambiar el estatus (id + can_publish), nada de edit_url/can_trash
     * — esta pantalla no es un editor ni un eliminador, es la revisión
     * de pending a publish.
     */
    private function pending_rows()
    {
        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'pending',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC', // los que más tiempo llevan esperando revisión, primero.
        ]);

        $rows = [];
        foreach ($query->posts as $post) {
            $rows[] = [
                'id'          => $post->ID,
                'title'       => $post->post_title !== '' ? $post->post_title : __('(sin título)', 'egc'),
                'author'      => get_the_author_meta('display_name', $post->post_author),
                'date'        => get_the_date('', $post),
                'can_publish' => current_user_can('publish_posts') && current_user_can('edit_post', $post->ID),
            ];
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

        if ($title === '') {
            $this->back_with_error('empty_title');
        }

        // El estatus nunca viene del formulario: es una decisión de
        // facultades, no una entrada del usuario. Quien puede publicar,
        // publica; quien no, queda pendiente de revisión — igual que
        // hace WordPress nativo con el rol Contributor.
        $status = current_user_can('publish_posts') ? 'publish' : 'pending';

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

    /**
     * La única acción de articulos-pendientes.php: pasa un post de
     * pending a publish. No toca título ni contenido — para eso ya
     * está blog-editar. current_user_can('publish_posts') más
     * edit_post($id) porque el meta_cap "publish_post" no distingue
     * "propio pendiente" de "ajeno pendiente" de forma directa; esta
     * pareja es la misma que ya usa handle_save() para decidir si algo
     * se publica o se manda a revisión.
     */
    public function handle_publicar()
    {
        check_admin_referer(self::ACTION_PUBLICAR, self::NONCE_NAME);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post    = $post_id ? get_post($post_id) : null;

        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'pending') {
            $this->back_with_error('forbidden');
        }

        if (!current_user_can('publish_posts') || !current_user_can('edit_post', $post_id)) {
            $this->back_with_error('forbidden');
        }

        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);

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
     * mandó un redirect_to explícito (blog-editar —alta o edición—, o
     * el trash de single.php, que lo arman con back_url() al pintarse)
     * se usa ese, validado contra el sitio; si no, el Referer de la
     * propia request (el caso normal al eliminar desde archive.php o
     * articulos-pendientes.php: te quedás en la misma pantalla); y si
     * no hay ninguno, el archivo del blog.
     *
     * El redirect_to explícito hace falta para blog-editar porque el
     * Referer de ESTA request ya es blog-editar (la propia pantalla
     * del formulario), no la pantalla de dos pasos atrás a la que se
     * quiere volver.
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
