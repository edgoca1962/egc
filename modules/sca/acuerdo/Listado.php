<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Administracion;
use EGC\Modules\Sca\Alcance;
use EGC\Modules\Sca\Comite\Comite;
use EGC\Modules\Sca\Fecha;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Puente — prepara lo que pintan las vistas de acuerdos del
 * front-end (módulo SCA): el listado con su barra lateral y el detalle.
 *
 * Las vistas (acuerdo/views/archive.php, single.php y sus partials) no
 * consultan nada ni deciden nada: reciben de acá textos, enlaces,
 * contadores y las acciones ya autorizadas.
 *
 * Qué NO hace: no filtra acuerdos. Qué acuerdos se ven lo decide
 * Consulta (pre_get_posts) y vale para cualquier consulta; acá solo se
 * aprovecha. Por eso la barra lateral cuenta sobre una consulta
 * normal de acuerdos y no repite la regla de alcance: lo que no puede
 * ver el usuario no aparece ni en la lista ni en los contadores.
 *
 * Barra lateral (como la pantalla anterior): búsqueda, "Acuerdos por
 * Estatus", "Acuerdos por Comité" y "Acuerdos asignados a", cada uno
 * con su cantidad. Se aplica UN filtro a la vez; elegir otro reemplaza
 * al anterior (y quita la búsqueda). Los contadores son los del alcance
 * del usuario, no dependen del filtro activo: sirven para decidir
 * adónde ir, no para resumir lo que se está viendo.
 *
 * Una consulta y agrupación en memoria, en vez de una consulta por
 * contador: el estatus se calcula por acuerdo con la misma clase que
 * usa el filtro (Estatus), así que contador y listado no pueden
 * discrepar. Los comités y asignados con menos de dos entradas no se
 * ofrecen (un filtro de una sola opción no filtra nada).
 *
 * Singleton sin hooks ni estado: se instancia a demanda desde las vistas.
 * Es una clase aparte de Consulta porque cambian por razones distintas
 * (Consulta cambia con las reglas de lectura; esta, con lo que muestra
 * la pantalla).
 */
class Listado
{
    use Singleton;

    /** Color de Bootstrap de cada estatus (badge y borde de la tarjeta). */
    const COLORES = [
        Estatus::VENCIDO    => 'danger',
        Estatus::POR_VENCER => 'warning',
        Estatus::EN_PROCESO => 'primary',
        Estatus::EJECUTADO  => 'success',
    ];

    private function __construct()
    {
    }

    // ------------------------------------------------------------------
    // Archivo (listado)
    // ------------------------------------------------------------------

    /**
     * Estado del listado de acuerdos. Las tarjetas salen de la consulta
     * principal de la página (ya filtrada, paginada y con alcance).
     *
     * @return array
     */
    public function view_state_archive()
    {
        global $wp_query;

        $alcance = Alcance::get_instance()->de(get_current_user_id());
        $base    = $this->url_archivo();
        $filtro  = $this->filtro_activo($base);

        $tarjetas = [];
        foreach ((array) $wp_query->posts as $acuerdo) {
            $tarjetas[] = $this->tarjeta($acuerdo);
        }

        return [
            'archive_url' => $base,
            'sin_acceso'  => !$alcance['todo'] && !$alcance['comites'] && !$alcance['propios'],
            'buscar'      => [
                'action'    => home_url('/'),
                'post_type' => Acuerdo::POST_TYPE,
                'valor'     => (string) get_query_var('s'),
            ],
            'secciones'   => $this->secciones($base, $filtro),
            'filtro'      => $filtro['descripcion'] === '' ? null : [
                'texto'      => $filtro['descripcion'],
                'quitar_url' => $base,
            ],
            'tarjetas'    => $tarjetas,
            'total'       => (int) $wp_query->found_posts,
            'paginacion'  => (string) get_the_posts_pagination(['mid_size' => 1]),
            'admin_url'   => Alcance::get_instance()->administra(get_current_user_id())
                ? Administracion::get_instance()->url('actas')
                : '',
        ];
    }

    private function url_archivo()
    {
        $url = get_post_type_archive_link(Acuerdo::POST_TYPE);

        return $url ?: home_url('/');
    }

    /**
     * Qué filtro (de los públicos de Consulta) o búsqueda hay activo.
     *
     * @return array{estatus:string,comite:int,asignado:string,busqueda:string,descripcion:string}
     */
    private function filtro_activo($base)
    {
        $estatus  = (string) get_query_var(Consulta::VAR_ESTATUS);
        $comite   = absint(get_query_var(Consulta::VAR_COMITE));
        $asignado = (string) get_query_var(Consulta::VAR_ASIGNADO);
        $busqueda = trim((string) get_query_var('s'));

        $estatus = Estatus::valido($estatus) ? $estatus : '';
        if ($asignado !== Consulta::SIN_ASIGNAR) {
            $asignado = absint($asignado) ? (string) absint($asignado) : '';
        }

        $descripcion = '';
        if ($estatus !== '') {
            /* translators: %s: nombre del estatus */
            $descripcion = sprintf(__('Estatus: %s', 'egc'), Estatus::etiqueta($estatus));
        } elseif ($comite) {
            /* translators: %s: nombre del comité */
            $descripcion = sprintf(__('Comité: %s', 'egc'), Comite::nombre($comite));
        } elseif ($asignado === Consulta::SIN_ASIGNAR) {
            $descripcion = __('Acuerdos sin asignar', 'egc');
        } elseif ($asignado !== '') {
            $usuario = get_userdata((int) $asignado);
            /* translators: %s: nombre de la persona */
            $descripcion = sprintf(__('Asignados a: %s', 'egc'), $usuario ? $usuario->display_name : '#' . $asignado);
        } elseif ($busqueda !== '') {
            /* translators: %s: texto buscado */
            $descripcion = sprintf(__('Búsqueda: %s', 'egc'), $busqueda);
        }

        return [
            'estatus'     => $estatus,
            'comite'      => $comite,
            'asignado'    => $asignado,
            'busqueda'    => $busqueda,
            'descripcion' => $descripcion,
        ];
    }

    // ------------------------------------------------------------------
    // Barra lateral
    // ------------------------------------------------------------------

    /**
     * @return array<int,array{titulo:string,items:array}> Cada item:
     *         label, count, url, activo.
     */
    private function secciones($base, array $filtro)
    {
        $acuerdos = $this->acuerdos_visibles();
        $hoy      = Fecha::hoy();

        $por_estatus = array_fill_keys(array_keys(Estatus::todos()), 0);
        $por_comite  = [];
        $por_persona = [];
        $sin_asignar = 0;

        $comite_de_acta = $this->comites_de_actas($acuerdos);
        $alcance        = Alcance::get_instance();

        foreach ($acuerdos as $acuerdo) {
            $por_estatus[Estatus::de($acuerdo->ID, $hoy)]++;

            $comite_id = isset($comite_de_acta[(int) $acuerdo->post_parent]) ? $comite_de_acta[(int) $acuerdo->post_parent] : 0;
            if ($comite_id) {
                $por_comite[$comite_id] = (isset($por_comite[$comite_id]) ? $por_comite[$comite_id] : 0) + 1;
            }

            // Misma definición de "sin asignar" que Asignacion::de().
            $autor = (int) $acuerdo->post_author;
            if ($alcance->administra($autor) || !get_userdata($autor)) {
                $sin_asignar++;
            } else {
                $por_persona[$autor] = (isset($por_persona[$autor]) ? $por_persona[$autor] : 0) + 1;
            }
        }

        $ninguno = $filtro['estatus'] === '' && !$filtro['comite'] && $filtro['asignado'] === '' && $filtro['busqueda'] === '';

        $secciones = [[
            'titulo' => __('Acuerdos por Estatus', 'egc'),
            'items'  => array_merge(
                [[
                    'label'  => __('Todos', 'egc'),
                    'count'  => count($acuerdos),
                    'url'    => $base,
                    'activo' => $ninguno,
                ]],
                $this->items_estatus($base, $filtro, $por_estatus)
            ),
        ]];

        $items_comite = [];
        foreach ($por_comite as $id => $cantidad) {
            $items_comite[] = [
                'label'  => Comite::nombre($id),
                'count'  => $cantidad,
                'url'    => add_query_arg(Consulta::VAR_COMITE, $id, $base),
                'activo' => $filtro['comite'] === $id && $filtro['estatus'] === '',
            ];
        }
        $this->ordenar($items_comite);
        $this->agregar_seccion($secciones, __('Acuerdos por Comité', 'egc'), $items_comite);

        $items_persona = [];
        foreach ($por_persona as $id => $cantidad) {
            $items_persona[] = [
                'label'  => get_userdata($id)->display_name,
                'count'  => $cantidad,
                'url'    => add_query_arg(Consulta::VAR_ASIGNADO, $id, $base),
                'activo' => $filtro['asignado'] === (string) $id && $filtro['estatus'] === '' && !$filtro['comite'],
            ];
        }
        $this->ordenar($items_persona);
        if ($sin_asignar) {
            $items_persona[] = [
                'label'  => __('Sin asignar', 'egc'),
                'count'  => $sin_asignar,
                'url'    => add_query_arg(Consulta::VAR_ASIGNADO, Consulta::SIN_ASIGNAR, $base),
                'activo' => $filtro['asignado'] === Consulta::SIN_ASIGNAR && $filtro['estatus'] === '' && !$filtro['comite'],
            ];
        }
        $this->agregar_seccion($secciones, __('Acuerdos asignados a', 'egc'), $items_persona);

        return $secciones;
    }

    private function items_estatus($base, array $filtro, array $cuentas)
    {
        $items = [];

        foreach (Estatus::todos() as $slug => $etiqueta) {
            $items[] = [
                'label'  => $etiqueta,
                'count'  => $cuentas[$slug],
                'url'    => add_query_arg(Consulta::VAR_ESTATUS, $slug, $base),
                'activo' => $filtro['estatus'] === $slug,
            ];
        }

        return $items;
    }

    private function agregar_seccion(array &$secciones, $titulo, array $items)
    {
        if (count($items) > 1) {
            $secciones[] = ['titulo' => $titulo, 'items' => $items];
        }
    }

    private function ordenar(array &$items)
    {
        usort($items, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });
    }

    /**
     * Acuerdos publicados que el usuario actual puede ver, completos
     * (hace falta autor, acta y metas para agrupar). Consulta nativa:
     * Consulta le aplica el alcance en pre_get_posts.
     *
     * @return \WP_Post[]
     */
    private function acuerdos_visibles()
    {
        return get_posts([
            'post_type'      => Acuerdo::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
    }

    /**
     * Comité de cada acta con una sola consulta (acuerdo → acta →
     * comité: el comité está dos saltos arriba).
     *
     * @param \WP_Post[] $acuerdos
     * @return array<int,int> acta_id => comite_id
     */
    private function comites_de_actas(array $acuerdos)
    {
        $actas = array_values(array_unique(array_map('intval', wp_list_pluck($acuerdos, 'post_parent'))));

        if (!$actas) { // post__in vacío se ignoraría y devolvería TODAS las actas.
            return [];
        }

        $mapa = [];
        foreach (get_posts([
            'post_type'        => Acta::POST_TYPE,
            'post_status'      => 'publish',
            'post__in'         => $actas,
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]) as $acta) {
            $mapa[(int) $acta->ID] = (int) $acta->post_parent;
        }

        return $mapa;
    }

    // ------------------------------------------------------------------
    // Tarjeta y detalle
    // ------------------------------------------------------------------

    /**
     * Todo lo que pinta una tarjeta de acuerdo (y la cabecera del detalle).
     *
     * @param \WP_Post|int $acuerdo
     * @return array
     */
    public function tarjeta($acuerdo)
    {
        $acuerdo    = get_post($acuerdo);
        $id         = (int) $acuerdo->ID;
        $hoy        = Fecha::hoy();
        $estatus    = Estatus::de($id, $hoy);
        $compromiso = (string) get_post_meta($id, Acuerdo::META_FECHA_COMPROMISO, true);
        $ejecucion  = (string) get_post_meta($id, Acuerdo::META_FECHA_EJECUCION, true);

        return [
            'id'             => $id,
            'titulo'         => $acuerdo->post_title,
            'url'            => get_permalink($id),
            'estatus'        => $estatus,
            'estatus_texto'  => Estatus::etiqueta($estatus),
            'color'          => self::COLORES[$estatus],
            'comite'         => Comite::nombre(Acuerdo::comite_id($id)),
            'reunion'        => Fecha::legible(substr((string) $acuerdo->post_date, 0, 10)),
            'compromiso'     => Fecha::legible($compromiso),
            'plazo'          => $this->plazo($estatus, $compromiso, $ejecucion, $hoy),
            'asignado'       => $this->asignado($id),
            'extracto'       => wp_trim_words(wp_strip_all_tags($acuerdo->post_content), 50),
            'acciones'       => $this->acciones($id),
        ];
    }

    /**
     * Estado del detalle de un acuerdo.
     *
     * @return array
     */
    public function view_state_single($acuerdo_id)
    {
        $tarjeta = $this->tarjeta($acuerdo_id);

        // El contenido pasa por el filtro nativo the_content (bloques, párrafos).
        $tarjeta['contenido'] = apply_filters('the_content', get_post($acuerdo_id)->post_content);
        $tarjeta['acciones']  = $this->acciones($acuerdo_id, $this->url_archivo());
        $tarjeta['volver']    = $this->url_archivo();

        return $tarjeta;
    }

    /**
     * Botones editar / eliminar, SOLO si el usuario puede (capacidades
     * nativas de ese post, resueltas en actions_for). Para quien solo
     * lee es null: la vista no pinta nada.
     *
     * @return array|null
     */
    private function acciones($acuerdo_id, $volver_a = '')
    {
        $management = AcuerdoManagement::get_instance();
        $acciones   = $management->actions_for($acuerdo_id);

        if (!$acciones['can_edit'] && !$acciones['can_trash']) {
            return null;
        }

        $acciones['redirect_to'] = $volver_a !== '' ? $volver_a : home_url(add_query_arg(null, null));

        return $acciones;
    }

    /**
     * @return array{texto:string,vencido:bool}
     */
    private function plazo($estatus, $compromiso, $ejecucion, $hoy)
    {
        if ($estatus === Estatus::EJECUTADO) {
            /* translators: %s: fecha de ejecución */
            return ['texto' => sprintf(__('Ejecutado el %s', 'egc'), Fecha::legible($ejecucion)), 'vencido' => false];
        }

        $dias = Fecha::dias($hoy, $compromiso);

        if ($dias < 0) {
            $atraso = abs($dias);
            /* translators: %s: cantidad de días */
            $texto = sprintf(_n('Venció hace %s día', 'Venció hace %s días', $atraso, 'egc'), number_format_i18n($atraso));

            return ['texto' => $texto, 'vencido' => true];
        }

        if ($dias === 0) {
            return ['texto' => __('Vence hoy', 'egc'), 'vencido' => false];
        }

        if ($dias === 1) {
            return ['texto' => __('Vence mañana', 'egc'), 'vencido' => false];
        }

        /* translators: %s: cantidad de días */
        return ['texto' => sprintf(__('Vence en %s días', 'egc'), number_format_i18n($dias)), 'vencido' => false];
    }

    private function asignado($acuerdo_id)
    {
        $asignado = Asignacion::get_instance()->de($acuerdo_id);

        if ($asignado['tipo'] === 'usuario') {
            return $asignado['nombre'];
        }

        if ($asignado['tipo'] === 'pendiente') {
            /* translators: %s: correo de la persona */
            return sprintf(__('%s (pendiente de registro)', 'egc'), $asignado['email']);
        }

        return __('Sin asignar', 'egc');
    }
}
