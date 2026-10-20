<?php

namespace EGC\Modules\Sca;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Modules\Sca\Acuerdo\Asignacion;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — base del CRUD de los cinco recursos de SCA, sin pasar
 * por wp-admin (módulo SCA).
 *
 * Por qué una clase abstracta: comité, puesto, miembro, acta y acuerdo
 * son cinco CRUD con EXACTAMENTE la misma mecánica — una Página de
 * edición sembrada (alta y edición según haya `?post_id=`), un handler
 * de guardado y otro de papelera en admin-post.php, guard en
 * template_redirect, mensajes por `?error=` / `?ok`, regreso con
 * `?volver=`. Es la mecánica que BilleteraManagement ya resuelve para
 * un recurso; acá se repetiría cinco veces, y de a una copia cada
 * corrección habría que hacerla cinco veces. Lo que DIFIERE entre
 * recursos son tres cosas, y es lo único que cada subclase escribe:
 * leer y validar el formulario (leer()), qué campos pintar
 * (valores()/opciones()) y qué hacer tras guardar (despues_de_guardar()).
 *
 * Autorización: único mecanismo, capacidades de WordPress.
 *   - Alta:     la capacidad primitiva `edit_posts` del recurso.
 *   - Edición:  `edit_post` sobre ESE post (map_meta_cap lo deriva de
 *               edit_others_* y de los otros).
 *   - Papelera: `delete_post` sobre ese post.
 * Solo el editor del módulo, el administrador general y el
 * superusuario las tienen (manifest + AdminGeneralRole). Cada escritura
 * pasa por: sesión + recurso válido + capacidad + nonce + API nativa
 * (wp_insert_post / wp_update_post / wp_trash_post); ocultar un botón
 * en la vista es presentación, el handler siempre revalida.
 *
 * Si el formulario falla la validación, se vuelve a la misma pantalla
 * con el error y lo que el usuario había escrito (guardado en un
 * transient de 2 minutos por usuario y recurso): perder el texto de un
 * acuerdo por una fecha mal puesta no es aceptable. Transient y no
 * sesión PHP porque es lo que WordPress ofrece para datos temporales.
 *
 * Las subclases usan el trait Singleton (que debe estar en la clase
 * concreta para poder invocar su constructor privado).
 */
abstract class RecursoManagement
{
    const NONCE_NAME = '_egc_nonce';

    const SEGUNDOS_ENTRADA_RECORDADA = 120;

    /** @var string|null URL de la página de edición, resuelta una sola vez */
    private $url_editar = null;

    // ------------------------------------------------------------------
    // Lo que define cada recurso
    // ------------------------------------------------------------------

    /** @return string post_type del recurso. */
    abstract protected function post_type();

    /** @return string slug de la Página de edición, p. ej. 'comite-editar'. */
    abstract protected function slug_editar();

    /** @return string título con el que se siembra la Página de edición. */
    abstract protected function titulo_pagina();

    /** @return string título de la pantalla según sea alta o edición. */
    abstract public function titulo_formulario($editando);

    /** @return string[] nombres de los campos del formulario que se recuerdan si falla. */
    abstract protected function campos_formulario();

    /**
     * Lee y valida el formulario ($_POST).
     *
     * @param int $post_id 0 si es un alta.
     * @return array{args:array,meta:array}|string Campos de wp_insert_post
     *         (sin tipo, estado ni autor) y metas a guardar; o el código
     *         de error si algo no valida.
     */
    abstract protected function leer($post_id);

    /**
     * @param \WP_Post $post
     * @return array Valores actuales del recurso, por nombre de campo del formulario.
     */
    abstract protected function valores_de($post);

    /**
     * @return array Valores iniciales de un alta (por omisión, ninguno).
     */
    protected function valores_nuevo()
    {
        return [];
    }

    /**
     * @return array Opciones y datos de apoyo para pintar el formulario
     *               (listas para los <select>, etc.).
     */
    protected function opciones()
    {
        return [];
    }

    /**
     * Gancho tras guardar. Devuelve argumentos extra para la URL de
     * regreso (p. ej. un aviso).
     *
     * @return array<string,string>
     */
    protected function despues_de_guardar($post_id, $creado, array $datos)
    {
        return [];
    }

    /**
     * @return array<string,scalar> parámetros de URL que el formulario
     *         necesita para reabrirse igual tras un error (p. ej. el
     *         acta en que se estaba agregando un acuerdo).
     */
    protected function args_formulario()
    {
        return [];
    }

    /** @return array<string,string> código => mensaje, propios del recurso. */
    protected function mensajes()
    {
        return [];
    }

    // ------------------------------------------------------------------

    protected function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . $this->action_save(), [$this, 'handle_save']);
        add_action('admin_post_' . $this->action_trash(), [$this, 'handle_trash']);
    }

    public function action_save()
    {
        return 'egc_sca_' . $this->post_type() . '_save';
    }

    public function action_trash()
    {
        return 'egc_sca_' . $this->post_type() . '_trash';
    }

    /**
     * @param array<string,scalar> $args parámetros de la URL (post_id, acta_id…).
     */
    public function url_editar(array $args = [])
    {
        if ($this->url_editar === null) {
            $id = Pages::get_instance()->find_or_create($this->titulo_pagina(), $this->slug_editar());
            $this->url_editar = $id ? get_permalink($id) : home_url('/');
        }

        return $args ? add_query_arg($args, $this->url_editar) : $this->url_editar;
    }

    /**
     * Adónde se vuelve por omisión: el listado de ESTE recurso.
     * Las subclases con otro listado (acuerdo) lo sobrescriben.
     */
    public function url_listado()
    {
        return Administracion::get_instance()->url($this->seccion());
    }

    /** @return string Sección de Administracion donde se lista este recurso. */
    protected function seccion()
    {
        return $this->post_type() . 's';
    }

    // ------------------------------------------------------------------
    // Guard
    // ------------------------------------------------------------------

    public function guard_access()
    {
        if (!is_page($this->slug_editar())) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $post_id = $this->post_id_pedido();

        $permitido = $post_id
            ? ($this->es_de_este_tipo($post_id) && current_user_can('edit_post', $post_id))
            : current_user_can($this->cap('edit_posts'));

        if (!$permitido) {
            wp_safe_redirect($this->url_listado());
            exit;
        }
    }

    // ------------------------------------------------------------------
    // Estado para las vistas
    // ------------------------------------------------------------------

    /**
     * @return array|null null si el `?post_id=` no es un recurso editable.
     */
    public function view_state_editar()
    {
        $post_id = $this->post_id_pedido();
        $post    = $post_id ? get_post($post_id) : null;

        if ($post_id && (!$post || !$this->es_de_este_tipo($post_id) || !current_user_can('edit_post', $post_id))) {
            return null;
        }

        $valores = $post ? $this->valores_de($post) : $this->valores_nuevo();

        return [
            'editing'      => $post ? ['id' => (int) $post->ID] : null,
            'titulo'       => $this->titulo_formulario((bool) $post),
            'valores'      => array_merge($valores, $this->entrada_recordada()),
            'opciones'     => $this->opciones(),
            'error'        => $this->mensaje_de('error'),
            'success'      => isset($_GET['ok']),
            'aviso'        => $this->mensaje_de('aviso'),
            'hoy'          => Fecha::hoy(),
            'form_action'  => admin_url('admin-post.php'),
            'action'       => $this->action_save(),
            'nonce_action' => $this->action_save(),
            'nonce_name'   => self::NONCE_NAME,
            'back_url'     => $this->back_url(),
        ];
    }

    /**
     * @return array{id:int,can_edit:bool,edit_url:string,can_trash:bool,trash_action:string,nonce_name:string}
     */
    public function actions_for($post_id)
    {
        return [
            'id'           => (int) $post_id,
            'can_edit'     => current_user_can('edit_post', $post_id),
            'edit_url'     => $this->con_regreso_aqui($this->url_editar(['post_id' => $post_id])),
            'can_trash'    => current_user_can('delete_post', $post_id),
            'trash_action' => $this->action_trash(),
            'nonce_name'   => self::NONCE_NAME,
        ];
    }

    public function can_create()
    {
        return current_user_can($this->cap('edit_posts'));
    }

    public function back_url()
    {
        $pedido = isset($_GET['volver']) ? wp_unslash($_GET['volver']) : '';

        return $pedido !== '' ? wp_validate_redirect($pedido, $this->url_listado()) : $this->url_listado();
    }

    public function con_regreso_aqui($url)
    {
        return add_query_arg('volver', rawurlencode(home_url(add_query_arg(null, null))), $url);
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    public function handle_save()
    {
        check_admin_referer($this->action_save(), self::NONCE_NAME);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!is_user_logged_in() || !current_user_can($this->cap('edit_posts'))) {
            $this->volver_con_error('forbidden', $post_id);
        }

        if ($post_id && (!$this->es_de_este_tipo($post_id) || !current_user_can('edit_post', $post_id))) {
            $this->volver_con_error('forbidden', $post_id);
        }

        $datos = $this->leer($post_id);
        if (is_string($datos)) {
            $this->recordar_entrada();
            $this->volver_con_error($datos, $post_id);
        }

        $campos = array_merge($datos['args'], [
            'post_type'   => $this->post_type(),
            'post_status' => 'publish',
            'meta_input'  => $datos['meta'],
        ]);

        // wp_insert_post()/wp_update_post() esperan datos "con slashes"
        // (como los de $_POST), y quitan una barra de cada campo.
        if ($post_id) {
            $resultado = wp_update_post(wp_slash($campos + ['ID' => $post_id]), true);
        } else {
            $resultado = wp_insert_post(wp_slash($campos + ['post_author' => $this->autor_inicial()]), true);
        }

        if (is_wp_error($resultado) || !$resultado) {
            $this->recordar_entrada();
            $this->volver_con_error('guardar_fallo', $post_id);
        }

        $this->olvidar_entrada();

        $extra = $this->despues_de_guardar((int) $resultado, !$post_id, $datos);

        $this->volver_con_ok($extra);
    }

    public function handle_trash()
    {
        check_admin_referer($this->action_trash(), self::NONCE_NAME);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !$this->es_de_este_tipo($post_id) || !current_user_can('delete_post', $post_id)) {
            $this->redirigir(add_query_arg('error', 'forbidden', $this->destino()));
        }

        // Falso si algo lo impide; en particular Cascada cancela la
        // eliminación completa si no se puede con alguno de los hijos.
        if (!wp_trash_post($post_id)) {
            $this->redirigir(add_query_arg('error', 'forbidden', $this->destino()));
        }

        $this->volver_con_ok([]);
    }

    // ------------------------------------------------------------------
    // Utilidades para las subclases
    // ------------------------------------------------------------------

    protected function cap($meta_cap)
    {
        $post_type_object = get_post_type_object($this->post_type());

        return $post_type_object ? $post_type_object->cap->$meta_cap : $meta_cap;
    }

    protected function es_de_este_tipo($post_id)
    {
        $post = get_post($post_id);

        return $post && $post->post_type === $this->post_type();
    }

    protected function post_id_pedido()
    {
        return isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
    }

    protected function texto($campo)
    {
        return isset($_POST[$campo]) && is_scalar($_POST[$campo])
            ? sanitize_text_field(wp_unslash($_POST[$campo]))
            : '';
    }

    protected function entero($campo)
    {
        return isset($_POST[$campo]) && is_scalar($_POST[$campo]) ? absint($_POST[$campo]) : 0;
    }

    protected function marcado($campo)
    {
        return !empty($_POST[$campo]);
    }

    /**
     * @return string|false '' si el campo vino vacío; la fecha AAAA-MM-DD
     *                      si es válida; false si trae algo que no es
     *                      una fecha real.
     */
    protected function fecha($campo)
    {
        $crudo = $this->texto($campo);

        if ($crudo === '') {
            return '';
        }

        $fecha = Fecha::sanitizar($crudo);

        return $fecha === '' ? false : $fecha;
    }

    /**
     * Autor con el que se crea el recurso: por omisión, quien lo
     * registra. Un recurso cuyo autor significa algo más (el asignado de
     * un acuerdo) lo redefine.
     *
     * @return int
     */
    protected function autor_inicial()
    {
        return get_current_user_id();
    }

    /** @return array<int,string> id => nombre de los comités publicados. */
    protected function opciones_comites()
    {
        return $this->opciones_de('comite');
    }

    /**
     * @return array<int,string> id => "Nombre (correo)" de las personas
     *         a quienes se puede asignar (ver Asignacion).
     */
    protected function opciones_usuarios()
    {
        return Asignacion::get_instance()->usuarios_asignables();
    }

    /** @return array<int,string> */
    protected function opciones_de($post_type)
    {
        $posts = get_posts([
            'post_type'        => $post_type,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        $opciones = [];
        foreach ($posts as $post) {
            $opciones[(int) $post->ID] = $post->post_title;
        }

        return $opciones;
    }

    // ------------------------------------------------------------------
    // Redirecciones y mensajes
    // ------------------------------------------------------------------

    private function volver_con_ok(array $extra)
    {
        $this->redirigir(add_query_arg(array_merge(['ok' => '1'], $extra), $this->destino()));
    }

    /**
     * Un error de validación vuelve al FORMULARIO (con lo escrito); un
     * "no permitido" vuelve al listado.
     */
    private function volver_con_error($codigo, $post_id = 0)
    {
        $url = $codigo === 'forbidden'
            ? $this->destino()
            : $this->url_editar(array_filter(array_merge(
                ['post_id' => $post_id, 'volver' => rawurlencode($this->destino())],
                $this->args_formulario()
            )));

        $this->redirigir(add_query_arg('error', $codigo, $url));
    }

    protected function redirigir($url)
    {
        wp_safe_redirect($url);
        exit;
    }

    protected function destino()
    {
        $pedido = isset($_POST['redirect_to']) ? wp_unslash($_POST['redirect_to']) : '';

        return $pedido !== '' ? wp_validate_redirect($pedido, $this->back_url()) : $this->back_url();
    }

    private function mensaje_de($parametro)
    {
        $codigo = isset($_GET[$parametro]) ? sanitize_key($_GET[$parametro]) : '';

        if ($codigo === '') {
            return '';
        }

        $todos = array_merge($this->mensajes_comunes(), $this->mensajes());

        return $todos[$codigo] ?? '';
    }

    private function mensajes_comunes()
    {
        return [
            'forbidden'      => __('No tenés permiso para hacer eso.', 'egc'),
            'guardar_fallo'  => __('No se pudo guardar. Intentá de nuevo.', 'egc'),
            'fecha_invalida' => __('Alguna de las fechas no es válida.', 'egc'),
        ];
    }

    // ------------------------------------------------------------------
    // Entrada recordada (para no perder lo escrito si algo no valida)
    // ------------------------------------------------------------------

    private function clave_entrada()
    {
        return 'egc_sca_form_' . get_current_user_id() . '_' . $this->post_type();
    }

    private function recordar_entrada()
    {
        $entrada = [];

        foreach ($this->campos_formulario() as $campo) {
            if (isset($_POST[$campo]) && is_scalar($_POST[$campo])) {
                $entrada[$campo] = (string) wp_unslash($_POST[$campo]);
            }
        }

        set_transient($this->clave_entrada(), $entrada, self::SEGUNDOS_ENTRADA_RECORDADA);
    }

    private function olvidar_entrada()
    {
        delete_transient($this->clave_entrada());
    }

    /**
     * Solo se recupera cuando se vuelve de un error (`?error=` en la
     * URL), y se consume: un formulario abierto más tarde no debe
     * precargar un borrador viejo.
     */
    private function entrada_recordada()
    {
        if (!isset($_GET['error'])) {
            return [];
        }

        $entrada = get_transient($this->clave_entrada());
        delete_transient($this->clave_entrada());

        return is_array($entrada) ? $entrada : [];
    }
}
