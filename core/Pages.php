<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Helper de siembra de páginas nativas de WordPress.
 *
 * Los pasos siguientes (login, solicitar ingreso, definir contraseña,
 * gestión de usuarios, mi cuenta, cambio de contraseña) necesitan cada
 * uno su propia Página nativa para poder mostrarse — como el guard de
 * `/wp-admin/` bloquea el acceso a todos salvo el superusuario, nadie
 * más podría crearlas a mano desde wp-admin. Esta clase centraliza esa
 * única operación (buscar por slug, crear si no existe) para que no se
 * repita en cada una de esas piezas.
 */
class Pages
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda desde quien
        // necesite sembrar una página (ver los pasos siguientes).
    }

    /**
     * Busca una Página por slug; si no existe, la crea.
     *
     * @return int ID de la página encontrada o creada. 0 si falló la
     *             creación (se registra con error_log(), para no
     *             frenar el resto del arranque del sitio por esto).
     */
    public function find_or_create($title, $slug)
    {
        $existing = get_page_by_path($slug);
        if ($existing) {
            return $existing->ID;
        }

        $id = wp_insert_post([
            'post_title'  => $title,
            'post_name'   => $slug,
            'post_status' => 'publish',
            'post_type'   => 'page',
        ]);

        if (is_wp_error($id) || !$id) {
            error_log(sprintf('EGC: no se pudo crear la página "%s" (%s).', $title, $slug));
            return 0;
        }

        return $id;
    }
}
