<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Pinta wp_list_comments() como tarjetas de Bootstrap 5 en vez de la
 * lista <li> plana que trae WordPress por defecto — mismo criterio que
 * BootstrapNavWalker para los menús: no reimplementa nada de la
 * lógica de comentarios (anidado, moderación, pings), solo traduce lo
 * que WordPress ya calcula a marcado de Bootstrap.
 *
 * Se usa siempre con 'style' => 'div' (ver
 * core/views/partials/comentarios.php): con ese estilo WordPress no
 * agrega ningún <ul>/<ol> propio, así que este walker controla el
 * 100% del marcado, de la tarjeta de nivel 0 a la respuesta más
 * anidada — no hace falta que la vista envuelva el llamado en nada.
 *
 * El aviso de "pendiente de moderación" si lo replica este walker a
 * mano (WordPress no lo agrega solo): es lo mismo que hace el walker
 * por defecto de WordPress (Walker_Comment::comment()) en su propio
 * código, no algo que comment_text() resuelva por su cuenta.
 */
class BootstrapCommentWalker extends \Walker_Comment
{
    public function start_lvl(&$output, $depth = 0, $args = [])
    {
        $output .= '<div class="comentario-respuesta ms-4 ms-sm-5 ps-3 border-start mt-3">';
    }

    public function end_lvl(&$output, $depth = 0, $args = [])
    {
        $output .= '</div>';
    }

    public function start_el(&$output, $comment, $depth = 0, $args = [], $id = 0)
    {
        $GLOBALS['comment'] = $comment;

        $tipo = get_comment_type($comment);

        if (in_array($tipo, ['pingback', 'trackback'], true) && !empty($args['short_ping'])) {
            $output .= '<div class="text-muted small mb-2">'
                . esc_html__('Pingback:', 'egc') . ' ' . get_comment_author_link($comment)
                . '</div>';
            return;
        }

        $clases = implode(' ', get_comment_class('', $comment, null));
        $avatar_size = $args['avatar_size'] ?? 44;
        $es_del_autor = in_array('bypostauthor', get_comment_class('', $comment, null), true);

        ob_start();
        ?>
        <div id="comment-<?php comment_ID(); ?>"
            class="<?php echo esc_attr($clases); ?> comentario-card p-3 bg-body-tertiary rounded-3">
            <div class="d-flex gap-3">
                <?php echo get_avatar($comment, $avatar_size, '', '', ['class' => ['rounded-circle', 'flex-shrink-0']]); ?>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="fw-semibold"><?php comment_author(); ?></span>
                            <?php if ($es_del_autor): ?>
                                <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis ms-1"
                                    style="font-size:.7rem;">
                                    <?php esc_html_e('Autor', 'egc'); ?>
                                </span>
                            <?php endif; ?>
                            <div class="small text-muted">
                                <a class="link-secondary text-decoration-none"
                                    href="<?php echo esc_url(get_comment_link($comment)); ?>">
                                    <?php echo esc_html(get_comment_date('', $comment) . ' · ' . get_comment_time()); ?>
                                </a>
                            </div>
                        </div>
                        <?php if (get_option('thread_comments') && !empty($args['max_depth']) && $args['max_depth'] > 1): ?>
                            <?php
                            echo get_comment_reply_link(array_merge($args, [
                                'depth' => $depth,
                                'max_depth' => $args['max_depth'],
                                'reply_text' => '<i class="bi bi-reply" aria-hidden="true"></i> ' . esc_html__('Responder', 'egc'),
                                'class' => 'comentario-accion link-secondary text-decoration-none',
                            ]));
                            ?>
                        <?php endif; ?>
                    </div>

                    <?php if ('0' === $comment->comment_approved): ?>
                        <p class="text-muted small fst-italic mb-1 mt-2">
                            <?php esc_html_e('Tu comentario está pendiente de moderación.', 'egc'); ?>
                        </p>
                    <?php endif; ?>

                    <div class="mt-2">
                        <?php comment_text($comment, $args); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $output .= ob_get_clean();
    }

    public function end_el(&$output, $comment, $depth = 0, $args = [])
    {
        // Nada que cerrar: start_el ya emite el <div> de la tarjeta
        // completo (abre y cierra) por comentario.
    }
}
