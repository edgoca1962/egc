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
 * Barra lateral: búsqueda, "Acuerdos por Estatus", "Acuerdos por
 * Comité" y "Acuerdos asignados a", cada uno con su cantidad.
 *  - Estatus: quien administra el módulo ve los de todo su alcance; los
 *    demás ven "Mis acuerdos por estatus" (solo los asignados a ellos).
 *  - Comité y persona: llevan a un SEGUNDO NIVEL (un comité o una
 *    persona elegidos, sin estatus): el listado no muestra acuerdos y
 *    ofrece los estatus de ese comité/persona, dentro del alcance del
 *    usuario. Elegido el estatus, se listan.
 * Los contadores son los del alcance del usuario, no dependen del
 * filtro activo: sirven para decidir adónde ir, no para resumir lo que
 * se está viendo.
 *
 * Pantalla de inicio: entrar a /acuerdos/ sin elegir nada es "Vencidos"
 * (lo decide Consulta y marca la consulta con Consulta::INICIO; acá solo
 * se lee). "Todos" es la ÚLTIMA opción de la sección de estatus. Si no
 * hay vencidos, el listado dice que no cuenta con acuerdos vencidos y
 * ofrece ir a "Todos". El orden de "Todos" (Vencidos, Por vencer este
 * mes, En proceso, Ejecutados) también lo resuelve Consulta.
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

        // Segundo nivel: elegido un comité o una persona SIN estatus, no se
        // listan sus acuerdos; se ofrecen los estatus para elegir.
        $nivel = $filtro['nivel'] ? $this->nivel($base, $filtro) : null;

        $tarjetas = [];
        if (!$nivel) {
            foreach ((array) $wp_query->posts as $acuerdo) {
                $tarjetas[] = $this->tarjeta($acuerdo);
            }
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
            // En la pantalla de inicio no hay filtro que mostrar ni quitar:
            // es el punto de partida, no una selección de la persona.
            'filtro'      => ($filtro['descripcion'] === '' || $filtro['inicio']) ? null : [
                'texto'      => $filtro['descripcion'],
                'quitar_url' => add_query_arg(Consulta::VAR_TODOS, 1, $base),
                'volver_url' => $this->url_nivel($base, $filtro),
            ],
            'inicio'      => $filtro['inicio'],
            'vacio'       => $this->vacio($base, $filtro),
            'nivel'       => $nivel,
            'tarjetas'    => $tarjetas,
            'total'       => $nivel ? 0 : (int) $wp_query->found_posts,
            'paginacion'  => $nivel ? '' : (string) get_the_posts_pagination(['mid_size' => 1]),
            'admin_url'   => Alcance::get_instance()->administra(get_current_user_id())
                ? Administracion::get_instance()->url('comites')
                : '',
            'contexto'    => $this->contexto($filtro['acta']),
        ];
    }

    /**
     * Tercer nivel del mantenimiento comité → acta → acuerdo: cuando el
     * listado está filtrado por UN acta, la pantalla muestra las migas de
     * pan hacia los niveles de arriba (solo a quien administra: esas
     * pantallas son suyas) y el botón de agregar un acuerdo a esa acta
     * (solo a quien tiene la capacidad; el handler revalida).
     *
     * @return array{migas:array,nuevo_url:string,nuevo_etiqueta:string}|null
     */
    private function contexto($acta_id)
    {
        $acta = $acta_id ? get_post($acta_id) : null;

        if (!$acta || $acta->post_type !== Acta::POST_TYPE) {
            return null;
        }

        $migas = [];
        if (Alcance::get_instance()->administra(get_current_user_id())) {
            $admin = Administracion::get_instance();
            $migas = [
                ['label' => __('Comités', 'egc'), 'url' => $admin->url('comites')],
                ['label' => Comite::nombre($acta->post_parent), 'url' => $admin->url_comite($acta->post_parent)],
                ['label' => $acta->post_title, 'url' => ''],
            ];
        }

        $manager = AcuerdoManagement::get_instance();

        return [
            'migas'          => $migas,
            'nuevo_url'      => $manager->can_create()
                ? $manager->con_regreso_aqui($manager->url_editar(['acta_id' => $acta->ID]))
                : '',
            'nuevo_etiqueta' => __('Agregar acuerdo', 'egc'),
        ];
    }

    private function url_archivo()
    {
        $url = get_post_type_archive_link(Acuerdo::POST_TYPE);

        return $url ?: home_url('/');
    }

    /**
     * Qué filtros (de los públicos de Consulta) o búsqueda hay activos.
     * Se pueden combinar (comité + estatus, mios + estatus); la
     * descripción los une con " · ".
     *
     * `nivel` es verdadero cuando se eligió un comité o una persona y
     * todavía NO un estatus: el segundo nivel de la barra lateral.
     *
     * `inicio` es verdadero cuando nadie eligió nada y Consulta puso los
     * vencidos por omisión; `todos`, cuando se pidió "Todos" explícito.
     *
     * Se lee de la consulta principal ($wp_query->get) y no de la URL:
     * lo que cuenta es lo que Consulta terminó aplicando, incluido el
     * estatus por omisión del inicio.
     *
     * @return array{estatus:string,comite:int,acta:int,asignado:string,mios:bool,busqueda:string,descripcion:string,nivel:bool,inicio:bool,todos:bool}
     */
    private function filtro_activo($base)
    {
        global $wp_query;

        $estatus  = (string) $wp_query->get(Consulta::VAR_ESTATUS);
        $comite   = absint($wp_query->get(Consulta::VAR_COMITE));
        $acta     = absint($wp_query->get(Consulta::VAR_ACTA));
        $asignado = (string) $wp_query->get(Consulta::VAR_ASIGNADO);
        $mios     = absint($wp_query->get(Consulta::VAR_MIOS)) > 0;
        $todos    = absint($wp_query->get(Consulta::VAR_TODOS)) > 0;
        $busqueda = trim((string) $wp_query->get('s'));
        $inicio   = (bool) $wp_query->get(Consulta::INICIO);

        $estatus = Estatus::valido($estatus) ? $estatus : '';
        if ($asignado !== Consulta::SIN_ASIGNAR) {
            $asignado = absint($asignado) ? (string) absint($asignado) : '';
        }

        $partes = [];
        if ($mios) {
            $partes[] = __('Mis acuerdos', 'egc');
        }
        if ($acta) {
            /* translators: %s: título del acta o minuta */
            $partes[] = sprintf(__('Acta: %s', 'egc'), get_the_title($acta));
        } elseif ($comite) {
            /* translators: %s: nombre del comité */
            $partes[] = sprintf(__('Comité: %s', 'egc'), Comite::nombre($comite));
        }
        if ($asignado === Consulta::SIN_ASIGNAR) {
            $partes[] = __('Acuerdos sin asignar', 'egc');
        } elseif ($asignado !== '') {
            $usuario = get_userdata((int) $asignado);
            /* translators: %s: nombre de la persona */
            $partes[] = sprintf(__('Asignados a: %s', 'egc'), $usuario ? $usuario->display_name : '#' . $asignado);
        }
        if ($estatus !== '') {
            /* translators: %s: nombre del estatus */
            $partes[] = sprintf(__('Estatus: %s', 'egc'), Estatus::etiqueta($estatus));
        }
        if (!$partes && $busqueda !== '') {
            /* translators: %s: texto buscado */
            $partes[] = sprintf(__('Búsqueda: %s', 'egc'), $busqueda);
        }

        return [
            'estatus'     => $estatus,
            'comite'      => $comite,
            'acta'        => $acta,
            'asignado'    => $asignado,
            'mios'        => $mios,
            'busqueda'    => $busqueda,
            'descripcion' => implode(' · ', $partes),
            'nivel'       => $estatus === '' && !$acta && !$mios && $busqueda === '' && ($comite || $asignado !== ''),
            'inicio'      => $inicio,
            'todos'       => $todos,
        ];
    }

    /**
     * Dirección de "Todos": para quien administra, todo su alcance
     * (`sca_todos`, el modo explícito: sin él se abriría Vencidos); para
     * los demás, todos SUS acuerdos (`sca_mios`).
     */
    private function url_todos($base)
    {
        return Alcance::get_instance()->administra(get_current_user_id())
            ? add_query_arg(Consulta::VAR_TODOS, 1, $base)
            : add_query_arg(Consulta::VAR_MIOS, 1, $base);
    }

    /**
     * Qué decir cuando la lista no tiene acuerdos. Sin vencidos, la
     * buena noticia (y un enlace a "Todos"); en cualquier otro caso, el
     * aviso neutro.
     *
     * @return array{texto:string,tipo:string,todos_url:string}
     */
    private function vacio($base, array $filtro)
    {
        // Una búsqueda sin resultados no dice nada sobre los vencidos.
        if ($filtro['estatus'] === Estatus::VENCIDO && $filtro['busqueda'] === '') {
            return [
                'texto'     => __('No cuenta con Acuerdos Vencidos.', 'egc'),
                'tipo'      => 'success',
                'todos_url' => $this->url_todos($base),
            ];
        }

        return [
            'texto'     => __('No hay acuerdos para mostrar.', 'egc'),
            'tipo'      => 'muted',
            'todos_url' => '',
        ];
    }

    /**
     * Segundo nivel: los estatus de UN comité o de UNA persona, con
     * cuántos acuerdos tiene cada uno dentro del alcance del usuario.
     *
     * @return array{titulo:string,total:int,estatus:array}
     */
    private function nivel($base, array $filtro)
    {
        $hoy     = Fecha::hoy();
        $cuentas = array_fill_keys(array_keys(Estatus::todos()), 0);
        $total   = 0;

        foreach ($this->acuerdos_del_filtro($filtro) as $acuerdo) {
            $cuentas[Estatus::de($acuerdo->ID, $hoy)]++;
            $total++;
        }

        $propio  = $this->url_nivel($base, $filtro);
        $estatus = [];

        foreach (Estatus::todos() as $slug => $etiqueta) {
            $estatus[] = [
                'label' => $etiqueta,
                'count' => $cuentas[$slug],
                'color' => self::COLORES[$slug],
                'url'   => add_query_arg(Consulta::VAR_ESTATUS, $slug, $propio),
            ];
        }

        return ['titulo' => $filtro['descripcion'], 'total' => $total, 'estatus' => $estatus];
    }

    /**
     * URL del segundo nivel del filtro activo (solo el comité o solo la
     * persona), o '' si el filtro no tiene segundo nivel.
     */
    private function url_nivel($base, array $filtro)
    {
        if ($filtro['acta'] || $filtro['mios']) {
            return '';
        }

        if ($filtro['comite']) {
            return add_query_arg(Consulta::VAR_COMITE, $filtro['comite'], $base);
        }

        if ($filtro['asignado'] !== '') {
            return add_query_arg(Consulta::VAR_ASIGNADO, $filtro['asignado'], $base);
        }

        return '';
    }

    /**
     * Los acuerdos del alcance del usuario que corresponden al comité o a
     * la persona del filtro (en memoria, sobre la misma consulta que usa
     * la barra lateral: contador y lista no pueden discrepar).
     *
     * @return \WP_Post[]
     */
    private function acuerdos_del_filtro(array $filtro)
    {
        $acuerdos       = $this->acuerdos_visibles();
        $comite_de_acta = $filtro['comite'] ? $this->comites_de_actas($acuerdos) : [];
        $alcance        = Alcance::get_instance();
        $out            = [];

        foreach ($acuerdos as $acuerdo) {
            if ($filtro['comite'] && ($comite_de_acta[(int) $acuerdo->post_parent] ?? 0) !== $filtro['comite']) {
                continue;
            }

            if ($filtro['asignado'] === Consulta::SIN_ASIGNAR) {
                if (!$this->sin_asignar($acuerdo, $alcance)) {
                    continue;
                }
            } elseif ($filtro['asignado'] !== '' && (int) $acuerdo->post_author !== (int) $filtro['asignado']) {
                continue;
            }

            $out[] = $acuerdo;
        }

        return $out;
    }

    /**
     * Misma definición de "sin asignar" que Asignacion::de(): el autor
     * administra el módulo o ya no existe.
     */
    private function sin_asignar($acuerdo, Alcance $alcance)
    {
        $autor = (int) $acuerdo->post_author;

        return $alcance->administra($autor) || !get_userdata($autor);
    }

    // ------------------------------------------------------------------
    // Barra lateral
    // ------------------------------------------------------------------

    /**
     * @return array<int,array{titulo:string,items:array}> Cada item:
     *         label, count, url, activo.
     *
     * "Acuerdos por Estatus" depende de quién mira. Quien administra el
     * módulo no tiene acuerdos propios (a un administrador no se le
     * asignan), así que ve los estatus de todo su alcance. Los demás ven
     * "Mis acuerdos por estatus": solo los asignados a ellos, para
     * revisar rápido el estado de lo suyo. Comité y persona no cambian:
     * llevan a un segundo nivel con los estatus (ver nivel()).
     */
    private function secciones($base, array $filtro)
    {
        $acuerdos = $this->acuerdos_visibles();
        $hoy      = Fecha::hoy();
        $alcance  = Alcance::get_instance();
        $yo       = get_current_user_id();

        $por_estatus = array_fill_keys(array_keys(Estatus::todos()), 0);
        $mios        = array_fill_keys(array_keys(Estatus::todos()), 0);
        $total_mios  = 0;
        $por_comite  = [];
        $por_persona = [];
        $sin_asignar = 0;

        $comite_de_acta = $this->comites_de_actas($acuerdos);

        foreach ($acuerdos as $acuerdo) {
            $estatus = Estatus::de($acuerdo->ID, $hoy);
            $por_estatus[$estatus]++;

            if ((int) $acuerdo->post_author === $yo) {
                $mios[$estatus]++;
                $total_mios++;
            }

            $comite_id = isset($comite_de_acta[(int) $acuerdo->post_parent]) ? $comite_de_acta[(int) $acuerdo->post_parent] : 0;
            if ($comite_id) {
                $por_comite[$comite_id] = (isset($por_comite[$comite_id]) ? $por_comite[$comite_id] : 0) + 1;
            }

            if ($this->sin_asignar($acuerdo, $alcance)) {
                $sin_asignar++;
            } else {
                $autor               = (int) $acuerdo->post_author;
                $por_persona[$autor] = (isset($por_persona[$autor]) ? $por_persona[$autor] : 0) + 1;
            }
        }

        $sin_otros = !$filtro['comite'] && !$filtro['acta'] && $filtro['asignado'] === '' && $filtro['busqueda'] === '';

        // "Todos" va al final: lo primero que se ofrece es lo que pide acción.
        if ($alcance->administra($yo)) {
            $items = $this->items_estatus($base, $por_estatus, $filtro['estatus'] !== '' && $sin_otros && !$filtro['mios'], $filtro['estatus']);
            $items[] = [
                'label'  => __('Todos', 'egc'),
                'count'  => count($acuerdos),
                'url'    => $this->url_todos($base),
                'activo' => $filtro['todos'] && $filtro['estatus'] === '' && $sin_otros && !$filtro['mios'],
            ];

            $secciones = [['titulo' => __('Acuerdos por Estatus', 'egc'), 'items' => $items]];
        } else {
            $items = $this->items_estatus($this->url_todos($base), $mios, $filtro['mios'], $filtro['estatus']);
            $items[] = [
                'label'  => __('Todos', 'egc'),
                'count'  => $total_mios,
                'url'    => $this->url_todos($base),
                'activo' => $filtro['mios'] && $filtro['estatus'] === '',
            ];

            $secciones = [['titulo' => __('Mis acuerdos por estatus', 'egc'), 'items' => $items]];
        }

        $items_comite = [];
        foreach ($por_comite as $id => $cantidad) {
            $items_comite[] = [
                'label'  => Comite::nombre($id),
                'count'  => $cantidad,
                'url'    => add_query_arg(Consulta::VAR_COMITE, $id, $base),
                'activo' => $filtro['comite'] === $id && !$filtro['mios'],
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
                'activo' => $filtro['asignado'] === (string) $id && !$filtro['comite'],
            ];
        }
        $this->ordenar($items_persona);
        if ($sin_asignar) {
            $items_persona[] = [
                'label'  => __('Sin asignar', 'egc'),
                'count'  => $sin_asignar,
                'url'    => add_query_arg(Consulta::VAR_ASIGNADO, Consulta::SIN_ASIGNAR, $base),
                'activo' => $filtro['asignado'] === Consulta::SIN_ASIGNAR && !$filtro['comite'],
            ];
        }
        $this->agregar_seccion($secciones, __('Acuerdos asignados a', 'egc'), $items_persona);

        return $secciones;
    }

    /**
     * @param string $base   URL a la que se le agrega el estatus.
     * @param array  $cuentas slug => cantidad.
     * @param bool   $en_esta_vista Si el filtro activo es de esta sección (si no, ningún estatus se marca).
     */
    private function items_estatus($base, array $cuentas, $en_esta_vista, $estatus_activo)
    {
        $items = [];

        foreach (Estatus::todos() as $slug => $etiqueta) {
            $items[] = [
                'label'  => $etiqueta,
                'count'  => $cuentas[$slug],
                'url'    => add_query_arg(Consulta::VAR_ESTATUS, $slug, $base),
                'activo' => $en_esta_vista && $estatus_activo === $slug,
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
            'ejecutar'       => $this->ejecutar($id),
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
     * Datos del botón "Marcar ejecutado", o null si el usuario no puede
     * (solo administradores — ver AcuerdoManagement::puede_ejecutar) o
     * el acuerdo ya está ejecutado: la vista no pinta nada.
     *
     * Vuelve a la misma pantalla (con sus filtros y página): marcar un
     * acuerdo no debe sacar al usuario de donde estaba.
     *
     * @return array{id:int,action:string,nonce_name:string,redirect_to:string}|null
     */
    private function ejecutar($acuerdo_id)
    {
        $management = AcuerdoManagement::get_instance();

        if (!$management->puede_ejecutar($acuerdo_id)) {
            return null;
        }

        return [
            'id'          => (int) $acuerdo_id,
            'action'      => AcuerdoManagement::ACTION_EJECUTAR,
            'nonce_name'  => AcuerdoManagement::NONCE_NAME,
            'redirect_to' => home_url(add_query_arg(null, null)),
        ];
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

        return __('Sin asignar', 'egc');
    }
}
