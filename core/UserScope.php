<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Qué recursos y qué roles puede administrar el usuario actual.
 *
 * Servicio pasivo (sin hooks): UserManagement lo consulta bajo demanda
 * para saber, del usuario logeado, sobre qué post_types tiene permiso
 * de "administrar" (edit_others_*) y qué roles puede ofrecer al
 * asignar acceso a otro usuario — sin que la vista de gestión de
 * usuarios tenga que conocer capacidades ni manifests directamente.
 *
 * No decide nada nuevo: solo junta current_user_can() (autorización
 * real) con lo que cada manifest declaró (roles, post_types,
 * assignable_roles).
 */
class UserScope
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda.
    }

    /**
     * @return bool Si el usuario actual es Administrador General. Se
     *              apoya en la capacidad marcadora, nunca en el nombre
     *              del rol.
     */
    public function is_general_admin()
    {
        return current_user_can('edit_users');
    }

    /**
     * @return bool Si el usuario actual administra este post_type: o
     *              bien es Administrador General (accede a todo), o
     *              bien tiene la capacidad "de otros" de ese recurso
     *              (es administrador de ese módulo).
     */
    public function manages($post_type)
    {
        if ($this->is_general_admin()) {
            return true;
        }

        return current_user_can("edit_others_{$post_type}s");
    }

    /**
     * @return string[] post_types (de todos los manifests) que el
     *                   usuario actual administra. Es lo que
     *                   UserManagement usa para saber qué columnas de
     *                   acceso mostrar y qué puede editar.
     */
    public function managed_post_types()
    {
        $managed = [];

        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            foreach ($this->post_types_of($manifest) as $post_type) {
                if ($this->manages($post_type)) {
                    $managed[] = $post_type;
                }
            }
        }

        return array_values(array_unique($managed));
    }

    /**
     * @return string[] Roles asignables para $post_type según el
     *                   manifest de su módulo (mutuamente excluyentes
     *                   entre sí: UserManagement solo permite elegir
     *                   uno de esta lista por recurso).
     */
    public function assignable_roles($post_type)
    {
        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            if (!in_array($post_type, $this->post_types_of($manifest), true)) {
                continue;
            }

            if (isset($manifest['assignable_roles'][$post_type]) && is_array($manifest['assignable_roles'][$post_type])) {
                return $manifest['assignable_roles'][$post_type];
            }
        }

        return [];
    }

    private function post_types_of($manifest)
    {
        if (!isset($manifest['post_types']) || !is_array($manifest['post_types'])) {
            return [];
        }

        return $manifest['post_types'];
    }
}
