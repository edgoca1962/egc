<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\LoginPage;
use EGC\Core\Singleton;
use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Alcance;
use EGC\Modules\Sca\Consecutivo;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — qué acuerdos devuelve CUALQUIER consulta de acuerdos
 * del front-end, y quién puede entrar a sus pantallas (módulo SCA).
 *
 * Es donde se APLICA el alcance (clase Alcance): sin esto, abrir el
 * CPT Acuerdo al público dejaría todos los acuerdos publicados
 * legibles por cualquiera con sesión.
 *
 * 1. Alcance. En pre_get_posts, toda consulta cuyo post_type sea
 *    exactamente `acuerdo` se limita (post__in) a los acuerdos que el
 *    usuario actual puede ver. Aplica a TODA consulta y no solo a la
 *    principal: un widget, un menú o un shortcode que listen acuerdos
 *    no pueden saltarse la regla. Una consulta sobre un acuerdo fuera
 *    de alcance simplemente no lo encuentra (el detalle da 404, sin
 *    confirmar que existe). Las consultas del propio sistema
 *    (numeración, sincronización, reasignación) se excluyen con la
 *    variable interna Acuerdo::SIN_ALCANCE.
 *
 * 2. Filtros públicos, con prefijo `sca_` para no chocar con variables
 *    de otros temas/plugins. Se traducen a consulta nativa:
 *       sca_estatus   → meta_query (clase Estatus)
 *       sca_comite    → post_parent__in = actas del comité (el comité
 *                       está dos saltos arriba: acuerdo → acta → comité)
 *       sca_asignado  → author (el asignado es el post_author); el
 *                       valor `sin` → author__in = quienes administran
 *                       el módulo (acuerdos sin asignar)
 *    Se usa `sca_asignado` y no el `?author=` nativo porque WordPress
 *    redirige `?author=N` a `/author/<nombre-de-usuario>/`, lo que
 *    además expone nombres de usuario. La búsqueda sí es la nativa (`s`):
 *    el título de cada acuerdo ya trae número, acta, fecha y comité.
 *
 * 3. Guard de acceso (template_redirect): quien no inició sesión va al
 *    login, igual que en Billetera. Además, defensa en profundidad: si
 *    por cualquier ruta (p. ej. `?p=ID`, que no pasa por el post_type
 *    de la consulta) se llegara a un acuerdo fuera de alcance, se
 *    responde 404.
 *
 * Una sola clase porque las tres piezas cambian juntas: son "las
 * reglas de lectura de acuerdos en el front-end".
 */
class Consulta
{
    use Singleton;

    const VAR_ESTATUS  = 'sca_estatus';
    const VAR_COMITE   = 'sca_comite';
    const VAR_ASIGNADO = 'sca_asignado';

    /** Valor de sca_asignado para "acuerdos sin asignar". */
    const SIN_ASIGNAR = 'sin';

    /** @var array<int,int[]> user_id => IDs de acuerdos visibles (caché por petición). */
    private $permitidos = [];

    private function __construct()
    {
        add_filter('query_vars', [$this, 'registrar_variables']);
        add_action('pre_get_posts', [$this, 'filtrar']);
        add_action('template_redirect', [$this, 'guard_access']);

        foreach ([
            'save_post_acuerdo', 'save_post_acta', 'save_post_miembro', 'save_post_puesto', 'save_post_comite',
            'trashed_post', 'untrashed_post', 'deleted_post',
        ] as $hook) {
            add_action($hook, [$this, 'olvidar']);
        }
    }

    public function olvidar()
    {
        $this->permitidos = [];
    }

    public function registrar_variables($vars)
    {
        return array_merge($vars, [self::VAR_ESTATUS, self::VAR_COMITE, self::VAR_ASIGNADO]);
    }

    /**
     * @param \WP_Query $query
     */
    public function filtrar($query)
    {
        if (!$this->es_de_acuerdos($query) || $query->get(Acuerdo::SIN_ALCANCE)) {
            return;
        }

        $this->aplicar_filtros($query);
        $this->aplicar_alcance($query);
    }

    /**
     * Antes de que WordPress resuelva la plantilla.
     */
    public function guard_access()
    {
        $es_acuerdos = is_post_type_archive(Acuerdo::POST_TYPE)
            || is_singular(Acuerdo::POST_TYPE)
            || get_query_var('post_type') === Acuerdo::POST_TYPE;

        if (!$es_acuerdos) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        if (is_singular(Acuerdo::POST_TYPE)) {
            $acuerdo = get_queried_object();

            if ($acuerdo && !Alcance::get_instance()->ve(get_current_user_id(), $acuerdo->ID)) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
            }
        }
    }

    /**
     * IDs de los acuerdos publicados que este usuario puede ver, o
     * null si ve todos (sin restricción). Es público porque la barra
     * lateral cuenta sobre este mismo conjunto.
     *
     * @return int[]|null
     */
    public function visibles($user_id)
    {
        $alcance = Alcance::get_instance()->de($user_id);

        if ($alcance['todo']) {
            return null;
        }

        if (!isset($this->permitidos[$user_id])) {
            $this->permitidos[$user_id] = $this->calcular_visibles($user_id, $alcance);
        }

        return $this->permitidos[$user_id];
    }

    private function calcular_visibles($user_id, array $alcance)
    {
        $ids = [];

        if ($alcance['propios']) {
            $ids = $this->acuerdos(['author' => (int) $user_id]);
        }

        if ($alcance['comites']) {
            $actas = get_posts([
                'post_type'            => Acta::POST_TYPE,
                'post_parent__in'      => $alcance['comites'],
                'post_status'          => 'publish',
                'posts_per_page'       => -1,
                'fields'               => 'ids',
                'no_found_rows'        => true,
                'suppress_filters'     => true,
            ]);

            if ($actas) { // post_parent__in vacío se ignoraría y devolvería TODO.
                $ids = array_merge($ids, $this->acuerdos(['post_parent__in' => $actas]));
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function acuerdos(array $criterio)
    {
        return get_posts(array_merge([
            'post_type'          => Acuerdo::POST_TYPE,
            'post_status'        => 'publish',
            'posts_per_page'     => -1,
            'fields'             => 'ids',
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
        ], $criterio));
    }

    private function aplicar_filtros($query)
    {
        $estatus = $query->get(self::VAR_ESTATUS);
        if (Estatus::valido($estatus)) {
            $existente  = $query->get('meta_query');
            $condicion  = Estatus::meta_query($estatus);
            $query->set('meta_query', $existente ? ['relation' => 'AND', $existente, $condicion] : $condicion);
        }

        $comite_id = absint($query->get(self::VAR_COMITE));
        if ($comite_id) {
            $actas = Consecutivo::actas_de($comite_id);

            if ($actas) {
                $query->set('post_parent__in', $actas);
            } else {
                $this->sin_resultados($query);
            }
        }

        $asignado = $query->get(self::VAR_ASIGNADO);
        if ($asignado === self::SIN_ASIGNAR) {
            $query->set('author__in', Alcance::get_instance()->administradores() ?: [0]);
        } elseif (absint($asignado)) {
            $query->set('author', absint($asignado));
        }
    }

    private function aplicar_alcance($query)
    {
        $ids = $this->visibles(get_current_user_id());

        if ($ids === null) {
            return;
        }

        $pedidos = $query->get('post__in');
        if ($pedidos) {
            $ids = array_values(array_intersect($ids, array_map('intval', $pedidos)));
        }

        // post__in vacío se ignora: [0] es "ningún post".
        $query->set('post__in', $ids ?: [0]);
    }

    private function sin_resultados($query)
    {
        $query->set('post__in', [0]);
    }

    private function es_de_acuerdos($query)
    {
        $post_type = $query->get('post_type');

        if (is_array($post_type)) {
            return $post_type === [Acuerdo::POST_TYPE];
        }

        return $post_type === Acuerdo::POST_TYPE;
    }
}
