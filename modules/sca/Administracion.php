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
use EGC\Modules\Sca\Acuerdo\Consulta;
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
 * Capa Puente — pantalla de administración del módulo SCA: el
 * mantenimiento de comités → actas → acuerdos, el de puestos y
 * miembros, y la herramienta de reasignación masiva (módulo SCA).
 *
 * Existe porque los lectores navegan SOLO acuerdos (/acuerdo/), pero el
 * administrador necesita llegar a los otros cuatro recursos, que no
 * tienen front-end público. WordPress no tiene nada equivalente fuera
 * de wp-admin, al que AdminGuard no deja entrar a nadie salvo al
 * superusuario. Es una Página nativa sembrada (`sca-administracion`)
 * con una sola vista; las secciones se eligen con `?seccion=`.
 *
 * Mantenimiento por niveles (comités, actas, acuerdos), de arriba a
 * abajo y siempre en ese orden:
 *
 *   1. `?seccion=comites`             tarjetas de comités. El título lleva a:
 *   2. `?seccion=comites&comite=ID`   tarjetas de las actas de ESE comité.
 *                                     El título del acta lleva a:
 *   3. /acuerdo/?sca_acta=ID          el listado de acuerdos de ESE acta
 *                                     (las tarjetas de siempre, con su
 *                                     barra lateral y las migas de pan).
 *
 * Cada nivel permite agregar el recurso que lista (si la capacidad lo
 * permite: ocultar un botón es presentación, el handler revalida) y
 * cada tarjeta trae editar y eliminar. Eliminar es en cascada (ver
 * Cascada) y la tarjeta avisa cuánto se llevaría por delante. Puestos y
 * miembros son otro mantenimiento, en pestañas aparte.
 *
 * No crea ni borra nada: cada tarjeta pide a su propio *Management los
 * botones de editar/eliminar (actions_for) y los enlaces de alta
 * (url_editar), de modo que la autorización vive en un solo lugar.
 * Esta clase solo ARMA los datos para que la vista pinte sin consultas
 * ni conocer capacidades.
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
     * @param string               $seccion comites|puestos|miembros|herramientas
     * @param array<string,scalar> $args    parámetros extra de la URL (comite…).
     */
    public function url($seccion = '', array $args = [])
    {
        if ($this->url === null) {
            $id        = Pages::get_instance()->find_or_create(__('Administración de acuerdos', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        $url = $seccion ? add_query_arg('seccion', $seccion, $this->url) : $this->url;

        return $args ? add_query_arg($args, $url) : $url;
    }

    /**
     * Nivel 2: las actas de un comité.
     */
    public function url_comite($comite_id)
    {
        return $this->url('comites', ['comite' => (int) $comite_id]);
    }

    /**
     * Nivel 3: los acuerdos de un acta (el archivo de acuerdos filtrado).
     */
    public function url_acta($acta_id)
    {
        $archivo = get_post_type_archive_link(Acuerdo::POST_TYPE) ?: home_url('/');

        return add_query_arg(Consulta::VAR_ACTA, (int) $acta_id, $archivo);
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
            'comites'      => __('Comités, actas y acuerdos', 'egc'),
            'puestos'      => __('Puestos', 'egc'),
            'miembros'     => __('Miembros', 'egc'),
            'herramientas' => __('Herramientas', 'egc'),
        ];
    }

    /**
     * @return array{
     *   seccion:string, titulo:string, modo:string,
     *   tabs:array<int,array{label:string,url:string,activa:bool}>,
     *   migas:array<int,array{label:string,url:string}>,
     *   columnas:string[], filas:array<int,array>, tarjetas:array<int,array>,
     *   nuevo_url:string, nuevo_etiqueta:string, vacio:string,
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
            'titulo'         => $listado['titulo'],
            'modo'           => $listado['modo'],
            'tabs'           => $this->tabs($seccion),
            'migas'          => $listado['migas'],
            'columnas'       => $listado['columnas'],
            'filas'          => $listado['filas'],
            'tarjetas'       => $listado['tarjetas'],
            'nuevo_url'      => $listado['nuevo_url'],
            'nuevo_etiqueta' => $listado['nuevo_etiqueta'],
            'vacio'          => $listado['vacio'],
            'paginacion'     => $listado['paginacion'],
            'herramientas'   => $seccion === 'herramientas' ? $this->herramientas() : null,
            'ok'             => isset($_GET['ok']),
            'error'          => $listado['error'] !== '' ? $listado['error'] : $this->mensaje_error(),
            'cantidad'       => isset($_GET['cantidad']) ? absint($_GET['cantidad']) : 0,
            'acuerdos_url'   => get_post_type_archive_link(Acuerdo::POST_TYPE) ?: home_url('/'),
        ];
    }

    // ------------------------------------------------------------------

    private function seccion_pedida()
    {
        $seccion = isset($_GET['seccion']) ? sanitize_key($_GET['seccion']) : '';

        return isset($this->secciones()[$seccion]) ? $seccion : 'comites';
    }

    /**
     * @return int El comité pedido en `?comite=` si es un comité publicado; 0 si no.
     */
    private function comite_pedido()
    {
        $comite_id = isset($_GET['comite']) ? absint($_GET['comite']) : 0;

        return ($comite_id && get_post_type($comite_id) === Comite::POST_TYPE && get_post_status($comite_id) === 'publish')
            ? $comite_id
            : 0;
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
     * @return array{
     *   titulo:string,modo:string,migas:array,columnas:string[],filas:array,tarjetas:array,
     *   nuevo_url:string,nuevo_etiqueta:string,vacio:string,paginacion:?array,error:string
     * }
     */
    private function listado($seccion)
    {
        switch ($seccion) {
            case 'comites':
                return isset($_GET['comite']) ? $this->listar_actas_de_comite() : $this->listar_comites();
            case 'puestos':
                return $this->listar_puestos();
            case 'miembros':
                return $this->listar_miembros();
        }

        return $this->base_listado($this->secciones()[$seccion], 'herramientas');
    }

    private function base_listado($titulo, $modo = 'tabla')
    {
        return [
            'titulo'         => $titulo,
            'modo'           => $modo,
            'migas'          => [],
            'columnas'       => [],
            'filas'          => [],
            'tarjetas'       => [],
            'nuevo_url'      => '',
            'nuevo_etiqueta' => '',
            'vacio'          => __('Todavía no hay registros.', 'egc'),
            'paginacion'     => null,
            'error'          => '',
        ];
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

    private function paginacion(\WP_Query $consulta, $url_base)
    {
        $total = (int) ceil($consulta->found_posts / self::POR_PAGINA);

        if ($total <= 1) {
            return null;
        }

        return [
            'actual' => isset($_GET['pagina']) ? max(1, absint($_GET['pagina'])) : 1,
            'total'  => $total,
            'base'   => add_query_arg('pagina', '%#%', $url_base),
        ];
    }

    /**
     * Editar / eliminar de un registro, o null si el usuario no puede
     * ninguna de las dos (la vista no pinta nada).
     */
    private function acciones_de($id, $manager)
    {
        $acciones = $manager->actions_for($id);

        // Tras eliminar, quedarse en esta misma pantalla (sección, nivel y página).
        $acciones['redirect_to'] = home_url(add_query_arg(null, null));

        return ($acciones['can_edit'] || $acciones['can_trash']) ? $acciones : null;
    }

    private function fila($id, array $celdas, $manager)
    {
        return [
            'id'       => (int) $id,
            'celdas'   => $celdas,
            'acciones' => $this->acciones_de($id, $manager),
        ];
    }

    /**
     * Una tarjeta de los niveles comités / actas.
     *
     * @param array<int,array{0:string,1:string}> $detalles pares rótulo => valor.
     * @param array<int,array{texto:string,badge:string}> $insignias
     */
    private function tarjeta($id, $titulo, $url, array $detalles, array $insignias, $aviso, $manager)
    {
        return [
            'id'        => (int) $id,
            'titulo'    => $titulo,
            'url'       => $url,
            'detalles'  => $detalles,
            'insignias' => $insignias,
            'aviso'     => $aviso,
            'acciones'  => $this->acciones_de($id, $manager),
        ];
    }

    /**
     * Texto de la tarjeta sobre lo que arrastra eliminar (Cascada).
     * Vacío si no arrastra nada.
     */
    private function aviso_cascada($actas, $acuerdos)
    {
        $partes = [];

        if ($actas > 0) {
            /* translators: %s: cantidad de actas */
            $partes[] = sprintf(_n('%s acta o minuta', '%s actas o minutas', $actas, 'egc'), number_format_i18n($actas));
        }
        if ($acuerdos > 0) {
            /* translators: %s: cantidad de acuerdos */
            $partes[] = sprintf(_n('%s acuerdo', '%s acuerdos', $acuerdos, 'egc'), number_format_i18n($acuerdos));
        }

        return $partes
            /* translators: %s: lista de lo que se elimina junto con el registro */
            ? sprintf(__('Al eliminarlo también se eliminan: %s.', 'egc'), implode(' y ', $partes))
            : '';
    }

    /**
     * Nivel 1: los comités.
     */
    private function listar_comites()
    {
        $manager  = ComiteManagement::get_instance();
        $consulta = $this->consulta(Comite::POST_TYPE, ['orderby' => 'title', 'order' => 'ASC']);
        $listado  = $this->base_listado(__('Comités', 'egc'), 'tarjetas');

        foreach ($consulta->posts as $comite) {
            $arrastra = Cascada::get_instance()->resumen($comite->ID);
            $puesto   = Comite::puesto_coordinador($comite->ID);

            $listado['tarjetas'][] = $this->tarjeta(
                $comite->ID,
                $comite->post_title,
                $this->url_comite($comite->ID),
                [
                    [__('Coordina', 'egc'), $puesto ? get_the_title($puesto) : '—'],
                    [__('Actas o minutas', 'egc'), (string) $arrastra['actas']],
                    [__('Acuerdos', 'egc'), (string) $arrastra['acuerdos']],
                    [__('Próx. acta', 'egc'), (string) max(1, (int) get_post_meta($comite->ID, Comite::META_PROXIMO_ACTA, true))],
                    [__('Próx. acuerdo', 'egc'), (string) max(1, (int) get_post_meta($comite->ID, Comite::META_PROXIMO_ACUERDO, true))],
                ],
                Comite::alcance_total($comite->ID) ? [['texto' => __('Alcance total', 'egc'), 'badge' => 'primary']] : [],
                $this->aviso_cascada($arrastra['actas'], $arrastra['acuerdos']),
                $manager
            );
        }

        $listado['nuevo_url']      = $manager->can_create() ? $manager->con_regreso_aqui($manager->url_editar()) : '';
        $listado['nuevo_etiqueta'] = __('Agregar comité', 'egc');
        $listado['vacio']          = __('Todavía no hay comités.', 'egc');
        $listado['paginacion']     = $this->paginacion($consulta, $this->url('comites'));

        return $listado;
    }

    private function listar_puestos()
    {
        $manager  = PuestoManagement::get_instance();
        $consulta = $this->consulta(Puesto::POST_TYPE, ['orderby' => 'title', 'order' => 'ASC']);
        $listado  = $this->base_listado(__('Puestos', 'egc'));

        foreach ($consulta->posts as $puesto) {
            $listado['filas'][] = $this->fila($puesto->ID, [
                $puesto->post_title,
                $puesto->post_parent ? Comite::nombre($puesto->post_parent) : __('Sin comité asignado', 'egc'),
            ], $manager);
        }

        $listado['columnas']       = [__('Nombre', 'egc'), __('Comité', 'egc')];
        $listado['nuevo_url']      = $manager->can_create() ? $manager->con_regreso_aqui($manager->url_editar()) : '';
        $listado['nuevo_etiqueta'] = __('Agregar puesto', 'egc');
        $listado['paginacion']     = $this->paginacion($consulta, $this->url('puestos'));

        return $listado;
    }

    private function listar_miembros()
    {
        $manager  = MiembroManagement::get_instance();
        $consulta = $this->consulta(Miembro::POST_TYPE, ['orderby' => 'title', 'order' => 'ASC']);
        $listado  = $this->base_listado(__('Miembros', 'egc'));

        foreach ($consulta->posts as $miembro) {
            $listado['filas'][] = $this->fila($miembro->ID, [
                $this->persona_de($miembro->ID),
                Comite::nombre($miembro->post_parent),
                get_the_title((int) get_post_meta($miembro->ID, Miembro::META_PUESTO, true)),
                $this->vigencia_de($miembro),
            ], $manager);
        }

        $listado['columnas']       = [__('Persona', 'egc'), __('Comité', 'egc'), __('Puesto', 'egc'), __('Vigencia', 'egc')];
        $listado['nuevo_url']      = $manager->can_create() ? $manager->con_regreso_aqui($manager->url_editar()) : '';
        $listado['nuevo_etiqueta'] = __('Agregar miembro', 'egc');
        $listado['paginacion']     = $this->paginacion($consulta, $this->url('miembros'));

        return $listado;
    }

    /**
     * Nivel 2: las actas o minutas de UN comité. Un `?comite=` que no
     * es un comité publicado no rompe nada: se explica y se vuelve a
     * mostrar el nivel 1.
     */
    private function listar_actas_de_comite()
    {
        $comite_id = $this->comite_pedido();

        if (!$comite_id) {
            $listado          = $this->listar_comites();
            $listado['error'] = __('No se encontró ese comité.', 'egc');

            return $listado;
        }

        $manager    = ActaManagement::get_instance();
        $consulta   = $this->consulta(Acta::POST_TYPE, ['orderby' => 'date', 'order' => 'DESC', 'post_parent' => $comite_id]);
        $cantidades = $this->acuerdos_por_acta(wp_list_pluck($consulta->posts, 'ID'));
        $nombre     = Comite::nombre($comite_id);
        $listado    = $this->base_listado(
            /* translators: %s: nombre del comité */
            sprintf(__('Actas y minutas de %s', 'egc'), $nombre),
            'tarjetas'
        );

        foreach ($consulta->posts as $acta) {
            $acuerdos = (int) ($cantidades[$acta->ID] ?? 0);

            $listado['tarjetas'][] = $this->tarjeta(
                $acta->ID,
                $acta->post_title,
                $this->url_acta($acta->ID),
                [
                    [__('Fecha de la reunión', 'egc'), substr($acta->post_date, 0, 10)],
                    [__('Acuerdos', 'egc'), (string) $acuerdos],
                ],
                [],
                $this->aviso_cascada(0, $acuerdos),
                $manager
            );
        }

        $listado['migas'] = [
            ['label' => __('Comités', 'egc'), 'url' => $this->url('comites')],
            ['label' => $nombre, 'url' => ''],
        ];
        $listado['nuevo_url']      = $manager->can_create()
            ? $manager->con_regreso_aqui($manager->url_editar(['comite_id' => $comite_id]))
            : '';
        $listado['nuevo_etiqueta'] = __('Agregar acta o minuta', 'egc');
        $listado['vacio']          = __('Este comité todavía no tiene actas ni minutas.', 'egc');
        $listado['paginacion']     = $this->paginacion($consulta, $this->url_comite($comite_id));

        return $listado;
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
     * @return string|array Texto, o texto + clase de la insignia.
     *
     * En un comité de alcance total (Junta) la vigencia TIENE efecto
     * (da o quita el acceso): insignia sólida, regla estricta
     * (Miembro::vigente). En los demás comités las fechas son
     * informativas (la membresía vale mientras exista): insignia de
     * contorno y rótulo "en fechas" / "fuera de fechas", para que no
     * se confunda con la de la Junta. Aquí una fecha puede faltar (solo
     * "desde" o solo "hasta"): el extremo vacío no limita. Sin ninguna
     * fecha no hay nada que calificar y se muestra solo el guion.
     */
    private function vigencia_de($miembro)
    {
        $desde = (string) get_post_meta($miembro->ID, Miembro::META_DESDE, true);
        $hasta = (string) get_post_meta($miembro->ID, Miembro::META_HASTA, true);
        $rango = ($desde !== '' || $hasta !== '') ? sprintf('%s → %s', $desde ?: '…', $hasta ?: '…') : '—';

        if (Comite::alcance_total($miembro->post_parent)) {
            return Miembro::vigente($miembro->ID)
                ? ['texto' => $rango . ' · ' . __('vigente', 'egc'), 'clase' => 'text-bg-success']
                : ['texto' => $rango . ' · ' . __('no vigente', 'egc'), 'clase' => 'text-bg-secondary'];
        }

        if ($desde === '' && $hasta === '') {
            return $rango;
        }

        $hoy      = Fecha::hoy();
        $en_fecha = ($desde === '' || $desde <= $hoy) && ($hasta === '' || $hoy <= $hasta);

        return $en_fecha
            ? ['texto' => $rango . ' · ' . __('en fechas', 'egc'), 'clase' => 'border border-success text-success']
            : ['texto' => $rango . ' · ' . __('fuera de fechas', 'egc'), 'clase' => 'border border-secondary text-secondary'];
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
