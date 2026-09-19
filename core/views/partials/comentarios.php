<?php

use EGC\Core\BootstrapCommentWalker;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Bloque de comentarios completo: listado + paginación + formulario.
 * Sin lógica de negocio ni queries propias — todo nativo de
 * WordPress (comments_open(), wp_list_comments(), comment_form()),
 * con argumentos y un walker (BootstrapCommentWalker) puestos solo
 * para el estilo.
 *
 * Compartido por Blog (modules/blog/views/single.php) y por la Página
 * genérica del Core (core/views/pagina.php): ninguna Página nativa
 * tiene módulo dueño (post_types en los manifests son siempre CPT),
 * así que este partial vive en Core, no en ningún módulo — mismo
 * criterio de extracción que post-actions.php.
 *
 * El formulario deja fuera el campo "sitio web" (WordPress lo incluye
 * por defecto): en un formulario moderno se usa casi exclusivamente
 * para spam, y omitirlo es una decisión de diseño, no una limitación
 * técnica — se puede volver a agregar si hace falta.
 */
if (!comments_open() && !get_comments_number()) {
    return;
}
?>
<div class="col-lg-8 mt-5">
    <h2 class="h4 mb-4">
        <?php
        printf(
            /* translators: %s: cantidad de comentarios, ya formateada */
            esc_html(_n('%s comentario', '%s comentarios', get_comments_number(), 'egc')),
            esc_html(number_format_i18n(get_comments_number()))
        );
        ?>
    </h2>

    <?php if (have_comments()): ?>
        <div class="d-flex flex-column gap-3 mb-4">
            <?php
            wp_list_comments([
                'style' => 'div',
                'short_ping' => true,
                'walker' => new BootstrapCommentWalker(),
            ]);
            ?>
        </div>

        <?php the_comments_pagination(); ?>
    <?php endif; ?>

    <?php if (comments_open()): ?>
        <div class="comentario-card p-4 bg-body-tertiary rounded-3">
            <?php
            comment_form([
                'class_form' => 'comentario-form',
                'title_reply' => __('Dejá tu comentario', 'egc'),
                'title_reply_before' => '<h3 class="h5 mb-3">',
                'title_reply_after' => '</h3>',
                'comment_field' => '<div class="form-floating mb-3">'
                    . '<textarea id="comment" name="comment" class="form-control" style="height:110px" placeholder="' . esc_attr__('Comentario', 'egc') . '" required></textarea>'
                    . '<label for="comment">' . esc_html__('Comentario', 'egc') . '</label>'
                    . '</div>',
                'fields' => [
                    'author' => '<div class="form-floating mb-3">'
                        . '<input id="author" name="author" type="text" class="form-control" placeholder="' . esc_attr__('Nombre', 'egc') . '"' . (get_option('require_name_email') ? ' required' : '') . '>'
                        . '<label for="author">' . esc_html__('Nombre', 'egc') . '</label>'
                        . '</div>',
                    'email' => '<div class="form-floating mb-3">'
                        . '<input id="email" name="email" type="email" class="form-control" placeholder="' . esc_attr__('Correo', 'egc') . '"' . (get_option('require_name_email') ? ' required' : '') . '>'
                        . '<label for="email">' . esc_html__('Correo', 'egc') . '</label>'
                        . '</div>',
                ],
                'class_submit' => 'btn btn-primary',
                'submit_field' => '<p class="form-submit mb-0">%1$s %2$s</p>',
                'label_submit' => __('Publicar comentario', 'egc'),
            ]);
            ?>
        </div>
    <?php endif; ?>
</div>
