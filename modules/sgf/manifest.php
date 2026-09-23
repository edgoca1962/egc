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
 *
 * `libro` (ver modules/sgf/libro/Libro.php) se suma con el mismo
 * criterio: capability_type propio (`libro`/`libros`), así que sus
 * capacidades son `edit_libros`/`edit_others_libros`/etc., no
 * `edit_billeteras`. `sgf_editor` y `sgf_autor` reciben las de los dos
 * recursos a la vez — un movimiento de Libro siempre cuelga de una
 * Billetera (su `post_parent`), así que administrar o ser autor de
 * billeteras y de sus movimientos es, en la práctica, un solo alcance,
 * no dos independientes.
 *
 * Por eso `libro` NO tiene su propia entrada en `assignable_roles`:
 * si la tuviera, "Gestión de usuarios" (core/UserManagement.php)
 * mostraría una columna aparte para asignar el rol de Libro, separada
 * de la de Billetera, obligando a asignar el mismo rol dos veces por
 * usuario y pudiendo quedar desincronizadas entre sí. Con una sola
 * entrada (`billetera`) que ya cubre ambos recursos alcanza, y
 * `UserManagement::view_state()` omite de la tabla cualquier post_type
 * managed sin `assignable_roles` propio — ver ese archivo.
 *
 * `presupuesto` (ver modules/sgf/presupuesto/Presupuesto.php) se suma
 * con el mismo criterio que `libro`: capability_type propio
 * (`presupuesto`/`presupuestos`), capacidades para `sgf_editor` y
 * `sgf_autor`, y tampoco tiene entrada propia en `assignable_roles` —
 * mismo motivo: sigue siendo un solo alcance ("¿participa de SGF?"),
 * no uno nuevo por cada recurso que se agregue al módulo.
 */
return [
    'nombre' => __('Sistema de Gestión Financiera', 'egc'),

    'sigla' => 'SGF',

    'post_types' => ['billetera', 'libro', 'presupuesto'],

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
                'edit_libros'                 => true,
                'edit_others_libros'          => true,
                'edit_published_libros'       => true,
                'edit_private_libros'         => true,
                'publish_libros'              => true,
                'read_private_libros'         => true,
                'delete_libros'               => true,
                'delete_others_libros'        => true,
                'delete_published_libros'     => true,
                'delete_private_libros'       => true,
                'edit_presupuestos'             => true,
                'edit_others_presupuestos'      => true,
                'edit_published_presupuestos'   => true,
                'edit_private_presupuestos'     => true,
                'publish_presupuestos'          => true,
                'read_private_presupuestos'     => true,
                'delete_presupuestos'           => true,
                'delete_others_presupuestos'    => true,
                'delete_published_presupuestos' => true,
                'delete_private_presupuestos'   => true,
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
                'edit_libros'                 => true,
                'edit_published_libros'       => true,
                'publish_libros'              => true,
                'delete_libros'               => true,
                'delete_published_libros'     => true,
                'edit_presupuestos'             => true,
                'edit_published_presupuestos'   => true,
                'publish_presupuestos'          => true,
                'delete_presupuestos'           => true,
                'delete_published_presupuestos' => true,
            ],
        ],
    ],

    'assignable_roles' => [
        'billetera' => ['sgf_editor', 'sgf_autor'],
    ],
];
