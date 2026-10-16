<?php

namespace EGC\Modules\Sca\Puesto;

use EGC\Core\Singleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Puesto (módulo SCA).
 *
 * Coordinador, Miembro, Presidente, Tesorero… Un puesto con `post_parent`
 * (un comité) solo es elegible en ese comité — los puestos "variados" de
 * Junta Directiva cuelgan de ella; Coordinador y Miembro no tienen padre
 * y sirven en todos. Se usa el `post_parent` nativo y no una meta
 * propia: WordPress ya modela "pertenece a" y WP_Query lo filtra solo.
 *
 * Se mantiene como CPT (y no como taxonomía) justamente por esa
 * pertenencia opcional a un comité, que una taxonomía plana no expresa.
 *
 * El puesto no sabe si coordina: qué puesto coordina un comité lo
 * declara el propio comité (`_puesto_coordinador` en Comite).
 *
 * No es público en el front-end: lo administra el editor del módulo y
 * el superusuario; ningún lector lo ve.
 */
class Puesto
{
    use Singleton;

    const POST_TYPE = 'puesto';

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Puestos', 'egc'),
                'singular_name'      => __('Puesto', 'egc'),
                'add_new_item'       => __('Agregar puesto', 'egc'),
                'edit_item'          => __('Editar puesto', 'egc'),
                'new_item'           => __('Nuevo puesto', 'egc'),
                'view_item'          => __('Ver puesto', 'egc'),
                'search_items'       => __('Buscar puestos', 'egc'),
                'not_found'          => __('No se encontraron puestos', 'egc'),
                'not_found_in_trash' => __('No hay puestos en la papelera', 'egc'),
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
}
