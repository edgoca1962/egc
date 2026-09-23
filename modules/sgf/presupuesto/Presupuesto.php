<?php

namespace EGC\Modules\Sgf\Presupuesto;

use EGC\Core\Singleton;
use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Categoria;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Presupuesto.
 *
 * Un Presupuesto es el monto MENSUAL presupuestado para una categoría
 * (un término de Categoria::TAXONOMY), en una moneda, para un año —
 * "por moneda, no por billetera" (aclaración explícita de Edwin): si
 * un usuario tiene más de una billetera en la misma moneda, el
 * presupuesto de una categoría es uno solo para las dos juntas, no uno
 * por billetera. El monto ANUAL no se guarda aparte: se calcula
 * multiplicando `_monto` (el mensual) por los meses que correspondan
 * al momento de comparar contra lo real (paso futuro, el Comparativo).
 *
 * `public => false` (mismo motivo que Libro, ver su docblock): ni
 * single ni archive nativos tienen sentido acá. Editar o eliminar un
 * Presupuesto pasa siempre por la página propia de
 * PresupuestoManagement (`?post_id=` sobre `presupuesto-editar`, igual
 * que Billetera/Libro), nunca por `get_permalink()`. Y el "listado" no
 * es un WP_Query paginado genérico: es un reporte agrupado por tipo
 * (Ingresos primero con subtotal, después Egresos, ver el docblock de
 * PresupuestoManagement) armado a mano con su propio get_posts() —
 * mismo motivo por el que LibroManagement::movimientos_de() hace lo
 * mismo en vez de apoyarse en pre_get_posts + archive.php. Con
 * `public => false` WordPress ni siquiera genera esas URLs nativas, así
 * que no queda ninguna puerta sin guardia — mismo cierre de brecha que
 * ya se corrigió en Libro. `show_ui => true` explícito para que el
 * superusuario lo siga viendo en wp-admin.
 *
 * `capability_type` propio (`presupuesto`/`presupuestos`) y
 * `map_meta_cap => true`, mismo motivo que Billetera y Libro:
 * capacidades aisladas de cualquier otro CPT.
 *
 * No es dueño de una taxonomía propia: se asocia a la MISMA taxonomía
 * que ya declara y administra Categoria (`sgf_igt`), vía
 * `register_taxonomy_for_object_type()` en vez de que Categoria.php
 * tenga que agregar `presupuesto` a su propia `register_taxonomy()` —
 * así Categoria sigue sin saber que Presupuesto existe (ARQUITECTURA
 * MODULAR: agregar o quitar este recurso no obliga a tocar el módulo
 * dueño de la taxonomía).
 *
 * `supports => ['title', 'author']`: a diferencia de Libro (que
 * necesitó forzar el autor a mano porque su dueño real es el de la
 * BILLETERA elegida, no de quien guarda), acá el dueño de un
 * Presupuesto es directamente quien lo carga — el mismo caso que ya
 * resuelve gratis el meta box nativo de Autor de WordPress. Con
 * `'author'` en supports, wp-admin ya trae ese `<select>` solo, y de
 * paso lo filtra a los usuarios que tienen la capacidad de este CPT
 * (`edit_presupuestos`) — exactamente lo que hubiera hecho a mano un
 * selector propio, gratis y sin reimplementarlo (PRINCIPIO RECTOR).
 */
class Presupuesto
{
    use Singleton;

    const POST_TYPE = 'presupuesto';

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_meta']);
        // Prioridad 21: Categoria::register_taxonomy() corre en la 20
        // (a su vez después de que Libro::register_post_type() corre
        // en la 10) — así la taxonomía ya existe cuando se intenta
        // asociarla acá.
        add_action('init', [$this, 'asociar_taxonomia'], 21);
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'guardar_meta_box']);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Presupuestos', 'egc'),
                'singular_name'      => __('Presupuesto', 'egc'),
                'add_new_item'       => __('Agregar presupuesto', 'egc'),
                'edit_item'          => __('Editar presupuesto', 'egc'),
                'new_item'           => __('Nuevo presupuesto', 'egc'),
                'view_item'          => __('Ver presupuesto', 'egc'),
                'search_items'       => __('Buscar presupuestos', 'egc'),
                'not_found'          => __('No se encontraron presupuestos', 'egc'),
                'not_found_in_trash' => __('No hay presupuestos en la papelera', 'egc'),
            ],
            'public'          => false,
            'show_ui'         => true,
            'supports'        => ['title', 'author'],
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }

    public function asociar_taxonomia()
    {
        register_taxonomy_for_object_type(Categoria::TAXONOMY, self::POST_TYPE);
    }

    /**
     * `_monto`: el mensual — en la práctica siempre positivo (un
     * presupuesto negativo no tiene sentido), pero esa validación es
     * de negocio, no de sanitización: va en
     * PresupuestoManagement::handle_save() (mismo criterio que ya
     * separa Billetera: register_post_meta() normaliza el tipo de
     * dato, no decide qué valores son válidos).
     *
     * `_moneda`: mismo par de valores que ya usa Billetera — se
     * reutiliza `Billetera::MONEDA_LOCAL`/`MONEDA_EXTRANJERA` tal
     * cual, no se duplica ni se extrae una clase "Moneda" nueva: es la
     * segunda vez que aparece el concepto, pero dentro del mismo
     * módulo SGF, así que alcanza con referenciar la constante.
     *
     * `_año`: entero de 4 dígitos, sin tercer campo de "mes": el
     * presupuesto es siempre el monto mensual de todo el año (ver el
     * docblock de la clase).
     *
     * `auth_callback` en los tres: mismo criterio que el resto del
     * módulo, `edit_post` sobre el post_id del meta.
     */
    public function register_post_meta()
    {
        $auth_callback = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };

        register_post_meta(self::POST_TYPE, '_monto', [
            'type'              => 'number',
            'single'            => true,
            'default'           => 0,
            'show_in_rest'      => false,
            'sanitize_callback' => function ($meta_value) {
                return round((float) $meta_value, 2);
            },
            'auth_callback' => $auth_callback,
        ]);

        register_post_meta(self::POST_TYPE, '_moneda', [
            'type'              => 'integer',
            'single'            => true,
            'default'           => Billetera::MONEDA_LOCAL,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
        ]);

        register_post_meta(self::POST_TYPE, '_año', [
            'type'              => 'integer',
            'single'            => true,
            'default'           => (int) gmdate('Y'),
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
        ]);
    }

    public function register_meta_box()
    {
        add_meta_box(
            'egc_presupuesto_detalle',
            __('Detalle del presupuesto', 'egc'),
            [$this, 'render_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Las categorías válidas son las del DUEÑO del presupuesto — acá
     * `$post->post_author`, ya resuelto por el meta box nativo de
     * Autor de WordPress (ver el docblock de la clase). En un
     * Presupuesto NUEVO, antes del primer guardado, `post_author` cae
     * al usuario logueado (el superusuario) — que no tiene categorías
     * propias de Libro (ver Categoria::tiene_acceso_sgf()) — así que
     * el combo aparece vacío hasta elegir el Autor correcto y guardar
     * una vez; mismo flujo en dos pasos que ya tiene el meta box de
     * Libro, y por el mismo motivo: sin JavaScript no hay forma de
     * recalcular este `<select>` cuando cambia el de Autor sin
     * recargar la página.
     */
    public function render_meta_box($post)
    {
        $dueño_id = (int) $post->post_author;

        $terminos     = $post->ID ? get_the_terms($post->ID, Categoria::TAXONOMY) : [];
        $categoria_id = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

        $estado = [
            'monto'              => (float) get_post_meta($post->ID, '_monto', true),
            'moneda'             => (int) get_post_meta($post->ID, '_moneda', true) ?: Billetera::MONEDA_LOCAL,
            'moneda_opciones'    => [
                Billetera::MONEDA_LOCAL      => __('Moneda Local', 'egc'),
                Billetera::MONEDA_EXTRANJERA => __('Moneda Extranjera', 'egc'),
            ],
            'año'                => (int) get_post_meta($post->ID, '_año', true) ?: (int) gmdate('Y'),
            'categoria_id'       => $categoria_id,
            'categoria_opciones' => $dueño_id ? Categoria::get_instance()->arbol_de($dueño_id) : [],
            'nonce_action'       => 'egc_presupuesto_meta_box',
            'nonce_name'         => '_egc_presupuesto_meta_nonce',
        ];

        include EGC_DIR . '/modules/sgf/presupuesto/views/admin/meta-box.php';
    }

    /**
     * A diferencia de Libro, acá no hace falta un filtro sobre
     * `wp_insert_post_data` para forzar el autor: `post_author` ya lo
     * resuelve el meta box nativo de WordPress con el `<select>` de
     * Autor, antes de que este hook corra — solo queda leer la
     * categoría, validarla contra ESE dueño, y asignarla.
     */
    public function guardar_meta_box($post_id)
    {
        if (
            !isset($_POST['_egc_presupuesto_meta_nonce'])
            || !wp_verify_nonce($_POST['_egc_presupuesto_meta_nonce'], 'egc_presupuesto_meta_box')
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Mismo criterio que la validación de Moneda de acá abajo: un
        // valor inválido no se guarda (se deja el que ya hubiera),
        // nunca se pisa con 0 — un monto <= 0 no tiene sentido para un
        // presupuesto (ver el docblock de register_post_meta()).
        $monto = isset($_POST['monto'])
            ? round((float) str_replace(',', '.', wp_unslash($_POST['monto'])), 2)
            : 0.0;
        if ($monto > 0) {
            update_post_meta($post_id, '_monto', $monto);
        }

        if (isset($_POST['moneda']) && in_array((int) $_POST['moneda'], [Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA], true)) {
            update_post_meta($post_id, '_moneda', wp_unslash($_POST['moneda']));
        }

        if (isset($_POST['anio'])) {
            update_post_meta($post_id, '_año', absint($_POST['anio']));
        }

        $this->guardar_categoria($post_id);
    }

    /**
     * Mismo criterio y mismo método (Categoria::pertenece_a()) que ya
     * usa Libro::guardar_categoria() — acá el dueño a validar es
     * `post_author` (ya escrito en la base para cuando este hook
     * corre, WordPress lo resuelve antes de disparar save_post), no el
     * de una billetera elegida aparte.
     */
    private function guardar_categoria($post_id)
    {
        $categoria_id = isset($_POST['categoria_id']) ? absint($_POST['categoria_id']) : 0;
        $dueño_id     = (int) get_post_field('post_author', $post_id);

        if ($categoria_id && !Categoria::get_instance()->pertenece_a($categoria_id, $dueño_id)) {
            $categoria_id = 0;
        }

        wp_set_object_terms($post_id, $categoria_id ? [$categoria_id] : [], Categoria::TAXONOMY, false);
    }
}
