<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Manifest del módulo Blog.
 *
 * `post_types` declara `post`, el CPT nativo de WordPress — no se
 * registra nada nuevo: WordPress ya trae `post` con sus propias
 * capacidades (edit_posts, edit_others_posts, publish_posts, etc.), así
 * que los roles de este módulo las usan directo, sin capability_type
 * propio ni register_post_type().
 *
 * `blog_editor` refleja el rol nativo "Editor" de WordPress, acotado a
 * `post` (no a páginas): `edit_others_posts`/`delete_others_posts` lo
 * habilitan a mantener CUALQUIER entrada — es, junto con el super
 * usuario, el único rol de Blog que administra el recurso
 * (UserScope::manages('post') se apoya justo en esa capacidad).
 *
 * `blog_contributor` y `blog_author` reflejan los nativos "Contributor"
 * y "Author": ninguno de los dos tiene `edit_others_posts` ni
 * `delete_others_posts`, así que ambos dan mantenimiento únicamente a
 * sus propias entradas — la distinción entre ellos es solo
 * `publish_posts`: el Autor lo tiene y publica directo; el Contributor
 * no, así que sus entradas nuevas quedan `pending` hasta que alguien
 * que administra el recurso (blog_editor o el super usuario) las
 * publica desde "Artículos pendientes de publicar". `upload_files` se
 * agrega en ambos para que puedan poner una imagen destacada en lo
 * propio — WordPress no se lo da por defecto a ninguno de los dos.
 *
 * `blog_contributor` suma además `edit_published_posts`, que el
 * "Contributor" nativo NO trae: sin ella, map_meta_cap exige esa
 * capacidad (además de `edit_posts`) para una entrada PROPIA ya
 * publicada, así que apenas blog_editor publica lo que el Contributor
 * mandó a revisión, el botón de Editar desaparecía — podía editar
 * mientras estaba pending, pero no después. Se agrega para que pueda
 * seguir editando lo suyo sin importar el estatus. A propósito NO se
 * agrega `delete_published_posts`: sigue sin poder eliminar, que es lo
 * que se pidió explícitamente.
 */
return [
    'nombre' => __('Blog', 'egc'),

    'post_types' => ['post'],

    'roles' => [
        'blog_editor' => [
            'name' => __('Editor de Blog', 'egc'),
            'capabilities' => [
                'read'                    => true,
                'edit_posts'              => true,
                'edit_others_posts'       => true,
                'edit_published_posts'    => true,
                'edit_private_posts'      => true,
                'publish_posts'           => true,
                'read_private_posts'      => true,
                'delete_posts'            => true,
                'delete_others_posts'     => true,
                'delete_published_posts'  => true,
                'delete_private_posts'    => true,
                'upload_files'            => true,
            ],
        ],
        'blog_contributor' => [
            'name' => __('Colaborador de Blog', 'egc'),
            'capabilities' => [
                'read'                 => true,
                'edit_posts'           => true,
                'edit_published_posts' => true,
                'delete_posts'         => true,
                'upload_files'         => true,
            ],
        ],
        'blog_author' => [
            'name' => __('Autor de Blog', 'egc'),
            'capabilities' => [
                'read'                    => true,
                'edit_posts'              => true,
                'edit_published_posts'    => true,
                'publish_posts'           => true,
                'delete_posts'            => true,
                'delete_published_posts'  => true,
                'upload_files'            => true,
            ],
        ],
    ],

    'assignable_roles' => [
        'post' => ['blog_editor', 'blog_author', 'blog_contributor'],
    ],
];
