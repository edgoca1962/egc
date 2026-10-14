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
 * Capa Lógica — CRUD de Acuerdo en el front-end (módulo SCA), más la
 * acción de reasignación masiva.
 *
 * Campos que escribe el administrador:
 *  - Acta (solo al crear, y siempre desde "agregar acuerdo" de un acta;
 *    después es inmutable): de ella salen comité, fecha y número.
 *  - Texto del acuerdo (wp_editor → post_content), obligatorio.
 *  - Fecha de compromiso, obligatoria.
 *  - Fecha de ejecución: la marca SOLO el administrador (este
 *    formulario es solo de administradores); vacía = no ejecutado.
 *  - Asignado: un usuario existente, o un correo de alguien que aún no
 *    tiene cuenta (Asignacion decide qué ocurre en cada caso).
 *
 * Qué cambió respecto de lo que el formulario manda, en cada
 * guardado, se decide comparando con el estado ACTUAL: así volver a
 * guardar el formulario con el mismo asignado no reenvía invitaciones
 * ni reasigna nada.
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

    private function __construct()
    {
        parent::__construct();
        add_action('admin_post_' . self::ACTION_REASIGNAR, [$this, 'handle_reasignar']);
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
        return ['acta_id', 'post_content', 'fecha_compromiso', 'fecha_ejecucion', 'asignado_user', 'asignado_email'];
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
            'pendiente_registro'   => __('Acuerdo guardado. Se envió una invitación a ese correo para que solicite su ingreso.', 'egc'),
            'pendiente_activacion' => __('Acuerdo guardado. Quedará a nombre de esa persona cuando se apruebe su ingreso.', 'egc'),
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
            Asignacion::ERR_RECHAZADO     => __('Esa persona no tiene una cuenta activa, así que no se le puede asignar.', 'egc'),
            Asignacion::ERR_CORREO        => __('El correo de la persona asignada no es válido.', 'egc'),
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
            wp_safe_redirect(Administracion::get_instance()->url('actas'));
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
            'asignado_email'   => '',
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
            'asignado_email'   => $asignado['tipo'] === 'pendiente' ? $asignado['email'] : '',
        ];
    }

    protected function opciones()
    {
        return ['usuarios' => $this->opciones_usuarios()];
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
                'asignado_user'  => $this->entero('asignado_user'),
                'asignado_email' => $this->texto('asignado_email'),
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
        $correo_crudo   = (string) $datos['extra']['asignado_email'];
        $correo_pedido  = $correo_crudo === '' ? '' : Asignacion::normalizar_correo($correo_crudo);

        if ($correo_crudo !== '' && $correo_pedido === '') {
            return ['error' => Asignacion::ERR_CORREO];
        }

        $usuario_actual = $actual['tipo'] === 'usuario' ? $actual['user_id'] : 0;
        $correo_actual  = $actual['tipo'] === 'pendiente' ? $actual['email'] : '';

        if ($correo_pedido !== '' && $correo_pedido !== $correo_actual) {
            $resultado = $asignacion->asignar_a_correo($post_id, $correo_pedido);
        } elseif ($usuario_pedido > 0 && $usuario_pedido !== $usuario_actual) {
            $resultado = $asignacion->asignar_a_usuario($post_id, $usuario_pedido);
        } elseif ($usuario_pedido === 0 && $correo_pedido === '' && $actual['tipo'] !== 'ninguno') {
            $resultado = $asignacion->desasignar($post_id);
        } else {
            return [];
        }

        if ($resultado === Asignacion::OK) {
            return [];
        }

        $avisos = [Asignacion::PENDIENTE_REGISTRO, Asignacion::PENDIENTE_ACTIVACION];

        return in_array($resultado, $avisos, true) ? ['aviso' => $resultado] : ['error' => $resultado];
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
