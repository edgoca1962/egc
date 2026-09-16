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
 * `post` (no a páginas). `blog_contributor` refleja el nativo
 * "Contributor" (puede crear y editar lo propio, no publicar), con
 * `upload_files` agregado para que pueda poner una imagen destacada en
 * sus propios borradores — WordPress no se lo da por defecto.
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
                'read'         => true,
                'edit_posts'   => true,
                'delete_posts' => true,
                'upload_files' => true,
            ],
        ],
    ],

    'assignable_roles' => [
        'post' => ['blog_editor', 'blog_contributor'],
    ],
];
