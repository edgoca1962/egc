<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Alcance;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — quién puede comentar un acuerdo (módulo SCA).
 *
 * Los comentarios de los acuerdos usan el flujo NATIVO de WordPress:
 * comment_form() envía a wp-comments-post.php, que valida, guarda
 * (wp_new_comment) y redirige. No hay handler propio para crear. Lo
 * único propio es la regla de quién puede, y se engancha en dos
 * filtros nativos:
 *
 * 1. `comments_open` — decide si el formulario se muestra Y si
 *    wp-comments-post.php acepta el envío (llama a comments_open() antes
 *    de guardar, así que un POST armado a mano tampoco pasa). Para un
 *    acuerdo está abierto exactamente cuando hay sesión iniciada y el
 *    usuario puede VER ese acuerdo (Alcance::ve): quien puede leer la
 *    conversación puede participar en ella. Por las reglas de alcance
 *    eso son, y solo ellos:
 *      - el administrador del módulo (editor de SCA; también el
 *        Administrador General y el superusuario);
 *      - cualquier miembro vigente de la Junta Directiva;
 *      - el administrador (coordinador) del comité del acuerdo;
 *      - el dueño del acuerdo (su post_author).
 *    Un miembro común de un comité solo ve lo suyo, así que solo
 *    comenta lo suyo. No hay una segunda lista de "quién comenta" que
 *    pudiera discrepar de la de "quién ve": ver y comentar son la misma
 *    pregunta y la contesta un solo servicio.
 *    Es la ÚNICA fuente de verdad: se ignora el `comment_status`
 *    guardado en el post, que depende del valor por omisión del sitio
 *    (suele ser "cerrado") y de cómo se creó el acuerdo (formulario,
 *    importación). Así ningún acuerdo queda mudo ni abierto por
 *    accidente.
 *
 * 2. `pre_comment_approved` — el comentario de alguien con acceso al
 *    acuerdo se aprueba solo; es una conversación interna entre
 *    personas ya autorizadas, y exigir moderación la volvería
 *    inservible. Solo se mejora un "pendiente" (0): si WordPress o un
 *    plugin antispam lo marcó como spam o devolvió un error, se respeta.
 *
 * No hay moderación: como todos los que pueden comentar ya están
 * autorizados y sus comentarios se aprueban solos, no queda nada que
 * aprobar. `moderate_comments` sigue siendo del superusuario, como fija
 * el proyecto. El aviso por correo de cada comentario es de
 * AvisoComentario (otra razón para cambiar: a quién se avisa).
 */
class Comentarios
{
    use Singleton;

    private function __construct()
    {
        add_filter('comments_open', [$this, 'abierto'], 10, 2);
        add_filter('pre_comment_approved', [$this, 'aprobar'], 20, 2);
    }

    /**
     * @param bool $abierto
     * @param int  $post_id
     * @return bool
     */
    public function abierto($abierto, $post_id)
    {
        if (get_post_type($post_id) !== Acuerdo::POST_TYPE) {
            return $abierto;
        }

        return $this->puede_comentar(get_current_user_id(), (int) $post_id);
    }

    /**
     * @param int|string|\WP_Error $aprobado
     * @param array                $datos    Datos del comentario.
     * @return int|string|\WP_Error
     */
    public function aprobar($aprobado, $datos)
    {
        $post_id = isset($datos['comment_post_ID']) ? (int) $datos['comment_post_ID'] : 0;

        if (get_post_type($post_id) !== Acuerdo::POST_TYPE) {
            return $aprobado;
        }

        if (($aprobado === 0 || $aprobado === '0') && $this->puede_comentar(get_current_user_id(), $post_id)) {
            return 1;
        }

        return $aprobado;
    }

    private function puede_comentar($user_id, $acuerdo_id)
    {
        return $user_id > 0 && Alcance::get_instance()->ve($user_id, $acuerdo_id);
    }
}
