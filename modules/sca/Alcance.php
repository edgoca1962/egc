<?php

namespace EGC\Modules\Sca;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Acuerdo\Acuerdo;
use EGC\Modules\Sca\Acuerdo\Asignacion;
use EGC\Modules\Sca\Comite\Comite;
use EGC\Modules\Sca\Miembro\Miembro;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — QUÉ acuerdos puede ver un usuario (módulo SCA).
 *
 * Por qué una clase propia y no capacidades de WordPress: las
 * capacidades de un rol son estáticas ("puede leer acuerdos"), y acá la
 * respuesta depende de datos que cambian con el calendario — membresías
 * con vigencia, qué puesto ocupa cada quien, a quién está asignado cada
 * acuerdo. WordPress no tiene un concepto nativo para eso (y
 * `read_post` NO oculta posts publicados: WP no lo consulta al listar
 * ni al mostrar un post publicado), así que la regla vive en este
 * servicio y se APLICA sobre las consultas (clase Consulta, paso 4).
 * Escribir (crear, editar, marcar ejecutado) sí es puramente
 * capacidades nativas: solo el editor del módulo y el superusuario.
 *
 * Reglas (las acordadas con el negocio):
 *
 * 0. PUERTA: sin acceso al módulo no se ve nada. Quien no administra y
 *    no tiene el rol lector de SCA (enrolado) tiene alcance vacío, tenga
 *    las membresías que tenga y esté lo que esté asignado a su nombre.
 *    El rol lo da una persona (el Administrador General, en Gestión de
 *    usuarios); ni asignar un acuerdo, ni registrar una membresía, ni
 *    activar una cuenta lo conceden. Las reglas 1 a 6 definen QUÉ ve
 *    quien ya está adentro; esta define SI está adentro.
 * 1. Administrador del módulo o superusuario: todo.
 * 2. Membresía VIGENTE en un comité con `_alcance_total` (Junta
 *    Directiva): todos los acuerdos de todos los comités.
 * 3. Membresía en otro comité con el puesto que ese comité declara
 *    coordinador (`_puesto_coordinador`): todos los acuerdos de ESE
 *    comité.
 * 4. Siempre, además: los acuerdos asignados al propio usuario
 *    (post_author) — con una excepción, la siguiente.
 * 5. Solo los puestos de Junta Directiva tienen vigencia. Un usuario
 *    con puesto de Junta VENCIDO que no tiene ninguna otra membresía
 *    vigente queda sin acceso a NINGÚN acuerdo, ni siquiera a los
 *    suyos: terminada la gestión se corta el acceso, y los acuerdos
 *    se reasignan al sucesor (herramienta de reasignación, paso 5). Si
 *    además tiene membresías vigentes fuera de Junta, conserva solo lo
 *    que esas le dan (coordinador: su comité; miembro: lo propio).
 * 6. Quien no pertenece a ningún comité (no tiene membresías) solo ve
 *    lo asignado a él.
 *
 * Resultado de de(): ['todo' => bool, 'comites' => int[], 'propios' =>
 * bool]. "Sin acceso" es todo=false, comites=[] y propios=false.
 *
 * Singleton con caché por petición (la membresía de un usuario se
 * consulta varias veces por página: listado, barra lateral, botones).
 * El caché se descarta cuando cambia cualquier miembro/puesto/comité,
 * para que un guardado y la lectura siguiente dentro de la misma
 * petición (los tests, un handler que reasigna y redirige) no vean
 * datos viejos.
 */
class Alcance
{
    use Singleton;

    /** @var array<int,array> */
    private $cache = [];

    /** @var int[]|null */
    private $administradores = null;

    private function __construct()
    {
        foreach ([
            'save_post_miembro', 'save_post_puesto', 'save_post_comite',
            'trashed_post', 'untrashed_post', 'deleted_post',
            // El acceso depende del rol: si cambia, la respuesta cacheada ya no vale.
            'add_user_role', 'remove_user_role', 'set_user_role',
        ] as $hook) {
            add_action($hook, [$this, 'olvidar']);
        }
    }

    public function olvidar()
    {
        $this->cache = [];
        $this->administradores = null;
    }

    /**
     * @return bool Si el usuario administra los acuerdos (editor del
     *              módulo, administrador general o superusuario).
     *
     * Se apoya en capacidades, nunca en nombres de rol; la capacidad se
     * lee del post type registrado y no se reconstruye a mano (ver
     * UserScope::manages()).
     */
    public function administra($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $post_type = get_post_type_object(Acuerdo::POST_TYPE);

        return $post_type && user_can($user_id, $post_type->cap->edit_others_posts);
    }

    /**
     * @return bool Si el usuario está enrolado en el módulo: tiene un
     *              rol de SCA, lector o Editor (los da el Administrador
     *              General). Quien administra por capacidades (el
     *              superusuario, el Administrador General) no lo
     *              necesita para ver: su acceso viene de ellas (ver
     *              administra()); el rol solo hace falta para que se le
     *              puedan asignar acuerdos.
     *
     * Es la ÚNICA definición de "enrolado": la usa la puerta de de()
     * y la regla de asignación (Asignacion), para que quien puede ver
     * y a quien se puede asignar nunca discrepen.
     */
    public function enrolado($user_id)
    {
        $usuario = (int) $user_id > 0 ? get_userdata((int) $user_id) : false;

        return $usuario && array_intersect([Asignacion::ROL_LECTOR, Asignacion::ROL_EDITOR], (array) $usuario->roles) !== [];
    }

    /**
     * @return int[] Usuarios que administran el módulo (consulta nativa:
     *               get_users con capacidad).
     */
    public function administradores()
    {
        if ($this->administradores === null) {
            $post_type = get_post_type_object(Acuerdo::POST_TYPE);

            $this->administradores = array_map('intval', get_users([
                'capability__in' => array_filter(['manage_options', $post_type ? $post_type->cap->edit_others_posts : '']),
                'fields'         => 'ID',
            ]));
        }

        return $this->administradores;
    }

    /**
     * @return array{todo:bool,comites:int[],propios:bool}
     */
    public function de($user_id)
    {
        $user_id = (int) $user_id;

        if (isset($this->cache[$user_id])) {
            return $this->cache[$user_id];
        }

        return $this->cache[$user_id] = $this->calcular($user_id);
    }

    /**
     * ¿Puede este usuario ver ESTE acuerdo? Misma regla que de(), para
     * un acuerdo puntual (comentar, abrir su pantalla).
     */
    public function ve($user_id, $acuerdo_id)
    {
        $acuerdo = get_post($acuerdo_id);
        if (!$acuerdo || $acuerdo->post_type !== Acuerdo::POST_TYPE) {
            return false;
        }

        $alcance = $this->de($user_id);

        if ($alcance['todo']) {
            return true;
        }

        if ($alcance['propios'] && (int) $acuerdo->post_author === (int) $user_id) {
            return true;
        }

        return in_array(Acuerdo::comite_id($acuerdo->ID), $alcance['comites'], true);
    }

    private function calcular($user_id)
    {
        $sin_acceso = ['todo' => false, 'comites' => [], 'propios' => false];

        if ($user_id <= 0) {
            return $sin_acceso;
        }

        if ($this->administra($user_id)) {
            return ['todo' => true, 'comites' => [], 'propios' => true];
        }

        // Regla 0: sin el rol del módulo no hay acceso, por más membresías o acuerdos que tenga.
        if (!$this->enrolado($user_id)) {
            return $sin_acceso;
        }

        $comites         = [];
        $tiene_membresia = false;
        $vigente_fuera   = false; // membresía vigente (cualquiera) que sostiene el acceso
        $junta_vencida   = false;

        foreach ($this->membresias($user_id) as $miembro_id) {
            $comite_id = (int) get_post($miembro_id)->post_parent;
            $tiene_membresia = true;

            if (Comite::alcance_total($comite_id)) {
                if (Miembro::vigente($miembro_id)) {
                    // Junta Directiva vigente: ve todo, no hace falta seguir.
                    return ['todo' => true, 'comites' => [], 'propios' => true];
                }

                $junta_vencida = true;
                continue;
            }

            // Fuera de Junta no hay vigencia: la membresía vale mientras exista.
            $vigente_fuera = true;

            $puesto_id = (int) get_post_meta($miembro_id, Miembro::META_PUESTO, true);
            if (Comite::coordina($comite_id, $puesto_id)) {
                $comites[$comite_id] = $comite_id;
            }
        }

        // Regla 5: solo membresías de Junta, todas vencidas => sin acceso.
        if ($tiene_membresia && $junta_vencida && !$vigente_fuera) {
            return $sin_acceso;
        }

        return ['todo' => false, 'comites' => array_values($comites), 'propios' => true];
    }

    /**
     * Membresías publicadas del usuario. Se identifica por `_user_id`
     * (la persona ya tiene cuenta); las que solo tienen correo todavía
     * no dan acceso a nadie. Consulta nativa, sin SQL propio.
     *
     * @return int[]
     */
    private function membresias($user_id)
    {
        return get_posts([
            'post_type'        => Miembro::POST_TYPE,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_query'       => [[
                'key'   => Miembro::META_USUARIO,
                'value' => $user_id,
                'type'  => 'NUMERIC',
            ]],
        ]);
    }
}
