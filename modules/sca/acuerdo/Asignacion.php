<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\Singleton;
use EGC\Core\UserStatus;
use EGC\Modules\Sca\Alcance;
use EGC\Modules\Sca\Consecutivo;
use EGC\Modules\Sca\Miembro\Miembro;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — a quién está asignado un acuerdo (módulo SCA).
 *
 * Mapeo a lo nativo: el asignado ES el `post_author` del acuerdo. Un
 * acuerdo "sin asignar" es uno cuyo autor administra el módulo (el
 * editor que lo registró): a quien administra no se le asignan
 * acuerdos, así que la pregunta se responde con la misma capacidad que
 * usa el alcance, sin una bandera aparte que pudiera desincronizarse.
 *
 * Tres situaciones al asignar:
 *
 *  1. La persona ya tiene cuenta activa: el autor pasa a ser ella.
 *  2. No tiene cuenta: se guarda su correo en `_asignado_email`, el
 *     autor sigue siendo el administrador y se le manda la invitación a
 *     "Solicitar ingreso" (Notificacion). Cuando el administrador del
 *     sitio apruebe esa solicitud, el Core dispara
 *     `egc_user_status_changed` y completar() convierte el correo en
 *     autor — el Core no sabe que SCA existe.
 *  3. Tiene cuenta pero todavía Pendiente: igual que 2, sin correo
 *     (ya pidió ingreso).
 *
 * Al completarse el ingreso también se enlazan sus membresías
 * (Miembro con `_email` y sin `_user_id`) y se le da el rol lector del
 * módulo si no tiene ninguno, para que figure como participante de SCA
 * en la gestión de usuarios.
 *
 * Esta clase NO verifica permisos del usuario actual: eso es del
 * handler (paso 6), que revalida capacidades y nonce antes de llamarla.
 * Acá se validan los DATOS (existe, no es administrador, está activo).
 *
 * Reasignación masiva (reasignar_todos): cuando cambia la persona de un
 * puesto (el Tesorero nuevo), TODOS los acuerdos del anterior pasan al
 * nuevo de un solo paso. Es necesaria porque la vigencia vencida le
 * quita al anterior el acceso a sus propios acuerdos (ver Alcance).
 *
 * Todas las consultas pasan Acuerdo::SIN_ALCANCE: son del sistema, no
 * deben recortarse según quien las dispare.
 */
class Asignacion
{
    use Singleton;

    const ROL_LECTOR = 'sca_lector'; // el rol de solo lectura que declara el manifest

    // Resultados
    const OK                    = 'ok';
    const PENDIENTE_REGISTRO    = 'pendiente_registro';   // sin cuenta: invitación enviada
    const PENDIENTE_ACTIVACION  = 'pendiente_activacion'; // cuenta en espera de aprobación
    const ERR_ACUERDO           = 'acuerdo_invalido';
    const ERR_USUARIO           = 'usuario_invalido';
    const ERR_ADMINISTRADOR     = 'es_administrador';
    const ERR_RECHAZADO         = 'usuario_rechazado';
    const ERR_CORREO            = 'correo_invalido';
    const ERR_MISMO_USUARIO     = 'mismo_usuario';
    const ERR_SIN_ADMIN         = 'sin_administrador';

    private function __construct()
    {
        add_action('egc_user_status_changed', [$this, 'completar'], 10, 3);
    }

    /**
     * A quién está asignado, ya resuelto para pintar (vista) y decidir
     * (formularios).
     *
     * @return array{tipo:string,user_id:int,nombre:string,email:string}
     *         tipo: 'usuario' | 'pendiente' (esperando que se enrole) | 'ninguno'
     */
    public function de($acuerdo_id)
    {
        $pendiente = (string) get_post_meta($acuerdo_id, Acuerdo::META_ASIGNADO_EMAIL, true);
        if ($pendiente !== '') {
            return ['tipo' => 'pendiente', 'user_id' => 0, 'nombre' => '', 'email' => $pendiente];
        }

        $acuerdo = get_post($acuerdo_id);
        $usuario = $acuerdo ? get_userdata((int) $acuerdo->post_author) : false;

        if (!$usuario || Alcance::get_instance()->administra($usuario->ID)) {
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

        $usuario = get_userdata((int) $user_id);
        if (!$usuario) {
            return self::ERR_USUARIO;
        }

        if (Alcance::get_instance()->administra($usuario->ID)) {
            return self::ERR_ADMINISTRADOR;
        }

        switch (UserStatus::get_instance()->get_status($usuario->ID)) {
            case UserStatus::RECHAZADO:
                return self::ERR_RECHAZADO;

            case UserStatus::PENDIENTE:
                return $this->dejar_pendiente($acuerdo_id, $usuario->user_email, self::PENDIENTE_ACTIVACION);
        }

        // Una invitación pendiente anterior (otro correo) queda sin efecto.
        delete_post_meta($acuerdo_id, Acuerdo::META_ASIGNADO_EMAIL);

        return $this->poner_autor($acuerdo_id, $usuario->ID);
    }

    /**
     * Para quien quizá todavía no tiene cuenta. Si el correo ya
     * pertenece a un usuario se asigna a ese usuario.
     *
     * @return string Uno de los resultados de esta clase.
     */
    public function asignar_a_correo($acuerdo_id, $email)
    {
        if (!$this->es_acuerdo($acuerdo_id)) {
            return self::ERR_ACUERDO;
        }

        $email = self::normalizar_correo($email);
        if ($email === '') {
            return self::ERR_CORREO;
        }

        $existente = get_user_by('email', $email);
        if ($existente) {
            return $this->asignar_a_usuario($acuerdo_id, $existente->ID);
        }

        $resultado = $this->dejar_pendiente($acuerdo_id, $email, self::PENDIENTE_REGISTRO);

        if ($resultado === self::PENDIENTE_REGISTRO) {
            Notificacion::invitar($email, $acuerdo_id);
        }

        return $resultado;
    }

    /**
     * Vuelve a "sin asignar": el autor pasa al administrador que
     * ejecuta la acción y se borra el correo pendiente.
     *
     * @return string
     */
    public function desasignar($acuerdo_id)
    {
        if (!$this->es_acuerdo($acuerdo_id)) {
            return self::ERR_ACUERDO;
        }

        $admin_id = $this->administrador_actual();
        if (!$admin_id) {
            return self::ERR_SIN_ADMIN;
        }

        delete_post_meta($acuerdo_id, Acuerdo::META_ASIGNADO_EMAIL);

        return $this->poner_autor($acuerdo_id, $admin_id);
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

        $destino = get_userdata($a_user_id);
        if (!$destino) {
            return ['codigo' => self::ERR_USUARIO, 'cantidad' => 0];
        }

        if (Alcance::get_instance()->administra($destino->ID)) {
            return ['codigo' => self::ERR_ADMINISTRADOR, 'cantidad' => 0];
        }

        // Un destino aún Pendiente no puede recibir en bloque: el
        // "pendiente" se guarda por acuerdo y habría que repetirlo en
        // cada uno. Se pide que el destino esté activo.
        if (UserStatus::get_instance()->get_status($destino->ID) !== UserStatus::ACTIVO) {
            return ['codigo' => self::ERR_RECHAZADO, 'cantidad' => 0];
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
            $this->poner_autor($acuerdo_id, $destino->ID);
        }

        $this->garantizar_rol_lector($destino->ID);

        return ['codigo' => self::OK, 'cantidad' => count($ids)];
    }

    /**
     * Engancha el ingreso de una persona con lo que SCA ya tenía
     * esperándola. Se ejecuta al pasar a Activo (hook del Core).
     */
    public function completar($user_id, $nuevo_estado, $estado_previo)
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

        $enlazo = false;

        // Acuerdos esperando a este correo (si por un dato raro el
        // usuario administra el módulo, no se le asignan).
        if (!Alcance::get_instance()->administra($usuario->ID)) {
            foreach ($this->acuerdos_pendientes($email) as $acuerdo_id) {
                delete_post_meta($acuerdo_id, Acuerdo::META_ASIGNADO_EMAIL);
                $this->poner_autor($acuerdo_id, $usuario->ID);
                $enlazo = true;
            }
        }

        // Membresías registradas por correo: ahora tienen usuario.
        foreach ($this->membresias_sin_usuario($email) as $miembro_id) {
            wp_update_post(['ID' => $miembro_id, 'meta_input' => [Miembro::META_USUARIO => $usuario->ID]]);
            $enlazo = true;
        }

        if ($enlazo) {
            $this->garantizar_rol_lector($usuario->ID);
        }
    }

    /**
     * Personas a las que se puede asignar un acuerdo: usuarios con
     * cuenta ACTIVA que no administran el módulo.
     *
     * @return array<int,string> id => "Nombre (correo)", por orden alfabético.
     */
    public function usuarios_asignables()
    {
        $usuarios = get_users([
            'meta_key'   => UserStatus::META_KEY,
            'meta_value' => UserStatus::ACTIVO,
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ]);

        $alcance  = Alcance::get_instance();
        $opciones = [];

        foreach ($usuarios as $usuario) {
            if (!$alcance->administra($usuario->ID)) {
                $opciones[(int) $usuario->ID] = sprintf('%s (%s)', $usuario->display_name, $usuario->user_email);
            }
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

        $this->garantizar_rol_lector($user_id);

        return self::OK;
    }

    /**
     * Guarda el correo y suelta el acuerdo (autor = administrador),
     * para que quien estaba asignado antes deje de verlo.
     */
    private function dejar_pendiente($acuerdo_id, $email, $resultado)
    {
        $admin_id = $this->administrador_actual();
        if (!$admin_id) {
            return self::ERR_SIN_ADMIN;
        }

        update_post_meta($acuerdo_id, Acuerdo::META_ASIGNADO_EMAIL, self::normalizar_correo($email));

        $acuerdo = get_post($acuerdo_id);
        if (!Alcance::get_instance()->administra((int) $acuerdo->post_author)) {
            wp_update_post(['ID' => $acuerdo_id, 'post_author' => $admin_id]);
        }

        return $resultado;
    }

    /**
     * El usuario actual si administra el módulo (es quien ejecuta la
     * acción); 0 si no (nadie con permiso para soltar un acuerdo).
     */
    private function administrador_actual()
    {
        $actual = get_current_user_id();

        return ($actual && Alcance::get_instance()->administra($actual)) ? $actual : 0;
    }

    /**
     * Quien participa de SCA sin ser administrador necesita el rol
     * lector. add_role (no set_role): se SUMA a los roles que ya tenga
     * en otros módulos.
     */
    private function garantizar_rol_lector($user_id)
    {
        $usuario = get_userdata($user_id);

        if (!$usuario || Alcance::get_instance()->administra($usuario->ID)) {
            return;
        }

        if (!in_array(self::ROL_LECTOR, (array) $usuario->roles, true)) {
            $usuario->add_role(self::ROL_LECTOR);
        }
    }

    /**
     * @return int[]
     */
    private function acuerdos_pendientes($email)
    {
        return get_posts([
            'post_type'          => Acuerdo::POST_TYPE,
            'post_status'        => Consecutivo::ESTADOS,
            'posts_per_page'     => -1,
            'fields'             => 'ids',
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
            'meta_query'         => [[
                'key'   => Acuerdo::META_ASIGNADO_EMAIL,
                'value' => $email,
            ]],
        ]);
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
