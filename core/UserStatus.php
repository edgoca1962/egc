<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Estado de "aduana" de cada usuario (sin incluir al superusuario).
 *
 * Todo usuario nuevo entra Pendiente. Desde Pendiente puede pasar a
 * Activo o Rechazado. Un usuario Activo o Rechazado no puede pasar
 * directo al otro extremo: primero vuelve a Pendiente. Esta clase es
 * la única fuente de verdad de esa regla — nadie más compara strings
 * de estado ni hace update_user_meta() de 'egc_status' directamente.
 */
class UserStatus
{
    use Singleton;

    const META_KEY = 'egc_status';

    const PENDIENTE = 'pendiente';
    const ACTIVO = 'activo';
    const RECHAZADO = 'rechazado';

    private function __construct()
    {
        add_action('user_register', [$this, 'set_default_status_on_register']);
    }

    /**
     * Todo usuario nuevo (creado por auto-registro o por gestión de
     * usuarios) arranca Pendiente. No se dispara egc_user_status_changed
     * aquí porque no hay "estado anterior": es el estado inicial, no
     * un cambio.
     */
    public function set_default_status_on_register($user_id)
    {
        if (!metadata_exists('user', $user_id, self::META_KEY)) {
            update_user_meta($user_id, self::META_KEY, self::PENDIENTE);
        }
    }

    /**
     * @return string Uno de PENDIENTE/ACTIVO/RECHAZADO. Pendiente si el
     *                usuario no tiene el meta (no debería pasar tras el
     *                hook de arriba, pero un usuario creado por fuera de
     *                WordPress también debe caer en el estado seguro).
     */
    public function get_status($user_id)
    {
        $status = get_user_meta($user_id, self::META_KEY, true);

        return in_array($status, $this->all_statuses_slugs(), true) ? $status : self::PENDIENTE;
    }

    /**
     * Aplica un cambio de estado si la transición es válida.
     *
     * @return bool true si se aplicó, false si la transición no es
     *              válida (el llamador decide cómo informarlo: esta
     *              clase no sabe de HTML ni de mensajes de error).
     */
    public function set_status($user_id, $new_status)
    {
        $current = $this->get_status($user_id);

        if (!$this->can_transition($current, $new_status)) {
            return false;
        }

        update_user_meta($user_id, self::META_KEY, $new_status);

        /**
         * Punto de extensión: quien necesite reaccionar a un cambio de
         * estado (por ejemplo, avisar por correo al pasar a Activo) se
         * cuelga de esta acción en vez de duplicar la regla de aduana.
         */
        do_action('egc_user_status_changed', $user_id, $new_status, $current);

        return true;
    }

    /**
     * Regla de aduana: todo cambio de estado pasa por Pendiente.
     * Activo <-> Rechazado directo no está permitido.
     */
    public function can_transition($from, $to)
    {
        if ($from === $to) {
            return false;
        }

        return $from === self::PENDIENTE || $to === self::PENDIENTE;
    }

    /**
     * @return string[] Estados a los que puede pasar $current, según la
     *                   regla de aduana. Pensado para pintar solo las
     *                   opciones válidas en el formulario de gestión de
     *                   usuarios (la vista no reimplementa la regla).
     */
    public function valid_next_statuses($current)
    {
        return array_values(array_filter(
            $this->all_statuses_slugs(),
            function ($status) use ($current) {
                return $this->can_transition($current, $status);
            }
        ));
    }

    /**
     * @return array<string,string> slug => etiqueta legible, para
     *                                selects y badges en la vista.
     */
    public function all_statuses()
    {
        return [
            self::PENDIENTE => __('Pendiente', 'egc'),
            self::ACTIVO    => __('Activo', 'egc'),
            self::RECHAZADO => __('Rechazado', 'egc'),
        ];
    }

    private function all_statuses_slugs()
    {
        return array_keys($this->all_statuses());
    }
}
