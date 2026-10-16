<?php

namespace EGC\Modules\Sca;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Acuerdo\Acuerdo;
use EGC\Modules\Sca\Comite\Comite;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — eliminación en cascada comité → actas → acuerdos
 * (módulo SCA).
 *
 * Qué resuelve WordPress y qué no (PRINCIPIO RECTOR): WordPress no
 * tiene eliminación en cascada entre posts: `wp_trash_post()` manda a
 * la papelera SOLO ese registro y deja huérfanos a los que apuntan a
 * él por `post_parent`. Lo que sí trae son los dos puntos de enganche
 * que hacen falta, y se usan tal cual:
 *
 *  - `pre_trash_post` (filtro): devolver `false` cancela la papelera.
 *  - `wp_trash_post` (acción): corre justo antes de cambiar el estado.
 *
 * Qué hace:
 *  - Eliminar un comité manda a la papelera sus actas y los acuerdos
 *    de cada acta. Eliminar un acta, sus acuerdos.
 *  - Los puestos propios de un comité y las membresías NO se tocan:
 *    tienen su propio mantenimiento (decisión de Edwin).
 *  - Todo o nada: antes de eliminar nada se verifica que quien
 *    elimina pueda eliminar CADA descendiente (`delete_post`, o sea
 *    capacidades de WordPress, no un permiso paralelo). Si no puede
 *    con uno solo, no se elimina ni siquiera el registro pedido; así
 *    no queda una cascada a medias.
 *  - Contenido, no datos: es la papelera nativa (`wp_trash_post`),
 *    nunca SQL ni borrado definitivo; la restauración, si hiciera
 *    falta, la hace el superusuario desde wp-admin.
 *
 * Por qué hooks y no código en los handlers de eliminar: vale para
 * cualquier vía (botón del front-end, wp-admin del superusuario,
 * WP-CLI), igual que Sincronizacion con los guardados.
 *
 * Por qué una clase aparte de Sincronizacion: aquella deriva campos de
 * la jerarquía y por diseño no rechaza nada; esta aplica una política
 * de eliminación y sí puede rechazar. Cambian por razones distintas.
 *
 * También ofrece `resumen()` para que la pantalla avise, antes de
 * confirmar, cuánto se va a eliminar: la misma consulta que usa la
 * cascada, de modo que el aviso y la acción no pueden diferir.
 */
class Cascada
{
    use Singleton;

    /** Estados "vivos": lo que ya está en la papelera no se vuelve a mandar. */
    const ESTADOS = ['publish', 'draft', 'pending', 'private', 'future'];

    private function __construct()
    {
        add_filter('pre_trash_post', [$this, 'validar'], 10, 2);
        add_action('wp_trash_post', [$this, 'enviar_a_papelera']);
    }

    /**
     * @param bool|null $check   null = seguir; cualquier otro valor cancela.
     * @param \WP_Post  $post
     * @return bool|null
     */
    public function validar($check, $post)
    {
        if ($check !== null || !$post || !$this->tiene_hijos_posibles($post->post_type)) {
            return $check;
        }

        foreach ($this->descendientes($post->ID, $post->post_type) as $id) {
            if (!current_user_can('delete_post', $id)) {
                return false;
            }
        }

        return $check;
    }

    public function enviar_a_papelera($post_id)
    {
        $post = get_post($post_id);

        if (!$post || !$this->tiene_hijos_posibles($post->post_type)) {
            return;
        }

        // Los hijos directos; cada uno dispara este mismo hook y baja otro nivel.
        foreach ($this->hijos($post->ID, $post->post_type) as $hijo_id) {
            wp_trash_post($hijo_id);
        }
    }

    /**
     * @return array{actas:int,acuerdos:int} Lo que se eliminaría además del registro pedido.
     */
    public function resumen($post_id)
    {
        $post = get_post($post_id);
        $out  = ['actas' => 0, 'acuerdos' => 0];

        if (!$post) {
            return $out;
        }

        foreach ($this->descendientes($post->ID, $post->post_type) as $id) {
            $tipo = get_post_type($id);
            if ($tipo === Acta::POST_TYPE) {
                $out['actas']++;
            } elseif ($tipo === Acuerdo::POST_TYPE) {
                $out['acuerdos']++;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------

    private function tiene_hijos_posibles($post_type)
    {
        return $post_type === Comite::POST_TYPE || $post_type === Acta::POST_TYPE;
    }

    /**
     * @return int[] Hijos directos vivos: las actas de un comité, o los acuerdos de un acta.
     */
    private function hijos($post_id, $post_type)
    {
        $tipo_hijo = $post_type === Comite::POST_TYPE ? Acta::POST_TYPE : Acuerdo::POST_TYPE;

        return get_posts([
            'post_type'          => $tipo_hijo,
            'post_status'        => self::ESTADOS,
            'post_parent'        => (int) $post_id,
            'posts_per_page'     => -1,
            'fields'             => 'ids',
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
        ]);
    }

    /**
     * @return int[] Todos los descendientes vivos, en cualquier nivel.
     */
    private function descendientes($post_id, $post_type)
    {
        if (!$this->tiene_hijos_posibles($post_type)) {
            return [];
        }

        $todos = [];

        foreach ($this->hijos($post_id, $post_type) as $hijo_id) {
            $todos[] = (int) $hijo_id;
            $todos   = array_merge($todos, $this->descendientes($hijo_id, get_post_type($hijo_id)));
        }

        return $todos;
    }
}
