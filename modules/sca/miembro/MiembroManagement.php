<?php

namespace EGC\Modules\Sca\Miembro;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Acuerdo\Asignacion;
use EGC\Modules\Sca\Alcance;
use EGC\Modules\Sca\Comite\Comite;
use EGC\Modules\Sca\Puesto\Puesto;
use EGC\Modules\Sca\RecursoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — CRUD de Miembro (membresía) en el front-end (módulo SCA).
 *
 * Una membresía une: persona + comité + puesto + vigencia.
 *
 * - La persona se indica con un usuario existente O con un correo. Con
 *   correo de alguien que todavía no tiene cuenta la membresía queda
 *   "por correo" (`_user_id` = 0) y se enlaza sola cuando esa persona
 *   se enrole y la activen (Asignacion::enlazar_membresias()). Enlazar
 *   no da acceso: el rol del módulo lo da el Administrador General, y
 *   sin él Alcance no le muestra nada. Si el correo ya es de un usuario
 *   se usa ese usuario directamente.
 * - El puesto debe servir en el comité: sin comité propio (Coordinador,
 *   Miembro…) o del mismo comité.
 * - Vigencia: OBLIGATORIA (inicio y fin) solo en comités de alcance
 *   total (Junta Directiva), donde da o quita acceso. En los demás se
 *   acepta pero no tiene efecto. Reelegir a alguien es editar las
 *   fechas de su membresía; si cambia la persona del puesto se crea la
 *   membresía nueva y se reasignan los acuerdos del anterior
 *   (Asignacion::reasignar_todos()).
 *
 * Quién aparece en la lista de personas: las enrolladas y activas
 * (las mismas a quienes se asignan acuerdos) MÁS quienes administran
 * el módulo. A un administrador no se le asignan acuerdos, pero sí
 * puede ocupar un puesto en un comité; reutilizar tal cual la lista de
 * asignación lo dejaba fuera del formulario aunque el servidor lo
 * aceptara. Son dos preguntas distintas y la lista de membresías no
 * debe heredar la restricción de la otra.
 *
 * El título (`post_title`) se arma al guardar como "Persona — Puesto —
 * Comité" solo para que la lista nativa de wp-admin del superusuario
 * sea legible; las pantallas del módulo leen nombres en vivo.
 */
class MiembroManagement extends RecursoManagement
{
    use Singleton;

    private function __construct()
    {
        parent::__construct();
    }

    protected function post_type()
    {
        return Miembro::POST_TYPE;
    }

    protected function slug_editar()
    {
        return 'miembro-editar';
    }

    protected function titulo_pagina()
    {
        return __('Miembro', 'egc');
    }

    public function titulo_formulario($editando)
    {
        return $editando ? __('Editar miembro', 'egc') : __('Nuevo miembro', 'egc');
    }

    protected function campos_formulario()
    {
        return ['comite_id', 'puesto_id', 'user_id', 'email', 'desde', 'hasta'];
    }

    protected function mensajes()
    {
        return [
            'comite_invalido'    => __('Seleccioná un comité válido.', 'egc'),
            'puesto_invalido'    => __('Seleccioná un puesto válido para ese comité.', 'egc'),
            'persona_vacia'      => __('Indicá la persona: elegí un usuario o escribí su correo.', 'egc'),
            'persona_invalida'   => __('El usuario seleccionado no existe.', 'egc'),
            'correo_invalido'    => __('El correo no es válido.', 'egc'),
            'vigencia_requerida' => __('Los puestos de este comité requieren fecha de inicio y de fin de vigencia.', 'egc'),
            'vigencia_invertida' => __('La fecha de inicio de la vigencia no puede ser posterior a la de fin.', 'egc'),
        ];
    }

    protected function valores_de($post)
    {
        return [
            'comite_id' => (string) (int) $post->post_parent,
            'puesto_id' => (string) (int) get_post_meta($post->ID, Miembro::META_PUESTO, true),
            'user_id'   => (string) (int) get_post_meta($post->ID, Miembro::META_USUARIO, true),
            'email'     => (string) get_post_meta($post->ID, Miembro::META_EMAIL, true),
            'desde'     => (string) get_post_meta($post->ID, Miembro::META_DESDE, true),
            'hasta'     => (string) get_post_meta($post->ID, Miembro::META_HASTA, true),
        ];
    }

    protected function opciones()
    {
        $puestos = [];
        foreach (get_posts([
            'post_type'        => Puesto::POST_TYPE,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]) as $puesto) {
            $comite = (int) $puesto->post_parent;
            $puestos[(int) $puesto->ID] = $comite
                ? sprintf('%s (%s)', $puesto->post_title, Comite::nombre($comite))
                : $puesto->post_title;
        }

        return [
            'comites'  => $this->opciones_comites(),
            'puestos'  => $puestos,
            'usuarios' => $this->personas_elegibles(),
            // Para que la vista avise "vigencia obligatoria" sin conocer la regla:
            'comites_con_vigencia' => array_values(array_filter(
                array_keys($this->opciones_comites()),
                [Comite::class, 'alcance_total']
            )),
        ];
    }

    protected function leer($post_id)
    {
        $comite_id = $this->entero('comite_id');
        if (!$comite_id || get_post_type($comite_id) !== Comite::POST_TYPE) {
            return 'comite_invalido';
        }

        $puesto_id = $this->entero('puesto_id');
        if (!$puesto_id || get_post_type($puesto_id) !== Puesto::POST_TYPE) {
            return 'puesto_invalido';
        }
        $comite_del_puesto = (int) get_post($puesto_id)->post_parent;
        if ($comite_del_puesto && $comite_del_puesto !== $comite_id) {
            return 'puesto_invalido';
        }

        $persona = $this->leer_persona();
        if (is_string($persona)) {
            return $persona;
        }

        $desde = $this->fecha('desde');
        $hasta = $this->fecha('hasta');
        if ($desde === false || $hasta === false) {
            return 'fecha_invalida';
        }

        if (Comite::alcance_total($comite_id) && ($desde === '' || $hasta === '')) {
            return 'vigencia_requerida';
        }

        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            return 'vigencia_invertida';
        }

        return [
            'args' => [
                'post_title'  => sprintf('%s — %s — %s', $persona['nombre'], get_post($puesto_id)->post_title, Comite::nombre($comite_id)),
                'post_parent' => $comite_id,
            ],
            'meta' => [
                Miembro::META_PUESTO  => $puesto_id,
                Miembro::META_USUARIO => $persona['user_id'],
                Miembro::META_EMAIL   => $persona['email'],
                Miembro::META_DESDE   => $desde,
                Miembro::META_HASTA   => $hasta,
            ],
        ];
    }

    /**
     * @return array<int,string> id => "Nombre (correo)", por orden alfabético.
     */
    private function personas_elegibles()
    {
        $personas = $this->opciones_usuarios();

        foreach (Alcance::get_instance()->administradores() as $user_id) {
            $usuario = get_userdata($user_id);

            if ($usuario) {
                $personas[(int) $user_id] = sprintf('%s (%s)', $usuario->display_name, $usuario->user_email);
            }
        }

        asort($personas, SORT_NATURAL | SORT_FLAG_CASE);

        return $personas;
    }

    /**
     * @return array{user_id:int,email:string,nombre:string}|string
     */
    private function leer_persona()
    {
        $user_id = $this->entero('user_id');

        // Un usuario elegido manda sobre el correo; el correo solo se
        // usa cuando no se eligió usuario.
        if (!$user_id) {
            $correo = $this->texto('email');

            if ($correo === '') {
                return 'persona_vacia';
            }

            $correo = Asignacion::normalizar_correo($correo);
            if ($correo === '') {
                return 'correo_invalido';
            }

            $existente = get_user_by('email', $correo);
            if (!$existente) {
                return ['user_id' => 0, 'email' => $correo, 'nombre' => $correo];
            }

            $user_id = (int) $existente->ID;
        }

        $usuario = get_userdata($user_id);
        if (!$usuario) {
            return 'persona_invalida';
        }

        // Con usuario enlazado el correo sobra: una sola fuente de verdad.
        return ['user_id' => (int) $usuario->ID, 'email' => '', 'nombre' => $usuario->display_name];
    }
}
