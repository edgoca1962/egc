<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Fecha;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Acuerdo (módulo SCA).
 *
 * Mapeo a lo nativo de WordPress:
 * - Acta: `post_parent` nativo (y el comité del acuerdo es el padre de
 *   su acta: dos saltos, sin copiarlo a una meta que pudiera quedar
 *   desincronizada).
 * - Fecha de la reunión: `post_date` nativo, heredada del acta (paso 3).
 * - Asignado: `post_author` nativo. Un acuerdo sin asignar no tiene
 *   autor (`post_author` = 0); al asignarlo pasa a ser el asignado, que
 *   puede ser cualquier persona enrolada en el módulo, el administrador
 *   (Editor de SCA) incluido.
 * - Comentarios: `supports => comments`, flujo nativo de
 *   comment_form() hacia wp-comments-post.php, sin handlers propios.
 *
 * Metas:
 * - `_numero`: consecutivo DENTRO del comité (no del acta). Lo asigna el
 *   sistema desde el contador del comité; solo una migración de datos
 *   históricos lo fija a mano.
 * - `_fecha_compromiso`: fecha límite. Un acuerdo está vencido si hoy
 *   es MAYOR que ella (el mismo día todavía no lo está).
 * - `_fecha_ejecucion`: vacía = no ejecutado. La marca solo el
 *   administrador. El estatus (vencido / por vencer este mes / en
 *   proceso / ejecutado) NO se guarda: se calcula a partir de estas
 *   dos fechas, así no hace falta ningún proceso diario que lo
 *   actualice ni puede quedar desfasado.
 *
 * Slug (`post_name`): lo genera el Core (PostSlugs) al crear el
 * registro — `acuerdo_` + 10 caracteres al azar — igual que para
 * cualquier CPT propio. Este módulo no lo toca.
 *
 * VISIBILIDAD: es el ÚNICO recurso del módulo con front-end (listado
 * `/acuerdo/` y detalle `/acuerdo/<slug>/`); comité, acta, miembro y
 * puesto siguen sin él. Se registra `publicly_queryable` — sin eso
 * WordPress descarta el post_type de las URL — pero NO `public`:
 * `exclude_from_search` (la búsqueda general del sitio no mezcla
 * acuerdos; se busca desde el propio listado con `?post_type=acuerdo`).
 * Qué acuerdos ve cada usuario NO lo decide el CPT sino el servicio
 * de alcance, aplicado por la clase Consulta sobre toda consulta.
 *
 * Rewrite: registrar un archive y un slug nuevos obliga a regenerar las
 * reglas de reescritura una vez. En vez de pedirle al administrador
 * que guarde Enlaces permanentes a mano, `actualizar_reglas()` las
 * regenera sola, una única vez por versión de las reglas
 * (opción `egc_sca_reglas`), lo que también cubre el caso de agregar
 * el módulo copiando su carpeta, sin reactivar el tema.
 */
class Acuerdo
{
    use Singleton;

    const POST_TYPE = 'acuerdo';

    const META_NUMERO           = '_numero';
    const META_FECHA_COMPROMISO = '_fecha_compromiso';
    const META_FECHA_EJECUCION  = '_fecha_ejecucion';

    /**
     * Variable de consulta INTERNA (no está en la lista de variables
     * públicas, así que ni una URL ni un formulario pueden ponerla):
     * las consultas que el propio sistema hace sobre acuerdos —
     * numeración, sincronización, reasignación, conteos— la pasan en
     * true para que el filtro de alcance (clase Consulta, paso 4) no
     * las recorte según el usuario que casualmente esté logueado.
     */
    const SIN_ALCANCE = 'egc_sca_sin_alcance';

    /** Subir cuando cambien el slug o el archive del CPT. */
    const VERSION_REGLAS = '1';

    const OPCION_REGLAS = 'egc_sca_reglas';

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_meta']);
        add_action('init', [$this, 'actualizar_reglas'], 20);
    }

    /**
     * Después de register_post_type() (prioridad 10): las reglas se
     * calculan a partir de los CPT ya registrados.
     */
    public function actualizar_reglas()
    {
        if (get_option(self::OPCION_REGLAS) === self::VERSION_REGLAS) {
            return;
        }

        flush_rewrite_rules(false); // false: sin reescribir .htaccess
        update_option(self::OPCION_REGLAS, self::VERSION_REGLAS);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Acuerdos', 'egc'),
                'singular_name'      => __('Acuerdo', 'egc'),
                'add_new_item'       => __('Agregar acuerdo', 'egc'),
                'edit_item'          => __('Editar acuerdo', 'egc'),
                'new_item'           => __('Nuevo acuerdo', 'egc'),
                'view_item'          => __('Ver acuerdo', 'egc'),
                'search_items'       => __('Buscar acuerdos', 'egc'),
                'not_found'          => __('No se encontraron acuerdos', 'egc'),
                'not_found_in_trash' => __('No hay acuerdos en la papelera', 'egc'),
            ],
            'public'              => false,
            'publicly_queryable'  => true,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_nav_menus'   => true,
            'has_archive'         => true,
            'rewrite'             => ['slug' => 'acuerdo', 'with_front' => false],
            'query_var'           => true,
            'supports'        => ['title', 'editor', 'author', 'comments'],
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }

    public function register_post_meta()
    {
        $auth_callback = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };

        register_post_meta(self::POST_TYPE, self::META_NUMERO, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
        ]);

        foreach ([self::META_FECHA_COMPROMISO, self::META_FECHA_EJECUCION] as $meta_key) {
            register_post_meta(self::POST_TYPE, $meta_key, [
                'type'              => 'string',
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => false,
                'sanitize_callback' => [Fecha::class, 'sanitizar'],
                'auth_callback'     => $auth_callback,
            ]);
        }
    }

    /**
     * @return int Acta (post_parent) del acuerdo; 0 si no existe.
     */
    public static function acta_id($acuerdo_id)
    {
        $acuerdo = get_post($acuerdo_id);

        return ($acuerdo && $acuerdo->post_type === self::POST_TYPE) ? (int) $acuerdo->post_parent : 0;
    }

    /**
     * @return int Comité del acuerdo: el padre de su acta (dos saltos
     *             por post_parent, sin copiar el comité a una meta).
     */
    public static function comite_id($acuerdo_id)
    {
        return Acta::comite_id(self::acta_id($acuerdo_id));
    }
}
