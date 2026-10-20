<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\Singleton;
use EGC\Core\UserStatus;
use EGC\Modules\Sca\Alcance;
use EGC\Modules\Sca\Miembro\Miembro;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — a quién está asignado un acuerdo (módulo SCA).
 *
 * Mapeo a lo nativo: el asignado ES el `post_author` del acuerdo. Un
 * acuerdo "sin asignar" es uno SIN autor (`post_author` = 0, o un
 * usuario que ya no existe): se guarda con la columna nativa, sin una
 * bandera aparte que pudiera desincronizarse. Antes "sin asignar"
 * significaba "autor administrador" porque a quien administra no se le
 * asignaban acuerdos; desde que el administrador del módulo (Editor de
 * SCA) también puede recibirlos, el autor ya no sirve para distinguirlo
 * y el acuerdo sin asignar pasa a ser el que no tiene autor. Los datos
 * anteriores se convierten una sola vez (migrar_sin_asignar()).
 *
 * REGLA: solo se asigna a quien ya está enrolado, y asignar no da
 * acceso a nadie. "Enrolado" son tres cosas a la vez, y las tres tienen
 * que cumplirse ANTES de asignar:
 *
 *  1. Tiene cuenta.
 *  2. La cuenta está Activa (el estado lo cambia el Administrador
 *     General desde Gestión de usuarios).
 *  3. Ya tiene un rol de SCA: lector o Editor (administrador del
 *     módulo). Lo da el Administrador General o el superusuario, también
 *     desde Gestión de usuarios. La definición vive en
 *     Alcance::enrolado(), que además es la puerta de la visibilidad:
 *     sin ese rol no se ve ningún acuerdo.
 *
 * Esta clase nunca crea cuentas, nunca manda invitaciones y nunca
 * agrega roles: el acceso al módulo lo concede una persona, en un
 * único lugar. Si el destino no cumple, se devuelve el motivo
 * (ERR_*) y no se toca nada. Antes existía el camino "asignar a un
 * correo sin cuenta" (invitación + asignación diferida + rol
 * automático al activarse): se eliminó por completo, porque era
 * justamente un acceso concedido sin que nadie lo decidiera.
 *
 * Esta clase NO verifica permisos del usuario actual: eso es del
 * handler (paso 6), que revalida capacidades y nonce antes de llamarla.
 * Acá se validan los DATOS (existe, está activo, está enrolado).
 *
 * Reasignación masiva (reasignar_todos): cuando cambia la persona de un
 * puesto (el Tesorero nuevo), TODOS los acuerdos del anterior pasan al
 * nuevo de un solo paso, con la misma regla de enrolamiento. Es
 * necesaria porque la vigencia vencida le quita al anterior el acceso a
 * sus propios acuerdos (ver Alcance).
 *
 * Membresías: cuando una cuenta pasa a Activa, las membresías
 * (Miembro) registradas con su correo y sin usuario se enlazan a ella.
 * Enlazar datos no es dar acceso: no se agrega ningún rol, y sin rol
 * Alcance no le muestra nada. Vive acá,
 * y no en una clase propia, porque es el único oyente de
 * `egc_user_status_changed` del módulo y son ~10 líneas.
 *
 * Todas las consultas pasan Acuerdo::SIN_ALCANCE: son del sistema, no
 * deben recortarse según quien las dispare.
 */
class Asignacion
{
    use Singleton;

    const ROL_LECTOR = 'sca_lector'; // el rol de solo lectura que declara el manifest
    const ROL_EDITOR = 'sca_editor'; // el administrador del módulo que declara el manifest

    // Resultados
    const OK                = 'ok';
    const ERR_ACUERDO       = 'acuerdo_invalido';
    const ERR_USUARIO       = 'usuario_invalido';
    const ERR_NO_ACTIVO     = 'usuario_no_activo';  // pendiente o rechazado
    const ERR_SIN_ACCESO    = 'sin_acceso_sca';     // activo, pero sin rol en el módulo
    const ERR_MISMO_USUARIO = 'mismo_usuario';

    /** Versión de la conversión de "sin asignar" (ver migrar_sin_asignar()). */
    const OPCION_MIGRACION  = 'egc_sca_sin_asignar_v';
    const VERSION_MIGRACION = '1';
    const LOTE_MIGRACION    = 50;

    private function __construct()
    {
        add_action('egc_user_status_changed', [$this, 'enlazar_membresias'], 10, 3);
        add_action('init', [$this, 'migrar_sin_asignar'], 30);
    }

    /**
     * A quién está asignado, ya resuelto para pintar (vista) y decidir
     * (formularios).
     *
     * @return array{tipo:string,user_id:int,nombre:string,email:string}
     *         tipo: 'usuario' | 'ninguno'
     */
    public function de($acuerdo_id)
    {
        $acuerdo = get_post($acuerdo_id);
        $usuario = $acuerdo ? get_userdata((int) $acuerdo->post_author) : false;

        if (!$usuario) {
            return ['tipo' => 'ninguno', 'user_id' => 0, 'nombre' => '', 'email' => ''];
        }

        return ['tipo' => 'usuario', 'user_id' => (int) $usuario->ID, 'nombre' => $usuario->display_name, 'email' => $usuario->user_email];
    }

    /**
     * @return string Uno de los resultados de esta clase.
     */
    public function asignar_a_usuario($acuerdo_id, $user_id)
    {
        if (!$this->es_acuerdo($acuerdo_id)) {
            return self::ERR_ACUERDO;
        }

        $motivo = $this->motivo_no_asignable((int) $user_id);
        if ($motivo !== self::OK) {
            return $motivo;
        }

        return $this->poner_autor($acuerdo_id, (int) $user_id);
    }

    /**
     * Vuelve a "sin asignar": el acuerdo queda sin autor (0).
     *
     * @return string
     */
    public function desasignar($acuerdo_id)
    {
        if (!$this->es_acuerdo($acuerdo_id)) {
            return self::ERR_ACUERDO;
        }

        return $this->poner_autor($acuerdo_id, 0);
    }

    /**
     * Conversión ÚNICA de los datos anteriores: cuando "sin asignar" era
     * "autor administrador", todo acuerdo cuyo autor administra el
     * módulo era un acuerdo sin asignar. Ahora esos acuerdos pasan a no
     * tener autor; si no, quedarían a nombre del administrador que los
     * registró.
     *
     * Corre en lotes (cada petición convierte hasta LOTE_MIGRACION con
     * wp_update_post, que dispara los hooks) hasta que no queda ninguno;
     * recién entonces se anota la versión. Importa el orden: tiene que
     * terminar ANTES de que se asigne algún acuerdo a un administrador,
     * porque después ya no se puede distinguir cuál era cuál. Como el
     * código nuevo es el único que permite esa asignación y esto corre en
     * `init`, se completa en las primeras peticiones.
     */
    public function migrar_sin_asignar()
    {
        if (get_option(self::OPCION_MIGRACION) === self::VERSION_MIGRACION) {
            return;
        }

        $administradores = Alcance::get_instance()->administradores();
        $ids             = $administradores ? get_posts([
            'post_type'          => Acuerdo::POST_TYPE,
            'post_status'        => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'author__in'         => $administradores,
            'posts_per_page'     => self::LOTE_MIGRACION,
            'fields'             => 'ids',
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
        ]) : [];

        foreach ($ids as $acuerdo_id) {
            $this->poner_autor($acuerdo_id, 0);
        }

        if (count($ids) < self::LOTE_MIGRACION) {
            update_option(self::OPCION_MIGRACION, self::VERSION_MIGRACION);
        }
    }

    /**
     * Pasa TODOS los acuerdos de un usuario a otro (cambio de la
     * persona que ocupa un puesto). Incluye los ejecutados: quien sale
     * pierde el acceso y los históricos no deben quedar a nombre de
     * alguien que ya no los puede ver.
     *
     * El destino se valida UNA vez, antes de tocar nada: si es inválido
     * no se reasigna ninguno.
     *
     * @return array{codigo:string,cantidad:int}
     */
    public function reasignar_todos($de_user_id, $a_user_id)
    {
        $de_user_id = (int) $de_user_id;
        $a_user_id  = (int) $a_user_id;

        if ($de_user_id <= 0 || !get_userdata($de_user_id)) {
            return ['codigo' => self::ERR_USUARIO, 'cantidad' => 0];
        }

        if ($de_user_id === $a_user_id) {
            return ['codigo' => self::ERR_MISMO_USUARIO, 'cantidad' => 0];
        }

        $motivo = $this->motivo_no_asignable($a_user_id);
        if ($motivo !== self::OK) {
            return ['codigo' => $motivo, 'cantidad' => 0];
        }

        $ids = get_posts([
            'post_type'          => Acuerdo::POST_TYPE,
            'post_status'        => ['publish', 'future', 'draft', 'pending', 'private'],
            'author'             => $de_user_id,
            'posts_per_page'     => -1,
            'fields'             => 'ids',
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
        ]);

        foreach ($ids as $acuerdo_id) {
            $this->poner_autor($acuerdo_id, $a_user_id);
        }

        return ['codigo' => self::OK, 'cantidad' => count($ids)];
    }

    /**
     * Al pasar una cuenta a Activa (hook del Core), enlaza las
     * membresías que se registraron con su correo. No concede acceso.
     */
    public function enlazar_membresias($user_id, $nuevo_estado, $estado_previo)
    {
        if ($nuevo_estado !== UserStatus::ACTIVO) {
            return;
        }

        $usuario = get_userdata($user_id);
        if (!$usuario) {
            return;
        }

        $email = self::normalizar_correo($usuario->user_email);
        if ($email === '') {
            return;
        }

        foreach ($this->membresias_sin_usuario($email) as $miembro_id) {
            wp_update_post(['ID' => $miembro_id, 'meta_input' => [Miembro::META_USUARIO => $usuario->ID]]);
        }
    }

    /**
     * Personas a las que se puede asignar un acuerdo: las enroladas
     * (cuenta Activa + rol de SCA, lector o Editor). Es la misma lista
     * que valida asignar_a_usuario(): lo que el formulario ofrece y lo
     * que el servidor acepta no pueden diferir.
     *
     * @return array<int,string> id => "Nombre (correo)", por orden alfabético.
     */
    public function usuarios_asignables()
    {
        $usuarios = get_users([
            'role__in'   => [self::ROL_LECTOR, self::ROL_EDITOR],
            'meta_key'   => UserStatus::META_KEY,
            'meta_value' => UserStatus::ACTIVO,
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ]);

        $opciones = [];

        foreach ($usuarios as $usuario) {
            $opciones[(int) $usuario->ID] = sprintf('%s (%s)', $usuario->display_name, $usuario->user_email);
        }

        return $opciones;
    }

    /**
     * Minúsculas y sin espacios: el correo es la llave que une la
     * invitación con la cuenta que se cree después, y "Ana@X.org" y
     * "ana@x.org" son la misma persona. Devuelve '' si no es válido.
     */
    public static function normalizar_correo($email)
    {
        $email = sanitize_email(strtolower(trim((string) $email)));

        return is_email($email) ? $email : '';
    }

    // ------------------------------------------------------------------

    private function es_acuerdo($acuerdo_id)
    {
        $acuerdo = get_post($acuerdo_id);

        return $acuerdo && $acuerdo->post_type === Acuerdo::POST_TYPE;
    }

    /**
     * Cambia el autor con wp_update_post (y no con SQL ni solo la
     * columna) para que se disparen los hooks: Sincronizacion, los
     * cachés de alcance y cualquier otro interesado.
     */
    private function poner_autor($acuerdo_id, $user_id)
    {
        $acuerdo = get_post($acuerdo_id);

        if ((int) $acuerdo->post_author !== (int) $user_id) {
            wp_update_post(['ID' => $acuerdo_id, 'post_author' => (int) $user_id]);
        }

        return self::OK;
    }

    /**
     * La regla de enrolamiento, en un solo lugar (la usan asignar y
     * reasignar). Orden de las comprobaciones: de la más básica a la
     * más específica, para devolver el motivo más útil.
     *
     * @return string OK si se le puede asignar; si no, el ERR_* del motivo.
     */
    private function motivo_no_asignable($user_id)
    {
        $usuario = $user_id > 0 ? get_userdata($user_id) : false;
        if (!$usuario) {
            return self::ERR_USUARIO;
        }

        if (UserStatus::get_instance()->get_status($usuario->ID) !== UserStatus::ACTIVO) {
            return self::ERR_NO_ACTIVO;
        }

        if (!Alcance::get_instance()->enrolado($usuario->ID)) {
            return self::ERR_SIN_ACCESO;
        }

        return self::OK;
    }

    /**
     * @return int[] Membresías con ese correo y sin usuario enlazado
     *               (la meta puede faltar o valer 0).
     */
    private function membresias_sin_usuario($email)
    {
        return get_posts([
            'post_type'        => Miembro::POST_TYPE,
            'post_status'      => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_query'       => [
                'relation' => 'AND',
                ['key' => Miembro::META_EMAIL, 'value' => $email],
                [
                    'relation' => 'OR',
                    ['key' => Miembro::META_USUARIO, 'compare' => 'NOT EXISTS'],
                    ['key' => Miembro::META_USUARIO, 'value' => 0, 'type' => 'NUMERIC'],
                ],
            ],
        ]);
    }
}
