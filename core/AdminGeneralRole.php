<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Rol 'Administrador General': gestiona usuarios y tiene acceso de
 * edición completo a los recursos de TODOS los módulos instalados.
 *
 * No es un rol de módulo (ningún manifest lo declara): es el único
 * rol que el Core arma él mismo, porque su alcance por definición
 * cruza todos los módulos — un módulo no puede declarar un rol que
 * necesita conocer post types de otros módulos.
 *
 * La capacidad marcadora es 'edit_users': es la que AdminGuard/las
 * vistas usan para distinguir "este usuario es Administrador General"
 * sin comparar el nombre del rol (ver UserScope y MenuResolver).
 */
class AdminGeneralRole
{
    use Singleton;

    const ROLE = 'administrador_general';

    const OPTION_KEY = 'egc_admin_general_caps';

    private function __construct()
    {
        add_action('after_switch_theme', [$this, 'sync']);
        add_action('admin_init', [$this, 'sync']);
    }

    /**
     * Recalcula las capacidades del rol a partir de los post_types que
     * los módulos presentes declaran en su manifest. Se recalcula en
     * cada admin_init (no solo al activar el tema) porque agregar o
     * quitar un módulo cambia el conjunto de post_types sin que el
     * tema se reactive.
     */
    public function sync()
    {
        $capabilities = $this->build_capabilities();

        remove_role(self::ROLE);
        add_role(self::ROLE, __('Administrador General', 'egc'), $capabilities);

        update_option(self::OPTION_KEY, $capabilities);
    }

    /**
     * @return array<string,bool> Capacidades base de gestión de
     *                             usuarios más, por cada post_type de
     *                             cada manifest, las capacidades
     *                             "de otros" que dan control total
     *                             sobre ese recurso.
     */
    private function build_capabilities()
    {
        $capabilities = [
            'read'          => true,
            'list_users'    => true,
            'edit_users'    => true,
            'promote_users' => true,
        ];

        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            foreach ($this->post_types_of($manifest) as $post_type) {
                foreach ($this->capabilities_for($post_type) as $capability) {
                    $capabilities[$capability] = true;
                }
            }
        }

        return $capabilities;
    }

    private function post_types_of($manifest)
    {
        if (!isset($manifest['post_types']) || !is_array($manifest['post_types'])) {
            return [];
        }

        return $manifest['post_types'];
    }

    /**
     * Nombres de capacidad para un post_type, asumiendo la convención
     * del proyecto: capability_type del CPT == slug del post_type
     * (plural agregando 's'). Es la misma convención que usa UserScope
     * para lo contrario (recortar el acceso a "lo propio").
     */
    private function capabilities_for($post_type)
    {
        $plural = $post_type . 's';

        return [
            "edit_{$plural}",
            "edit_others_{$plural}",
            "edit_published_{$plural}",
            "edit_private_{$plural}",
            "publish_{$plural}",
            "read_private_{$plural}",
            "delete_{$plural}",
            "delete_others_{$plural}",
            "delete_published_{$plural}",
            "delete_private_{$plural}",
        ];
    }
}
