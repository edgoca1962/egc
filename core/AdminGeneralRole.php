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
     * Nombres de capacidad para un post_type, leídos de
     * get_post_type_object($post_type)->cap — el objeto que WordPress ya
     * arma al registrar el CPT — en vez de reconstruirlos agregando una
     * "s". Esa "s" no es un plural gramatical: es el sufijo que
     * register_post_type() usa por defecto cuando capability_type es un
     * string simple, y no coincide con la capacidad real apenas un
     * módulo declara un plural irregular (capability_type =>
     * ['actividad', 'actividades']). Misma corrección que UserScope
     * aplica del lado de "administra este post_type".
     */
    private function capabilities_for($post_type)
    {
        $post_type_object = get_post_type_object($post_type);
        if (!$post_type_object) {
            return [];
        }

        $cap = $post_type_object->cap;

        return [
            $cap->edit_posts,
            $cap->edit_others_posts,
            $cap->edit_published_posts,
            $cap->edit_private_posts,
            $cap->publish_posts,
            $cap->read_private_posts,
            $cap->delete_posts,
            $cap->delete_others_posts,
            $cap->delete_published_posts,
            $cap->delete_private_posts,
        ];
    }
}
