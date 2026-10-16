<?php

namespace EGC\Modules\Sca\Comite;

use EGC\Core\Singleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Comité (módulo SCA).
 *
 * Declara el recurso ante WordPress: el CPT y sus cuatro postmeta. Los
 * formularios, guards y handlers del administrador van en una clase
 * aparte (paso 6): esta cambia si cambia la FORMA del comité, la otra
 * si cambia el FLUJO de uso.
 *
 * El comité NO es público en el front-end: a los lectores nunca se les
 * muestra un comité ni un acta como pantalla — navegan directo a
 * listados de acuerdos (por estatus, por comité, por asignado). Solo lo
 * administran el editor del módulo (con páginas propias, paso 6) y el
 * superusuario (con la pantalla nativa de wp-admin, `show_ui`).
 *
 * Metas:
 * - `_alcance_total`: bandera "los miembros vigentes de este comité ven
 *   TODOS los acuerdos de TODOS los comités" (Junta Directiva). Es una
 *   bandera y no una comparación con el nombre "Junta Directiva" para
 *   que ninguna regla ni vista dependa de un texto. Hoy también decide
 *   que los puestos de este comité tienen vigencia y que se rotula
 *   "Acta" en vez de "Minuta"; son tres reglas distintas que hoy
 *   coinciden en un solo comité, y se separan en banderas propias el
 *   día que aparezca un segundo caso.
 * - `_puesto_coordinador`: el Puesto que coordina este comité (0 = sin
 *   coordinador). Quien ocupa ESE puesto en ESTE comité (tiene una
 *   membresía del comité con ese puesto) ve todos los acuerdos del
 *   comité y recibe los avisos de comentarios. Se declara acá y no con
 *   una bandera en el puesto: un puesto es global ("Presidente" sirve
 *   en todos los comités) y qué puesto coordina es decisión de cada
 *   comité. Cambiar de persona es cambiar la membresía, sin tocar el
 *   comité, y no hay una segunda fuente de verdad que mantener.
 * - `_proximo_acta` / `_proximo_acuerdo`: el próximo consecutivo a
 *   usar, por comité. Arrancan en 1 y el administrador los configura
 *   (tras una migración de datos históricos queda en el siguiente al
 *   último migrado). Guardar el PRÓXIMO y no el último hace que el
 *   valor que ve el administrador sea justo el que tomará el siguiente
 *   registro.
 */
class Comite
{
    use Singleton;

    const POST_TYPE = 'comite';

    const META_ALCANCE_TOTAL       = '_alcance_total';
    const META_PUESTO_COORDINADOR  = '_puesto_coordinador';
    const META_PROXIMO_ACTA     = '_proximo_acta';
    const META_PROXIMO_ACUERDO  = '_proximo_acuerdo';

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_meta']);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Comités', 'egc'),
                'singular_name'      => __('Comité', 'egc'),
                'add_new_item'       => __('Agregar comité', 'egc'),
                'edit_item'          => __('Editar comité', 'egc'),
                'new_item'           => __('Nuevo comité', 'egc'),
                'view_item'          => __('Ver comité', 'egc'),
                'search_items'       => __('Buscar comités', 'egc'),
                'not_found'          => __('No se encontraron comités', 'egc'),
                'not_found_in_trash' => __('No hay comités en la papelera', 'egc'),
            ],
            'public'          => false,
            'show_ui'         => true,
            'has_archive'     => false,
            'rewrite'         => false,
            'query_var'       => false,
            'supports'        => ['title'],
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }

    /**
     * `auth_callback` usa `edit_post` sobre el post_id de la meta:
     * nadie escribe estos datos de un comité ajeno por fuera del
     * formulario (defensa en profundidad, el handler de guardado del
     * paso 6 revalida también).
     */
    public function register_post_meta()
    {
        $auth_callback = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };

        register_post_meta(self::POST_TYPE, self::META_ALCANCE_TOTAL, [
            'type'              => 'boolean',
            'single'            => true,
            'default'           => false,
            'show_in_rest'      => false,
            'sanitize_callback' => function ($meta_value) {
                return filter_var($meta_value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            },
            'auth_callback'     => $auth_callback,
        ]);

        register_post_meta(self::POST_TYPE, self::META_PUESTO_COORDINADOR, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
        ]);

        $consecutivo = function ($meta_value) {
            return max(1, absint($meta_value));
        };

        foreach ([self::META_PROXIMO_ACTA, self::META_PROXIMO_ACUERDO] as $meta_key) {
            register_post_meta(self::POST_TYPE, $meta_key, [
                'type'              => 'integer',
                'single'            => true,
                'default'           => 1,
                'show_in_rest'      => false,
                'sanitize_callback' => $consecutivo,
                'auth_callback'     => $auth_callback,
            ]);
        }
    }

    /**
     * @return bool Si el comité tiene alcance total (Junta Directiva).
     */
    public static function alcance_total($comite_id)
    {
        return (bool) get_post_meta($comite_id, self::META_ALCANCE_TOTAL, true);
    }

    /**
     * @return int ID del puesto que coordina el comité (0 = ninguno).
     */
    public static function puesto_coordinador($comite_id)
    {
        return (int) get_post_meta($comite_id, self::META_PUESTO_COORDINADOR, true);
    }

    /**
     * @return bool Si $puesto_id es el puesto que coordina el comité.
     */
    public static function coordina($comite_id, $puesto_id)
    {
        $coordinador = self::puesto_coordinador($comite_id);

        return $coordinador > 0 && $coordinador === (int) $puesto_id;
    }

    /**
     * @return string Nombre del comité ('' si no existe).
     */
    public static function nombre($comite_id)
    {
        $comite = get_post($comite_id);

        return ($comite && $comite->post_type === self::POST_TYPE) ? $comite->post_title : '';
    }
}
