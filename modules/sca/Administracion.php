<?php

namespace EGC\Modules\Sca;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Acta\ActaManagement;
use EGC\Modules\Sca\Acuerdo\Acuerdo;
use EGC\Modules\Sca\Acuerdo\AcuerdoManagement;
use EGC\Modules\Sca\Acuerdo\Asignacion;
use EGC\Modules\Sca\Comite\Comite;
use EGC\Modules\Sca\Comite\ComiteManagement;
use EGC\Modules\Sca\Miembro\Miembro;
use EGC\Modules\Sca\Miembro\MiembroManagement;
use EGC\Modules\Sca\Puesto\Puesto;
use EGC\Modules\Sca\Puesto\PuestoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica (el puente hacia la vista) — pantalla de administración
 * del módulo SCA: los listados de comités, puestos, miembros y actas,
 * y la herramienta de reasignación masiva (módulo SCA).
 *
 * Existe porque los lectores navegan SOLO acuerdos (/acuerdo/), pero el
 * administrador necesita llegar a los otros cuatro recursos, que no
 * tienen front-end público. WordPress no tiene nada equivalente fuera
 * de wp-admin, al que AdminGuard no deja entrar a nadie salvo al
 * superusuario. Es una Página nativa sembrada (`sca-administracion`)
 * con una sola vista; las secciones se eligen con `?seccion=`.
 *
 * No crea ni borra nada: cada fila pide a su propio *Management los
 * botones de editar/eliminar (actions_for) y los enlaces de alta
 * (url_editar), de modo que la autorización vive en un solo lugar.
 * Esta clase solo ARMA los datos de los listados para que la vista
 * pinte sin consultas ni conocer capacidades.
 *
 * Acceso: solo quien administra el módulo (Alcance::administra).
 */
class Administracion
{
    use Singleton;

    const SLUG = 'sca-administracion';

    const POR_PAGINA = 20;

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
    }

    /**
     * @param string $seccion comites|puestos|miembros|actas|herramientas
     */
    public function url($seccion = '')
    {
        if ($this->url === null) {
            $id        = Pages::get_instance()->find_or_create(__('Administración de acuerdos', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $seccion ? add_query_arg('seccion', $seccion, $this->url) : $this->url;
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

        if (!Alcance::get_instance()->administra(get_current_user_id())) {
            $archivo = get_post_type_archive_link(Acuerdo::POST_TYPE);
            wp_safe_redirect($archivo ?: home_url('/'));
            exit;
        }
    }

    /**
     * @return array<string,string> clave de sección => rótulo.
     */
    public function secciones()
    {
        return [
            'actas'        => __('Actas y minutas', 'egc'),
            'comites'      => __('Comités', 'egc'),
            'puestos'      => __('Puestos', 'egc'),
            'miembros'     => __('Miembros', 'egc'),
            'herramientas' => __('Herramientas', 'egc'),
        ];
    }

    /**
     * @return array{
     *   seccion:string, titulo:string,
     *   tabs:array<int,array{label:string,url:string,activa:bool}>,
     *   columnas:string[], filas:array<int,array>, nuevo_url:string, nuevo_etiqueta:string,
     *   paginacion:?array, herramientas:?array,
     *   ok:bool, error:string, cantidad:int, acuerdos_url:string
     * }
     */
    public function view_state()
    {
        $seccion = $this->seccion_pedida();
        $listado = $this->listado($seccion);

        return [
            'seccion'        => $seccion,
            'titulo'         => $this->secciones()[$seccion],
            'tabs'           => $this->tabs($seccion),
            'columnas'       => $listado['columnas'],
            'filas'          => $listado['filas'],
            'nuevo_url'      => $listado['nuevo_url'],
            'nuevo_etiqueta' => $listado['nuevo_etiqueta'],
            'paginacion'     => $listado['paginacion'],
            'herramientas'   => $seccion === 'herramientas' ? $this->herramientas() : null,
            'ok'             => isset($_GET['ok']),
            'error'          => $this->mensaje_error(),
            'cantidad'       => isset($_GET['cantidad']) ? absint($_GET['cantidad']) : 0,
            'acuerdos_url'   => get_post_type_archive_link(Acuerdo::POST_TYPE) ?: home_url('/'),
        ];
    }

    // ------------------------------------------------------------------

    private function seccion_pedida()
    {
        $seccion = isset($_GET['seccion']) ? sanitize_key($_GET['seccion']) : '';

        return isset($this->secciones()[$seccion]) ? $seccion : 'actas';
    }

    private function tabs($activa)
    {
        $tabs = [];

        foreach ($this->secciones() as $clave => $rotulo) {
            $tabs[] = ['label' => $rotulo, 'url' => $this->url($clave), 'activa' => $clave === $activa];
        }

        return $tabs;
    }

    private function mensaje_error()
    {
        $codigo = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';
        if ($codigo === '') {
            return '';
        }

        $mensajes = array_merge(
            ['forbidden' => __('No tenés permiso para hacer eso.', 'egc')],
            AcuerdoManagement::mensajes_asignacion()
        );

        return $mensajes[$codigo] ?? '';
    }

    /**
     * @return array{columnas:string[],filas:array,nuevo_url:string,nuevo_etiqueta:string,paginacion:?array}
     */
    private function listado($seccion)
    {
        $vacio = ['columnas' => [], 'filas' => [], 'nuevo_url' => '', 'nuevo_etiqueta' => '', 'paginacion' => null];

        switch ($seccion) {
            case 'comites':
                return $this->listar_comites();
            case 'puestos':
                return $this->listar_puestos();
            case 'miembros':
                return $this->listar_miembros();
            case 'actas':
                return $this->listar_actas();
        }

        return $vacio;
    }

    private function consulta($post_type, array $orden)
    {
        $pagina = isset($_GET['pagina']) ? max(1, absint($_GET['pagina'])) : 1;

        return new \WP_Query(array_merge([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => self::POR_PAGINA,
            'paged'          => $pagina,
        ], $orden));
    }

    private function paginacion(\WP_Query $consulta)
    {
        $total = (int) ceil($consulta->found_posts / self::POR_PAGINA);

        if ($total <= 1) {
            return null;
        }

        return [
            'actual' => isset($_GET['pagina']) ? max(1, absint($_GET['pagina'])) : 1,
            'total'  => $total,
            'base'   => add_query_arg('pagina', '%#%', $this->url($this->seccion_pedida())),
        ];
    }

    private function fila($id, array $celdas, $manager, array $enlaces = [])
    {
        $acciones = $manager->actions_for($id);

        // Tras eliminar, quedarse en esta misma pantalla (sección y página).
        $acciones['redirect_to'] = home_url(add_query_arg(null, null));

        return [
            'id'       => (int) $id,
            'celdas'   => $celdas,
            'acciones' => ($acciones['can_edit'] || $acciones['can_trash']) ? $acciones : null,
            'enlaces'  => $enlaces,
        ];
    }

    private function listar_comites()
    {
        $manager  = ComiteManagement::get_instance();
        $consulta = $this->consulta(Comite::POST_TYPE, ['orderby' => 'title', 'order' => 'ASC']);
        $filas    = [];

        foreach ($consulta->posts as $comite) {
            $filas[] = $this->fila($comite->ID, [
                $comite->post_title,
                Comite::alcance_total($comite->ID) ? ['texto' => __('Alcance total', 'egc'), 'badge' => 'primary'] : '—',
                (string) max(1, (int) get_post_meta($comite->ID, Comite::META_PROXIMO_ACTA, true)),
                (string) max(1, (int) get_post_meta($comite->ID, Comite::META_PROXIMO_ACUERDO, true)),
            ], $manager);
        }

        return [
            'columnas'       => [__('Nombre', 'egc'), __('Visibilidad', 'egc'), __('Próx. acta', 'egc'), __('Próx. acuerdo', 'egc')],
            'filas'          => $filas,
            'nuevo_url'      => $manager->can_create() ? $manager->con_regreso_aqui($manager->url_editar()) : '',
            'nuevo_etiqueta' => __('Agregar comité', 'egc'),
            'paginacion'     => $this->paginacion($consulta),
        ];
    }

    private function listar_puestos()
    {
        $manager  = PuestoManagement::get_instance();
        $consulta = $this->consulta(Puesto::POST_TYPE, ['orderby' => 'title', 'order' => 'ASC']);
        $filas    = [];

        foreach ($consulta->posts as $puesto) {
            $filas[] = $this->fila($puesto->ID, [
                $puesto->post_title,
                $puesto->post_parent ? Comite::nombre($puesto->post_parent) : __('Todos los comités', 'egc'),
                Puesto::coordina($puesto->ID) ? ['texto' => __('Ve todo su comité', 'egc'), 'badge' => 'info'] : '—',
            ], $manager);
        }

        return [
            'columnas'       => [__('Nombre', 'egc'), __('Comité', 'egc'), __('Coordina', 'egc')],
            'filas'          => $filas,
            'nuevo_url'      => $manager->can_create() ? $manager->con_regreso_aqui($manager->url_editar()) : '',
            'nuevo_etiqueta' => __('Agregar puesto', 'egc'),
            'paginacion'     => $this->paginacion($consulta),
        ];
    }

    private function listar_miembros()
    {
        $manager  = MiembroManagement::get_instance();
        $consulta = $this->consulta(Miembro::POST_TYPE, ['orderby' => 'title', 'order' => 'ASC']);
        $filas    = [];

        foreach ($consulta->posts as $miembro) {
            $filas[] = $this->fila($miembro->ID, [
                $this->persona_de($miembro->ID),
                Comite::nombre($miembro->post_parent),
                get_the_title((int) get_post_meta($miembro->ID, Miembro::META_PUESTO, true)),
                $this->vigencia_de($miembro),
            ], $manager);
        }

        return [
            'columnas'       => [__('Persona', 'egc'), __('Comité', 'egc'), __('Puesto', 'egc'), __('Vigencia', 'egc')],
            'filas'          => $filas,
            'nuevo_url'      => $manager->can_create() ? $manager->con_regreso_aqui($manager->url_editar()) : '',
            'nuevo_etiqueta' => __('Agregar miembro', 'egc'),
            'paginacion'     => $this->paginacion($consulta),
        ];
    }

    private function listar_actas()
    {
        $manager         = ActaManagement::get_instance();
        $acuerdo_manager = AcuerdoManagement::get_instance();
        $consulta        = $this->consulta(Acta::POST_TYPE, ['orderby' => 'date', 'order' => 'DESC']);
        $cantidades      = $this->acuerdos_por_acta(wp_list_pluck($consulta->posts, 'ID'));
        $filas           = [];

        foreach ($consulta->posts as $acta) {
            $enlaces = [];
            if ($acuerdo_manager->can_create()) {
                $enlaces[] = [
                    'label' => __('Agregar acuerdo', 'egc'),
                    'icon'  => 'plus-lg',
                    'url'   => $acuerdo_manager->con_regreso_aqui($acuerdo_manager->url_editar(['acta_id' => $acta->ID])),
                ];
            }

            $filas[] = $this->fila($acta->ID, [
                $acta->post_title,
                substr($acta->post_date, 0, 10),
                (string) ($cantidades[$acta->ID] ?? 0),
            ], $manager, $enlaces);
        }

        return [
            'columnas'       => [__('Acta o minuta', 'egc'), __('Fecha de la reunión', 'egc'), __('Acuerdos', 'egc')],
            'filas'          => $filas,
            'nuevo_url'      => $manager->can_create() ? $manager->con_regreso_aqui($manager->url_editar()) : '',
            'nuevo_etiqueta' => __('Agregar acta o minuta', 'egc'),
            'paginacion'     => $this->paginacion($consulta),
        ];
    }

    /**
     * @param int[] $actas_ids
     * @return array<int,int> acta_id => cantidad de acuerdos publicados.
     */
    private function acuerdos_por_acta(array $actas_ids)
    {
        if (!$actas_ids) {
            return [];
        }

        $cantidades = [];

        foreach (get_posts([
            'post_type'          => Acuerdo::POST_TYPE,
            'post_status'        => 'publish',
            'post_parent__in'    => $actas_ids,
            'posts_per_page'     => -1,
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
        ]) as $acuerdo) {
            $cantidades[(int) $acuerdo->post_parent] = ($cantidades[(int) $acuerdo->post_parent] ?? 0) + 1;
        }

        return $cantidades;
    }

    private function persona_de($miembro_id)
    {
        $usuario = get_userdata((int) get_post_meta($miembro_id, Miembro::META_USUARIO, true));

        if ($usuario) {
            return $usuario->display_name;
        }

        $correo = (string) get_post_meta($miembro_id, Miembro::META_EMAIL, true);

        return $correo !== ''
            ? sprintf(__('%s (pendiente de ingreso)', 'egc'), $correo)
            : '—';
    }

    /**
     * @return string|array Texto, o texto + badge cuando la vigencia tiene efecto (Junta).
     */
    private function vigencia_de($miembro)
    {
        $desde = (string) get_post_meta($miembro->ID, Miembro::META_DESDE, true);
        $hasta = (string) get_post_meta($miembro->ID, Miembro::META_HASTA, true);
        $rango = ($desde !== '' || $hasta !== '') ? sprintf('%s → %s', $desde ?: '…', $hasta ?: '…') : '—';

        if (!Comite::alcance_total($miembro->post_parent)) {
            return $rango;
        }

        return Miembro::vigente($miembro->ID)
            ? ['texto' => $rango . ' · ' . __('vigente', 'egc'), 'badge' => 'success']
            : ['texto' => $rango . ' · ' . __('no vigente', 'egc'), 'badge' => 'secondary'];
    }

    /**
     * Datos de la herramienta de reasignación masiva.
     *
     * @return array{action:string,nonce_action:string,nonce_name:string,form_action:string,origenes:array<int,string>,destinos:array<int,string>}
     */
    private function herramientas()
    {
        return [
            'action'       => AcuerdoManagement::ACTION_REASIGNAR,
            'nonce_action' => AcuerdoManagement::ACTION_REASIGNAR,
            'nonce_name'   => RecursoManagement::NONCE_NAME,
            'form_action'  => admin_url('admin-post.php'),
            'origenes'     => $this->usuarios_con_acuerdos(),
            'destinos'     => Asignacion::get_instance()->usuarios_asignables(),
        ];
    }

    /**
     * Quiénes tienen acuerdos a su nombre y cuántos (para elegir de
     * quién reasignar). WP_Query no tiene un modo "autores distintos",
     * así que se agrupa leyendo el autor de cada acuerdo.
     *
     * @return array<int,string> user_id => "Nombre (correo) — N acuerdos"
     */
    private function usuarios_con_acuerdos()
    {
        $cuentas = [];

        foreach (get_posts([
            'post_type'          => Acuerdo::POST_TYPE,
            'post_status'        => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page'     => -1,
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
        ]) as $acuerdo) {
            $autor = (int) $acuerdo->post_author;
            $cuentas[$autor] = ($cuentas[$autor] ?? 0) + 1;
        }

        $alcance  = Alcance::get_instance();
        $opciones = [];

        foreach ($cuentas as $user_id => $cantidad) {
            $usuario = get_userdata($user_id);
            if ($usuario && !$alcance->administra($user_id)) {
                $opciones[$user_id] = sprintf(
                    /* translators: 1: nombre, 2: correo, 3: cantidad de acuerdos */
                    _n('%1$s (%2$s) — %3$d acuerdo', '%1$s (%2$s) — %3$d acuerdos', $cantidad, 'egc'),
                    $usuario->display_name,
                    $usuario->user_email,
                    $cantidad
                );
            }
        }

        asort($opciones);

        return $opciones;
    }
}
