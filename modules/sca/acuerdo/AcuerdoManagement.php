<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\LoginPage;
use EGC\Core\Singleton;
use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Administracion;
use EGC\Modules\Sca\Alcance;
use EGC\Modules\Sca\Fecha;
use EGC\Modules\Sca\RecursoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — CRUD de Acuerdo en el front-end (módulo SCA), más dos
 * acciones propias: la reasignación masiva y "marcar ejecutado".
 *
 * Campos que escribe el administrador:
 *  - Acta (solo al crear, y siempre desde "agregar acuerdo" de un acta;
 *    después es inmutable): de ella salen comité, fecha y número.
 *  - Texto del acuerdo (wp_editor → post_content), obligatorio.
 *  - Fecha de compromiso, obligatoria.
 *  - Fecha de ejecución: la marca SOLO el administrador (este
 *    formulario es solo de administradores); vacía = no ejecutado. Es
 *    el camino para corregir la fecha; el camino normal es el botón
 *    "Marcar ejecutado" (handle_ejecutar), que pone la de hoy.
 *  - Asignado: un usuario ya enrolado en el módulo (cuenta activa y
 *    acceso a SCA). Asignacion valida la regla; este formulario solo
 *    ofrece a quienes la cumplen. No se asigna a correos sin cuenta ni
 *    se da acceso a nadie.
 *
 * Qué cambió respecto de lo que el formulario manda, en cada
 * guardado, se decide comparando con el estado ACTUAL: así volver a
 * guardar el formulario con el mismo asignado no reasigna nada.
 *
 * El acuerdo se crea con comentarios abiertos (`comment_status`): el
 * valor por omisión del sitio suele ser cerrado, y sin esto nadie
 * podría comentar.
 *
 * Si el acuerdo se guardó pero la asignación fue rechazada (p. ej. se
 * eligió a quien administra el módulo) se vuelve con el aviso de ambas
 * cosas: el texto del acuerdo no se pierde.
 */
class AcuerdoManagement extends RecursoManagement
{
    use Singleton;

    const ACTION_REASIGNAR = 'egc_sca_reasignar';
    const ACTION_EJECUTAR  = 'egc_sca_ejecutar';

    private function __construct()
    {
        parent::__construct();
        add_action('admin_post_' . self::ACTION_REASIGNAR, [$this, 'handle_reasignar']);
        add_action('admin_post_' . self::ACTION_EJECUTAR, [$this, 'handle_ejecutar']);
    }

    protected function post_type()
    {
        return Acuerdo::POST_TYPE;
    }

    protected function slug_editar()
    {
        return 'acuerdo-editar';
    }

    protected function titulo_pagina()
    {
        return __('Acuerdo', 'egc');
    }

    public function titulo_formulario($editando)
    {
        return $editando ? __('Editar acuerdo', 'egc') : __('Nuevo acuerdo', 'egc');
    }

    /**
     * El listado de acuerdos es su archivo nativo (/acuerdo/), no una
     * sección de Administracion.
     */
    public function url_listado()
    {
        $url = get_post_type_archive_link(Acuerdo::POST_TYPE);

        return $url ?: home_url('/');
    }

    protected function campos_formulario()
    {
        return ['acta_id', 'post_content', 'fecha_compromiso', 'fecha_ejecucion', 'asignado_user'];
    }

    protected function args_formulario()
    {
        return ['acta_id' => $this->entero('acta_id')];
    }

    protected function mensajes()
    {
        return array_merge([
            'acta_invalida'        => __('Seleccioná un acta válida.', 'egc'),
            'contenido_vacio'      => __('El texto del acuerdo no puede quedar vacío.', 'egc'),
            'compromiso_requerido' => __('La fecha de compromiso es obligatoria.', 'egc'),
            'ejecucion_futura'     => __('La fecha de ejecución no puede ser futura.', 'egc'),
        ], self::mensajes_asignacion());
    }

    /**
     * @return array<string,string> Mensajes de los resultados de Asignacion
     *         que no son éxito. Estáticos porque también los usa
     *         Administracion (reasignación masiva).
     */
    public static function mensajes_asignacion()
    {
        return [
            Asignacion::ERR_ACUERDO       => __('El acuerdo no existe.', 'egc'),
            Asignacion::ERR_USUARIO       => __('El usuario indicado no existe.', 'egc'),
            Asignacion::ERR_ADMINISTRADOR => __('A quien administra el módulo no se le pueden asignar acuerdos.', 'egc'),
            Asignacion::ERR_NO_ACTIVO     => __('Esa persona no tiene una cuenta activa, así que no se le puede asignar.', 'egc'),
            Asignacion::ERR_SIN_ACCESO    => __('Esa persona todavía no tiene acceso al módulo. El acceso lo da el Administrador General desde Gestión de usuarios; después se le puede asignar.', 'egc'),
            Asignacion::ERR_MISMO_USUARIO => __('El usuario de origen y el de destino son el mismo.', 'egc'),
            Asignacion::ERR_SIN_ADMIN     => __('Solo quien administra el módulo puede asignar acuerdos.', 'egc'),
        ];
    }

    public function guard_access()
    {
        parent::guard_access();

        // Un acuerdo nuevo siempre nace de un acta: sin acta válida no
        // hay nada que hacer en esta pantalla.
        if (is_page($this->slug_editar()) && !$this->post_id_pedido() && !$this->acta_pedida()) {
            wp_safe_redirect(Administracion::get_instance()->url('comites'));
            exit;
        }
    }

    /**
     * @return int El acta pedida en `?acta_id=` si es un acta real; 0 si no.
     */
    private function acta_pedida()
    {
        $acta_id = isset($_GET['acta_id']) ? absint($_GET['acta_id']) : 0;

        return ($acta_id && get_post_type($acta_id) === Acta::POST_TYPE) ? $acta_id : 0;
    }

    protected function valores_nuevo()
    {
        $acta_id = $this->acta_pedida();

        return [
            'acta_id'          => (string) $acta_id,
            'acta_titulo'      => $acta_id ? get_the_title($acta_id) : '',
            'titulo'           => '',
            'post_content'     => '',
            'fecha_compromiso' => '',
            'fecha_ejecucion'  => '',
            'asignado_user'    => '0',
        ];
    }

    protected function valores_de($post)
    {
        $asignado = Asignacion::get_instance()->de($post->ID);

        return [
            'acta_id'          => (string) (int) $post->post_parent,
            'acta_titulo'      => get_the_title($post->post_parent),
            'titulo'           => $post->post_title,
            'post_content'     => $post->post_content,
            'fecha_compromiso' => (string) get_post_meta($post->ID, Acuerdo::META_FECHA_COMPROMISO, true),
            'fecha_ejecucion'  => (string) get_post_meta($post->ID, Acuerdo::META_FECHA_EJECUCION, true),
            'asignado_user'    => (string) $asignado['user_id'],
        ];
    }

    /**
     * Las personas asignables, más quien YA tiene el acuerdo aunque ya
     * no cumpla la regla (p. ej. le quitaron el acceso después): si no
     * apareciera en la lista, el select mostraría "Sin asignar" y
     * guardar el formulario sin tocarlo le quitaría el acuerdo en
     * silencio. Al conservarlo, "sin cambios" sigue significando eso.
     */
    protected function opciones()
    {
        $usuarios = $this->opciones_usuarios();
        $post_id  = $this->post_id_pedido();

        if ($post_id && $this->es_de_este_tipo($post_id)) {
            $actual = Asignacion::get_instance()->de($post_id);

            if ($actual['tipo'] === 'usuario' && !isset($usuarios[$actual['user_id']])) {
                /* translators: 1: nombre, 2: correo */
                $usuarios[$actual['user_id']] = sprintf(__('%1$s (%2$s) — sin acceso vigente', 'egc'), $actual['nombre'], $actual['email']);
            }
        }

        return ['usuarios' => $usuarios];
    }

    protected function leer($post_id)
    {
        if ($post_id) {
            $acta_id = (int) get_post($post_id)->post_parent; // inmutable
        } else {
            $acta_id = $this->entero('acta_id');
            if (!$acta_id || get_post_type($acta_id) !== Acta::POST_TYPE) {
                return 'acta_invalida';
            }
        }

        $contenido = wp_kses_post(wp_unslash(isset($_POST['post_content']) ? $_POST['post_content'] : ''));
        if (trim(wp_strip_all_tags($contenido)) === '') {
            return 'contenido_vacio';
        }

        $compromiso = $this->fecha('fecha_compromiso');
        $ejecucion  = $this->fecha('fecha_ejecucion');
        if ($compromiso === false || $ejecucion === false) {
            return 'fecha_invalida';
        }
        if ($compromiso === '') {
            return 'compromiso_requerido';
        }
        if ($ejecucion !== '' && $ejecucion > Fecha::hoy()) {
            return 'ejecucion_futura';
        }

        $args = ['post_content' => $contenido];

        if (!$post_id) {
            $acta = get_post($acta_id);

            $args['post_title']     = __('Acuerdo nuevo', 'egc'); // provisional: lo reemplaza Sincronizacion
            $args['post_parent']    = $acta_id;
            $args['comment_status'] = 'open';
            $args['post_date']      = $acta->post_date;
            $args['post_date_gmt']  = $acta->post_date_gmt;
            $args['edit_date']      = true;
        }

        return [
            'args'  => $args,
            'meta'  => [
                Acuerdo::META_FECHA_COMPROMISO => $compromiso,
                Acuerdo::META_FECHA_EJECUCION  => $ejecucion, // '' => Sincronizacion borra la meta
            ],
            'extra' => [
                'asignado_user' => $this->entero('asignado_user'),
            ],
        ];
    }

    /**
     * Aplica la asignación que pidió el formulario, comparando con la
     * actual (ver el docblock de la clase).
     */
    protected function despues_de_guardar($post_id, $creado, array $datos)
    {
        $asignacion = Asignacion::get_instance();
        $actual     = $asignacion->de($post_id);

        $usuario_pedido = (int) $datos['extra']['asignado_user'];
        $usuario_actual = $actual['tipo'] === 'usuario' ? $actual['user_id'] : 0;

        if ($usuario_pedido > 0 && $usuario_pedido !== $usuario_actual) {
            $resultado = $asignacion->asignar_a_usuario($post_id, $usuario_pedido);
        } elseif ($usuario_pedido === 0 && $actual['tipo'] !== 'ninguno') {
            $resultado = $asignacion->desasignar($post_id);
        } else {
            return [];
        }

        return $resultado === Asignacion::OK ? [] : ['error' => $resultado];
    }

    /**
     * @return bool Si el usuario actual puede marcar este acuerdo como
     *              ejecutado Y todavía tiene sentido ofrecérselo (está
     *              publicado y no está ejecutado). La usan el puente
     *              (para mostrar el botón) y el handler (para
     *              autorizar): una sola definición.
     *
     * Quién puede: el administrador del módulo (editor de SCA), el
     * Administrador General y el superusuario — exactamente quienes
     * Alcance::administra() reconoce, que se apoya en capacidades
     * (manage_options o edit_others_acuerdos), nunca en nombres de rol.
     * El lector, y el asignado al acuerdo, no pueden.
     */
    public function puede_ejecutar($post_id)
    {
        $post_id = (int) $post_id;

        return $this->es_de_este_tipo($post_id)
            && get_post_status($post_id) === 'publish'
            && is_user_logged_in()
            && Alcance::get_instance()->administra(get_current_user_id())
            && Estatus::de($post_id) !== Estatus::EJECUTADO;
    }

    /**
     * Marca un acuerdo como ejecutado hoy (fecha del sitio). Un solo
     * clic, sin formulario.
     *
     * Escribe con wp_update_post + meta_input (la misma vía que el
     * guardado del formulario) para que se disparen los hooks
     * save_post y Sincronizacion mantenga sus invariantes. Si ya está
     * ejecutado no se pisa su fecha. Quitar o corregir la fecha se
     * hace desde el formulario de edición.
     */
    public function handle_ejecutar()
    {
        check_admin_referer(self::ACTION_EJECUTAR, self::NONCE_NAME);

        $destino = $this->destino();
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !$this->es_de_este_tipo($post_id)) {
            $this->redirigir(add_query_arg('error', 'forbidden', $destino));
        }

        if (!is_user_logged_in() || !Alcance::get_instance()->administra(get_current_user_id())) {
            $this->redirigir(add_query_arg('error', 'forbidden', $destino));
        }

        if ($this->puede_ejecutar($post_id)) {
            wp_update_post([
                'ID'         => $post_id,
                'meta_input' => [Acuerdo::META_FECHA_EJECUCION => Fecha::hoy()],
            ]);
        }

        $this->redirigir($destino);
    }

    /**
     * Reasignación masiva: todos los acuerdos de un usuario pasan a otro.
     */
    public function handle_reasignar()
    {
        check_admin_referer(self::ACTION_REASIGNAR, self::NONCE_NAME);

        $destino = Administracion::get_instance()->url('herramientas');

        if (!is_user_logged_in() || !Alcance::get_instance()->administra(get_current_user_id())) {
            $this->redirigir(add_query_arg('error', 'forbidden', $destino));
        }

        $de = isset($_POST['de_user']) ? absint($_POST['de_user']) : 0;
        $a  = isset($_POST['a_user']) ? absint($_POST['a_user']) : 0;

        $resultado = Asignacion::get_instance()->reasignar_todos($de, $a);

        if ($resultado['codigo'] !== Asignacion::OK) {
            $this->redirigir(add_query_arg('error', $resultado['codigo'], $destino));
        }

        $this->redirigir(add_query_arg(['ok' => '1', 'cantidad' => $resultado['cantidad']], $destino));
    }
}
