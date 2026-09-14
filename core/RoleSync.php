<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Sincroniza los roles declarados por los manifests de los módulos
 * presentes.
 *
 * Único mecanismo de permisos del proyecto: capacidades nativas de
 * WordPress. Cada módulo declara sus propios roles, con las
 * capacidades de sus propios recursos, en la clave `roles` de su
 * manifest. Esta clase los da de alta y, si un módulo deja de estar
 * presente, limpia los roles que había registrado — comparando contra
 * un registro guardado en una opción, porque WordPress no tiene forma
 * nativa de saber "qué módulo declaró este rol".
 */
class RoleSync
{
    use Singleton;

    const OPTION_KEY = 'egc_synced_roles';

    private function __construct()
    {
        add_action('after_switch_theme', [$this, 'sync']);
        add_action('admin_init', [$this, 'sync']);
    }

    public function sync()
    {
        $manifests = ModuleLoader::get_instance()->discover();

        $declared = [];
        foreach ($manifests as $manifest) {
            if (empty($manifest['roles']) || !is_array($manifest['roles'])) {
                continue;
            }
            foreach ($manifest['roles'] as $role_slug => $role_data) {
                $declared[$role_slug] = $role_data;
            }
        }

        foreach ($declared as $role_slug => $role_data) {
            $name = $role_data['name'] ?? $role_slug;
            $capabilities = $role_data['capabilities'] ?? [];

            // remove + add (no solo add) para que un cambio de
            // capacidades en el manifest también se refleje: add_role()
            // nativo no hace nada si el rol ya existe.
            remove_role($role_slug);
            add_role($role_slug, $name, $capabilities);
        }

        $previously_synced = get_option(self::OPTION_KEY, []);
        $now_synced = array_keys($declared);

        $orphaned = array_diff($previously_synced, $now_synced);
        foreach ($orphaned as $role_slug) {
            remove_role($role_slug);
        }

        update_option(self::OPTION_KEY, $now_synced);
    }
}
