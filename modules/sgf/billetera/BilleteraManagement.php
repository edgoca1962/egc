<?php

namespace EGC\Modules\Sgf\Billetera;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * CRUD de Billetera sin pasar por wp-admin, mismo patrón que
 * PostManagement en Blog: una sola página (billetera-editar, alta Y
 * edición según haya o no ?post_id=), handlers de admin-post.php,
 * view_state_*() para las vistas, guard_access() en template_redirect.
 *
 * La diferencia real con Blog no es de mecánica sino de alcance:
 * Billetera es información financiera privada, no contenido editorial
 * público. Blog deja que cualquiera vea el archive/single; acá solo el
 * dueño de cada billetera y quien administra el recurso (sgf_editor o
 * el super usuario) pueden verla — eso exige scope_archive_query() (el
 * listado) y el chequeo de propietario en guard_single() (el detalle),
 * ninguno de los dos necesario en Blog.
 *
 * Quien administra el recurso ve el listado de TODOS los usuarios sin
 * filtrar por defecto (scope_archive_query() no acota nada para
 * quien administra) — eso incluye al superusuario, porque
 * UserScope::manages() no lo trata como caso especial, resuelve todo
 * con current_user_can(). El filtro por usuario de archive.php
 * (?usuario=, ver archive_usuario_opciones()) existe para sgf_editor y
 * Administrador General, que administran el recurso pero — a
 * diferencia del superusuario — AdminGuard los deja afuera de
 * wp-admin, así que no tienen el filtro de autor nativo de la lista
 * de wp-admin como alternativa. Al superusuario, que sí entra a
 * wp-admin, no hace falta construirle nada acá: ya tiene ese mismo
 * filtro gratis en la lista nativa de Billeteras (show_ui => true), y
 * duplicarlo en el front-end sería una segunda fuente de verdad para
 * algo que WordPress ya resuelve.
 *
 * El atajo "Mis billeteras" del `<select>` (ver view_state_archive())
 * usa `UserScope::is_general_admin()`, no `manages()`: a propósito
 * deja afuera a sgf_editor (que administra Billetera vía
 * edit_others_billeteras, pero no es Administrador General) — pedido
 * explícito de Edwin. `is_general_admin()` es la misma capacidad
 * marcadora (`edit_users`) que ya identifica a Administrador General
 * en el resto del Core, y por diseño (ver el docblock de
 * AdminGeneralRole) también cubre al superusuario si alguna vez
 * navega el front-end en vez de wp-admin — no es un caso especial
 * nuevo, es el mismo criterio que ya existía.
 */
class BilleteraManagement
{
    use Singleton;

    const SLUG_EDITAR = 'billetera-editar';

    const ACTION_SAVE = 'egc_billetera_save';

    const ACTION_TRASH = 'egc_billetera_trash';

    const NONCE_NAME = '_egc_nonce';

    private $url_editar = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save']);
        add_action('admin_post_' . self::ACTION_TRASH, [$this, 'handle_trash']);
        add_action('pre_get_posts', [$this, 'scope_archive_query']);
    }

    public function url_editar()
    {
        if ($this->url_editar === null) {
            $id = Pages::get_instance()->find_or_create(__('Billetera', 'egc'), self::SLUG_EDITAR);
            $this->url_editar = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url_editar;
    }

    public function guard_access()
    {
        if (is_page(self::SLUG_EDITAR)) {
            $this->guard_editar();
            return;
        }

        if (is_singular(Billetera::POST_TYPE)) {
            $this->guard_single();
            return;
        }

        if (is_post_type_archive(Billetera::POST_TYPE)) {
            $this->guard_archive();
        }
    }

    /**
     * Sin post_id es "nueva billetera": alcanza con la capacidad
     * primitiva de edición del recurso. Con post_id es edición de una
     * billetera puntual: hace falta edit_post sobre ESA billetera —
     * WordPress resuelve propio/ajeno solo, vía map_meta_cap, a partir
     * de edit_billeteras/edit_others_billeteras.
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

        if (!current_user_can($this->cap('edit_posts'))) {
            wp_safe_redirect($this->archive_url());
            exit;
        }
    }

    /**
     * A diferencia de Blog, acá nadie sin sesión ve nada: es
     * información financiera, no contenido público.
     */
    private function guard_archive()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }
    }

    /**
     * El dueño de la billetera, o quien administra el recurso, pueden
     * ver el detalle — nadie más, aunque la billetera esté publicada.
     *
     * Esta es la única comparación directa de post_author de todo el
     * proyecto, y es deliberada: AUTORIZACIÓN pide resolver propio/ajeno
     * con capacidades pareadas (edit_posts vs edit_others_posts), pero
     * esa pareja solo existe para editar/eliminar — WordPress no tiene
     * una capacidad nativa de "leer solo lo propio" en un post_type
     * público. current_user_can('read_post', $id) no sirve acá: para
     * WordPress, un post publicado de un post_type público es legible
     * por cualquiera, sin distinguir autor (así es como Blog es público
     * a propósito). Como no hay capacidad nativa que resolver, no queda
     * más remedio que comparar el dato.
     */
    private function guard_single()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $post = get_queried_object();
        if (!$post instanceof WP_Post) {
            return;
        }

        if ((int) $post->post_author === get_current_user_id()) {
            return;
        }

        if (UserScope::get_instance()->manages(Billetera::POST_TYPE)) {
            return;
        }

        wp_safe_redirect($this->archive_url());
        exit;
    }

    /**
     * Filtra el listado (archive.php) a las billeteras del usuario
     * actual, salvo que administre el recurso — mismo motivo que
     * guard_single(): WordPress no tiene forma nativa de acotar el
     * listado principal de un post_type público por autor, así que se
     * fuerza acá. No logueado: 'author' => 0 (ningún autor real tiene
     * ese ID) como defensa adicional, aunque guard_archive() ya
     * redirige antes de que se llegue a pintar nada.
     *
     * Quien administra el recurso puede además acotar ese listado sin
     * filtrar a un usuario puntual, con `?usuario=` (ver
     * usuario_filtro_seleccionado() / archive_usuario_opciones()) — sin
     * este filtro, admin general y sgf_editor no tienen ninguna forma
     * de encontrar la billetera de un usuario puntual una vez que hay
     * varios, porque a diferencia del superusuario, a ellos
     * AdminGuard los deja afuera de wp-admin (que sí trae un filtro de
     * autor nativo) — ver la nota en la clase.
     */
    public function scope_archive_query($query)
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if (!$query->is_post_type_archive(Billetera::POST_TYPE)) {
            return;
        }

        if (!is_user_logged_in()) {
            $query->set('author', 0);
            return;
        }

        if (!UserScope::get_instance()->manages(Billetera::POST_TYPE)) {
            $query->set('author', get_current_user_id());
            return;
        }

        $usuario_filtro = $this->usuario_filtro_seleccionado();
        if ($usuario_filtro) {
            $query->set('author', $usuario_filtro);
        }
    }

    /**
     * El `?usuario=` de la URL, solo si es un ID de usuario real — 0 en
     * cualquier otro caso (sin filtro, o un valor inventado). Lo usan
     * tanto scope_archive_query() (para acotar la consulta) como
     * view_state_archive() (para marcar la opción seleccionada en el
     * `<select>`).
     */
    private function usuario_filtro_seleccionado()
    {
        $user_id = isset($_GET['usuario']) ? absint($_GET['usuario']) : 0;

        return ($user_id && get_userdata($user_id)) ? $user_id : 0;
    }

    /**
     * Usuarios que tienen al menos una billetera propia, correo =>
     * para pintar el `<select>` de archive.php — no "todos los usuarios
     * del sitio" (la mayoría no tiene ninguna billetera, sería ruido) ni
     * "todos los que tienen rol de SGF" (alguien con el rol recién
     * asignado y todavía sin billeteras no tiene nada que filtrar).
     *
     * Sin `get_posts()` con `'fields' => 'ids'` no hay forma nativa de
     * pedirle a WordPress directamente "los post_author distintos de
     * este post_type" (WP_Query no tiene ese modo) — de ahí el loop
     * leyendo `post_author` de cada ID.
     *
     * @return array<int,string> user_id => user_email, ordenado por email.
     */
    private function archive_usuario_opciones()
    {
        $ids = get_posts([
            'post_type'      => Billetera::POST_TYPE,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        $usuarios = [];
        foreach ($ids as $post_id) {
            $user_id = (int) get_post_field('post_author', $post_id);
            if ($user_id === 0 || isset($usuarios[$user_id])) {
                continue;
            }

            $user = get_userdata($user_id);
            if ($user) {
                $usuarios[$user_id] = $user->user_email;
            }
        }

        asort($usuarios);

        return $usuarios;
    }

    /**
     * @return array{
     *   editing: ?array,
     *   can_publish: bool,
     *   moneda_opciones: array<int,string>,
     *   error: string,
     *   success: bool,
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     *   back_url: string,
     * }|null
     */
    public function view_state_editar()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $editing = $post_id ? $this->editable_billetera($post_id) : null;

        if ($post_id && !$editing) {
            return null;
        }

        return [
            'editing'         => $editing,
            'can_publish'     => current_user_can($this->cap('publish_posts')),
            'moneda_opciones' => $this->moneda_opciones(),
            'error'        => $this->message('error'),
            'success'      => (bool) $this->message('ok'),
            'form_action'  => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION_SAVE,
            'nonce_name'   => self::NONCE_NAME,
            'back_url'     => $this->back_url(),
        ];
    }

    /**
     * Etiqueta legible de un valor de `_moneda`, para archive.php y
     * single.php — reutiliza el mismo par que arma moneda_opciones(),
     * para no repetir los dos textos en dos lugares.
     */
    public function moneda_label($moneda)
    {
        return $this->moneda_opciones()[(int) $moneda] ?? '';
    }

    private function moneda_opciones()
    {
        return [
            Billetera::MONEDA_LOCAL      => __('Moneda Local', 'egc'),
            Billetera::MONEDA_EXTRANJERA => __('Moneda Extranjera', 'egc'),
        ];
    }

    /**
     * @return array{
     *   posts: array<int,array>,
     *   error: string,
     *   success: bool,
     * }
     */
    public function view_state_archive()
    {
        $puede_filtrar = UserScope::get_instance()->manages(Billetera::POST_TYPE);

        return [
            'error'                    => $this->message('error'),
            'success'                  => (bool) $this->message('ok'),
            'can_filter_by_usuario'    => $puede_filtrar,
            'usuario_opciones'         => $puede_filtrar ? $this->archive_usuario_opciones() : [],
            'usuario_seleccionado'     => $this->usuario_filtro_seleccionado(),
            // Atajo "Mis billeteras": solo para Administrador General,
            // no para sgf_editor (ver el docblock de la clase). Nunca
            // el usuario_id actual a secas: get_current_user_id() ya
            // alcanza sin agregar otra clave, se resuelve en la vista.
            'es_administrador_general' => UserScope::get_instance()->is_general_admin(),
        ];
    }

    /**
     * Si corresponde mostrar el botón "crear billetera" — decisión de
     * autorización, así que vive acá y no en archive.php (la vista
     * solo pinta lo que ya vino resuelto).
     */
    public function can_create()
    {
        return current_user_can($this->cap('edit_posts'));
    }

    /**
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
     * Mismo mecanismo que PostManagement::back_url() en Blog: ?volver=
     * propio en vez del header Referer del navegador (poco confiable en
     * entornos como LocalWP), validado contra el propio sitio.
     */
    public function back_url()
    {
        $requested = isset($_GET['volver']) ? wp_unslash($_GET['volver']) : '';

        return $requested !== '' ? wp_validate_redirect($requested, $this->archive_url()) : $this->archive_url();
    }

    public function with_return_here($url)
    {
        return add_query_arg('volver', rawurlencode($this->current_url()), $url);
    }

    private function current_url()
    {
        return home_url(add_query_arg(null, null));
    }

    /**
     * A diferencia de Blog (post nativo, sin archivo propio),
     * Billetera es un CPT real con has_archive => true: WordPress ya
     * resuelve su URL de listado solo.
     */
    private function archive_url()
    {
        $url = get_post_type_archive_link(Billetera::POST_TYPE);

        return $url ?: home_url('/');
    }

    /**
     * Nombre real de una capacidad primitiva de Billetera
     * (`edit_posts`, `publish_posts`, …) leído de
     * get_post_type_object()->cap, nunca hardcodeado — mismo motivo que
     * ya corrigió UserScope y AdminGeneralRole: `capability_type` es
     * `['billetera', 'billeteras']`, así que la capacidad real es
     * `edit_billeteras`, no `edit_posts`.
     */
    private function cap($meta_cap)
    {
        $post_type_object = get_post_type_object(Billetera::POST_TYPE);

        return $post_type_object ? $post_type_object->cap->$meta_cap : $meta_cap;
    }

    private function editable_billetera($post_id)
    {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== Billetera::POST_TYPE || !current_user_can('edit_post', $post_id)) {
            return null;
        }

        return [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'saldo'  => (float) get_post_meta($post->ID, '_saldo', true),
            'moneda' => (int) get_post_meta($post->ID, '_moneda', true) ?: Billetera::MONEDA_LOCAL,
        ];
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
                return __('El nombre de la billetera no puede quedar vacío.', 'egc');
            case 'moneda_invalida':
                return __('Seleccioná una moneda válida.', 'egc');
            default:
                return '';
        }
    }

    public function handle_save()
    {
        check_admin_referer(self::ACTION_SAVE, self::NONCE_NAME);

        if (!is_user_logged_in() || !current_user_can($this->cap('edit_posts'))) {
            $this->back_with_error('forbidden');
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $title   = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        // Con signo: una cuenta sobregirada o una tarjeta de crédito son
        // saldos negativos legítimos (ver la regla de signos del
        // módulo) — nada de absint ni de forzar unsigned acá.
        $saldo  = isset($_POST['saldo']) ? (float) str_replace(',', '.', wp_unslash($_POST['saldo'])) : 0.0;
        $moneda = isset($_POST['moneda']) ? absint($_POST['moneda']) : 0;

        if ($title === '') {
            $this->back_with_error('empty_title');
        }

        if (!in_array($moneda, [Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA], true)) {
            $this->back_with_error('moneda_invalida');
        }

        // El estatus nunca es una decisión del usuario: si puede
        // publicar, publica; si no, queda pendiente — mismo criterio
        // que ya aplica Blog en su propio handle_save().
        $status = current_user_can($this->cap('publish_posts')) ? 'publish' : 'pending';

        $data = [
            'post_type'   => Billetera::POST_TYPE,
            'post_title'  => $title,
            'post_status' => $status,
            'meta_input'  => [
                '_saldo'  => $saldo,
                '_moneda' => $moneda,
            ],
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
        $post    = $post_id ? get_post($post_id) : null;

        if (!$post || $post->post_type !== Billetera::POST_TYPE || !current_user_can('delete_post', $post_id)) {
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

    private function redirect_target()
    {
        $requested = isset($_POST['redirect_to']) ? wp_unslash($_POST['redirect_to']) : '';

        if ($requested !== '') {
            return wp_validate_redirect($requested, $this->back_url());
        }

        return $this->back_url();
    }
}
