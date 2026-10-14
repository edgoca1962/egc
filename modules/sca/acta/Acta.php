<?php

namespace EGC\Modules\Sca\Acta;

use EGC\Core\Singleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Acta/Minuta (módulo SCA).
 *
 * Una sola clase para los dos rótulos: es el mismo recurso, y la
 * palabra ("Acta" para Junta Directiva, "Minuta" para el resto) la
 * decide el comité al formar el título (paso 3), no el tipo de post.
 *
 * - Comité: `post_parent` nativo.
 * - Fecha de la reunión: `post_date` nativo, que el administrador debe
 *   ingresar (el registro puede hacerse días después de la sesión). Se
 *   propaga a los acuerdos del acta (paso 3).
 * - `_numero`: consecutivo del acta DENTRO de su comité. Lo asigna el
 *   sistema desde el contador del comité; solo una migración de datos
 *   históricos lo fija a mano, respetando el de origen.
 *
 * El título (`Acta-### AAAA-MM-DD Nombre Comité`) se arma
 * automáticamente (paso 3), no se teclea.
 *
 * No es público en el front-end: los lectores llegan a los acuerdos
 * directamente, sin pasar por actas.
 */
class Acta
{
    use Singleton;

    const POST_TYPE = 'acta';

    const META_NUMERO = '_numero';

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_meta']);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Actas', 'egc'),
                'singular_name'      => __('Acta', 'egc'),
                'add_new_item'       => __('Agregar acta', 'egc'),
                'edit_item'          => __('Editar acta', 'egc'),
                'new_item'           => __('Nueva acta', 'egc'),
                'view_item'          => __('Ver acta', 'egc'),
                'search_items'       => __('Buscar actas', 'egc'),
                'not_found'          => __('No se encontraron actas', 'egc'),
                'not_found_in_trash' => __('No hay actas en la papelera', 'egc'),
            ],
            'public'          => false,
            'show_ui'         => true,
            'has_archive'     => false,
            'rewrite'         => false,
            'query_var'       => false,
            'supports'        => ['title', 'editor'],
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }

    public function register_post_meta()
    {
        register_post_meta(self::POST_TYPE, self::META_NUMERO, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
            'auth_callback'     => function ($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
        ]);
    }

    /**
     * @return int Comité (post_parent) del acta; 0 si no existe.
     */
    public static function comite_id($acta_id)
    {
        $acta = get_post($acta_id);

        return ($acta && $acta->post_type === self::POST_TYPE) ? (int) $acta->post_parent : 0;
    }
}
