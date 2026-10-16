<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\Singleton;
use EGC\Core\UserStatus;
use EGC\Modules\Sca\Alcance;
use EGC\Modules\Sca\Miembro\Miembro;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — aviso por correo de cada comentario nuevo en un
 * acuerdo (módulo SCA).
 *
 * Qué resuelve WordPress y qué no (PRINCIPIO RECTOR): WordPress ya
 * avisa de un comentario nuevo, pero solo al AUTOR del post (y al
 * moderador si queda pendiente). En un acuerdo el autor es el dueño,
 * así que el coordinador y el administrador, que son a quienes hay que
 * avisar, no reciben nada. Lo que SÍ se aprovecha de lo nativo: la
 * acción `comment_post` (se dispara una vez por comentario guardado,
 * venga de wp-comments-post.php o de cualquier otra vía), `wp_mail()`
 * (con el SMTP que configura el Core) y `get_comment_link()`. Lo que se
 * apaga: el aviso nativo al autor (filtro `notify_post_author`), porque
 * el dueño pasa a recibir el aviso de esta clase y, si no, llegarían
 * dos correos distintos por el mismo comentario.
 *
 * A quién se avisa, en orden de regla:
 *
 *  1. Coordinadores del comité del acuerdo (Miembro::coordinadores).
 *  2. Administradores del módulo: los usuarios con el rol Editor de SCA.
 *     EXCEPTO cuando el dueño del acuerdo es un administrador (acuerdo
 *     sin asignar): en ese caso el aviso va solo al coordinador.
 *  3. El dueño del acuerdo, cuando el comentario lo escribió otra
 *     persona (la respuesta del administrador): así sabe que su
 *     solicitud fue atendida sin tener que volver a abrir el acuerdo.
 *
 * Nunca se avisa a quien escribió el comentario: si el comentarista es
 * un coordinador del comité (sea o no el dueño del acuerdo) se lo omite
 * y el aviso va a los demás destinatarios, administrador incluido; si
 * es el administrador, se omite al administrador. Pueden comentar
 * (Comentarios): el administrador, la Junta, el coordinador del comité
 * y el dueño.
 *
 * Solo recibe el aviso quien puede abrir el acuerdo y leerlo: un
 * coordinador o dueño debe estar enrolado (rol lector de SCA), nadie
 * puede tener la cuenta pendiente o rechazada, y debe tener un correo
 * válido. No se manda a correos sueltos (membresías sin cuenta): sería
 * invitar a alguien a quien nadie dio acceso.
 *
 * Es una clase aparte de Comentarios porque cambian por razones
 * distintas: Comentarios cambia con QUIÉN puede comentar; esta, con A
 * QUIÉN se avisa y qué dice el mensaje.
 *
 * El envío es "lo mejor posible": si wp_mail() falla no se interrumpe
 * el guardado del comentario (ya está guardado cuando corre este hook).
 */
class AvisoComentario
{
    use Singleton;

    /** Largo máximo del extracto del comentario dentro del correo. */
    const PALABRAS_EXTRACTO = 60;

    private function __construct()
    {
        add_action('comment_post', [$this, 'avisar'], 10, 3);
        add_filter('notify_post_author', [$this, 'sin_aviso_nativo'], 10, 2);
    }

    /**
     * Apaga el aviso nativo al autor SOLO para comentarios de acuerdos.
     *
     * @param bool $notificar
     * @param int  $comment_id
     * @return bool
     */
    public function sin_aviso_nativo($notificar, $comment_id)
    {
        $comentario = get_comment($comment_id);

        if ($comentario && get_post_type($comentario->comment_post_ID) === Acuerdo::POST_TYPE) {
            return false;
        }

        return $notificar;
    }

    /**
     * @param int        $comment_id
     * @param int|string $aprobado   1, 0 o 'spam'.
     * @param array      $datos
     */
    public function avisar($comment_id, $aprobado, $datos = [])
    {
        // Un comentario en espera o marcado como spam todavía no es una conversación.
        if ($aprobado !== 1 && $aprobado !== '1') {
            return;
        }

        $comentario = get_comment($comment_id);
        if (!$comentario || !in_array((string) $comentario->comment_type, ['', 'comment'], true)) {
            return;
        }

        $acuerdo = get_post($comentario->comment_post_ID);
        if (!$acuerdo || $acuerdo->post_type !== Acuerdo::POST_TYPE) {
            return;
        }

        foreach ($this->destinatarios($acuerdo, (int) $comentario->user_id) as $usuario) {
            $this->enviar($usuario, $acuerdo, $comentario);
        }
    }

    /**
     * @param \WP_Post $acuerdo
     * @param int      $autor_id Quien escribió el comentario (nunca recibe el aviso).
     * @return \WP_User[]
     */
    public function destinatarios($acuerdo, $autor_id)
    {
        $alcance = Alcance::get_instance();
        $dueno   = Asignacion::get_instance()->de($acuerdo->ID);

        $ids = Miembro::coordinadores(Acuerdo::comite_id($acuerdo->ID));

        // Dueño administrador = acuerdo sin asignar: el aviso es solo para el coordinador.
        if (!$alcance->administra((int) $acuerdo->post_author)) {
            $ids = array_merge($ids, $this->administradores());
        }

        if ($dueno['tipo'] === 'usuario') {
            $ids[] = $dueno['user_id'];
        }

        $usuarios = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id === (int) $autor_id) {
                continue;
            }

            $usuario = get_userdata($id);
            if ($usuario && $this->puede_recibir($usuario)) {
                $usuarios[] = $usuario;
            }
        }

        return $usuarios;
    }

    /**
     * @return int[] Administradores del módulo: los Editores de SCA.
     */
    private function administradores()
    {
        return array_map('intval', get_users(['role' => Asignacion::ROL_EDITOR, 'fields' => 'ID']));
    }

    /**
     * Correo válido, cuenta no bloqueada y con acceso real al módulo:
     * administra, o está enrolado. (Para un administrador no se exige
     * el rol lector: su acceso viene de sus capacidades.)
     */
    private function puede_recibir($usuario)
    {
        if (!is_email($usuario->user_email)) {
            return false;
        }

        $estado = UserStatus::get_instance()->get_status($usuario->ID);
        if (in_array($estado, [UserStatus::PENDIENTE, UserStatus::RECHAZADO], true)) {
            return false;
        }

        $alcance = Alcance::get_instance();

        return $alcance->administra($usuario->ID) || $alcance->enrolado($usuario->ID);
    }

    /**
     * Un correo por destinatario (no uno con copia a todos: nadie
     * necesita ver los correos de los demás). Texto plano: lo que hace
     * falta es el aviso y el enlace.
     */
    private function enviar($usuario, $acuerdo, $comentario)
    {
        $autor = get_userdata((int) $comentario->user_id);
        $autor = $autor ? $autor->display_name : (string) $comentario->comment_author;

        $titulo   = wp_specialchars_decode((string) $acuerdo->post_title, ENT_QUOTES);
        $extracto = wp_trim_words(
            wp_specialchars_decode(wp_strip_all_tags((string) $comentario->comment_content), ENT_QUOTES),
            self::PALABRAS_EXTRACTO
        );

        /* translators: %s: título del acuerdo */
        $asunto = sprintf(__('Nuevo comentario en %s', 'egc'), $titulo);

        $cuerpo = sprintf(
            /* translators: 1: nombre de quien comentó, 2: título del acuerdo, 3: texto del comentario, 4: enlace */
            __("%1\$s comentó en el acuerdo «%2\$s»:\n\n%3\$s\n\nVer y responder: %4\$s", 'egc'),
            wp_specialchars_decode((string) $autor, ENT_QUOTES),
            $titulo,
            $extracto,
            get_comment_link($comentario)
        );

        wp_mail($usuario->user_email, $asunto, $cuerpo);
    }
}
