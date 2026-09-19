<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Manifest del módulo SGF (Sistema de Gestión Financiera).
 *
 * `post_types` declara `billetera`, el primer CPT del módulo. A
 * diferencia de Blog (que reutiliza `post` nativo), acá sí hace falta
 * `register_post_type()` propio — ver modules/sgf/billetera/Billetera.php
 * — con `capability_type => ['billetera', 'billeteras']` y
 * `map_meta_cap => true`, para que sus capacidades queden aisladas de
 * las de cualquier otro módulo, tal como pide AUTORIZACIÓN.
 *
 * `sgf_editor` es el administrador del módulo: capacidades "others"
 * incluidas, puede gestionar las billeteras de cualquier usuario —
 * misma forma que `blog_editor` en Blog.
 *
 * `sgf_autor` es el usuario común del módulo: CRUD completo pero
 * únicamente sobre lo propio (WordPress resuelve "propio" vs "ajeno"
 * con `post_author` a través de `map_meta_cap`, sin comparación de
 * autor hardcodeada en ningún lado). Incluye `publish_posts` porque
 * en SGF no existe un flujo de revisión pendiente como en Blog: cada
 * usuario publica sus propios registros financieros de inmediato. No
 * hay rol de tipo "contributor" en este módulo.
 *
 * Ninguno de los dos roles trae `upload_files`: Billetera no admite
 * imagen destacada (`supports => ['title']` únicamente), así que esa
 * capacidad no tiene nada que habilitar todavía — se agrega el día
 * que un CPT del módulo realmente la necesite.
 *
 * IMPORTANTE sobre los nombres de las capacidades: a diferencia de
 * Blog, que reutiliza `post` (capability_type nativo, por eso
 * `edit_posts` es un nombre real), Billetera se registra con
 * `capability_type => ['billetera', 'billeteras']` (ver
 * modules/sgf/billetera/Billetera.php). WordPress computa sus
 * capacidades primitivas a partir del plural, no de "post": no existe
 * `edit_posts` para este CPT, existe `edit_billeteras`. Por eso las
 * claves de abajo van con el plural del recurso, no con las de Blog.
 */
return [
    'nombre' => __('Sistema de Gestión Financiera', 'egc'),

    'sigla' => 'SGF',

    'post_types' => ['billetera'],

    'roles' => [
        'sgf_editor' => [
            'name' => __('Editor de SGF', 'egc'),
            'capabilities' => [
                'read'                        => true,
                'edit_billeteras'             => true,
                'edit_others_billeteras'      => true,
                'edit_published_billeteras'   => true,
                'edit_private_billeteras'     => true,
                'publish_billeteras'          => true,
                'read_private_billeteras'     => true,
                'delete_billeteras'           => true,
                'delete_others_billeteras'    => true,
                'delete_published_billeteras' => true,
                'delete_private_billeteras'   => true,
            ],
        ],
        'sgf_autor' => [
            'name' => __('Autor de SGF', 'egc'),
            'capabilities' => [
                'read'                        => true,
                'edit_billeteras'             => true,
                'edit_published_billeteras'   => true,
                'publish_billeteras'          => true,
                'delete_billeteras'           => true,
                'delete_published_billeteras' => true,
            ],
        ],
    ],

    'assignable_roles' => [
        'billetera' => ['sgf_editor', 'sgf_autor'],
    ],
];
