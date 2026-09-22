<?php

namespace EGC\Modules\Sgf\Libro;

use EGC\Core\Singleton;
use EGC\Modules\Sgf\Billetera\Billetera;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Libro.
 *
 * Libro es el detalle de los movimientos del libro contable de una
 * billetera: cada registro nace con `post_parent` = el ID de esa
 * billetera y `post_date` = la fecha del movimiento — ambos campos
 * nativos de WordPress, ninguno se reinventa como meta propio (una
 * relación "este post pertenece a aquel" y "la fecha de este post" ya
 * están resueltas). `post_title` tampoco es un meta aparte: es la
 * descripción de la transacción, tal cual la carga la persona (ver
 * LibroManagement, paso siguiente).
 *
 * Mismo motivo que Billetera para tener `register_post_type()` propio
 * (en vez de reutilizar `post` como Blog): capability_type propio
 * (`libro`/`libros`) y `map_meta_cap => true`, para que sus
 * capacidades queden aisladas de las de cualquier otro CPT, tal como
 * pide AUTORIZACIÓN.
 *
 * Esta clase solo declara el recurso (el CPT y sus cuatro postmeta).
 * El resto — guardar con `post_author` forzado al dueño de la
 * billetera padre, recalcular el saldo, los guards de acceso, los
 * filtros del archive — va en `LibroManagement`, misma separación de
 * razones de cambio que ya existe entre `Billetera` y
 * `BilleteraManagement`.
 */
class Libro
{
    use Singleton;

    const POST_TYPE = 'libro';

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_meta']);
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'guardar_meta_box']);
        add_filter('wp_insert_post_data', [$this, 'forzar_billetera_y_autor'], 10, 2);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Movimientos', 'egc'),
                'singular_name'      => __('Movimiento', 'egc'),
                'add_new_item'       => __('Agregar movimiento', 'egc'),
                'edit_item'          => __('Editar movimiento', 'egc'),
                'new_item'           => __('Nuevo movimiento', 'egc'),
                'view_item'          => __('Ver movimiento', 'egc'),
                'search_items'       => __('Buscar movimientos', 'egc'),
                'not_found'          => __('No se encontraron movimientos', 'egc'),
                'not_found_in_trash' => __('No hay movimientos en la papelera', 'egc'),
            ],
            'public'          => true,
            'has_archive'     => true,
            'rewrite'         => ['slug' => self::POST_TYPE],
            'supports'        => ['title'],
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }

    /**
     * `_monto`: con signo, es el campo que carga la persona — el mismo
     * criterio de "positivo = ingreso, negativo = egreso, salvo
     * reversiones" que ya rige `_saldo` en Billetera.
     *
     * `_debe` y `_haber`: INTERNOS, siempre positivos — nadie los carga
     * directo (salvo la futura carga masiva, que sí los va a recibir
     * como columnas propias de un archivo; no es parte de este paso).
     * Se derivan del signo de `_monto` al guardar: monto positivo ->
     * haber = monto, debe = 0; monto negativo -> debe = abs(monto),
     * haber = 0 — ver guardar_meta_box() para wp-admin, y
     * LibroManagement::handle_save() (paso siguiente) para el
     * formulario propio. El sanitize_callback aplica `abs()` además de
     * redondear, así que ni siquiera un valor negativo mal formado
     * puede quedar guardado con signo, pase lo que pase por dónde se
     * escriba.
     *
     * `_referencia`: texto libre (glosa/comprobante), nunca la
     * descripción del movimiento — esa es el `post_title`, ver el
     * docblock de la clase.
     *
     * `auth_callback` en los cuatro: mismo criterio que Billetera,
     * `edit_post` sobre el post_id del meta.
     */
    public function register_post_meta()
    {
        $auth_callback = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };

        $campo_monto = [
            'type'              => 'number',
            'single'            => true,
            'default'           => 0,
            'show_in_rest'      => false,
            'sanitize_callback' => function ($meta_value) {
                return round((float) $meta_value, 2);
            },
            'auth_callback' => $auth_callback,
        ];

        $campo_positivo = $campo_monto;
        $campo_positivo['sanitize_callback'] = function ($meta_value) {
            return abs(round((float) $meta_value, 2));
        };

        register_post_meta(self::POST_TYPE, '_debe', $campo_positivo);
        register_post_meta(self::POST_TYPE, '_haber', $campo_positivo);
        register_post_meta(self::POST_TYPE, '_monto', $campo_monto);

        register_post_meta(self::POST_TYPE, '_referencia', [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => $auth_callback,
        ]);
    }

    /**
     * A diferencia de Billetera, acá no alcanza con una meta box: un
     * movimiento nuevo cargado desde wp-admin no tiene forma nativa de
     * decir a qué billetera pertenece (`post_parent`, sin selector
     * propio porque Libro no es jerárquico) ni de heredar el dueño
     * correcto (`post_author`) — sin esto, WordPress le pondría de
     * autor a quien esté logueado guardando (el superusuario) en vez
     * del dueño real de la billetera, rompiendo la regla que ya fijamos
     * para el formulario propio en LibroManagement (paso siguiente).
     *
     * `forzar_billetera_y_autor()` (más abajo) resuelve esas dos cosas
     * ANTES de que el post se escriba, así no hace falta un segundo
     * wp_update_post() encima (que on save_post_libro dispararía este
     * mismo hook de nuevo). Nada de HTML acá: arma el estado y delega
     * el marcado a la vista — ver
     * modules/sgf/libro/views/admin/meta-box.php.
     */
    public function register_meta_box()
    {
        add_meta_box(
            'egc_libro_detalle',
            __('Detalle del movimiento', 'egc'),
            [$this, 'render_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        $estado = [
            'monto'              => (float) get_post_meta($post->ID, '_monto', true),
            'referencia'         => (string) get_post_meta($post->ID, '_referencia', true),
            'billetera_id'       => (int) $post->post_parent,
            'billetera_opciones' => $this->billetera_opciones(),
            'nonce_action'       => 'egc_libro_meta_box',
            'nonce_name'         => '_egc_libro_meta_nonce',
        ];

        include EGC_DIR . '/modules/sgf/libro/views/admin/meta-box.php';
    }

    /**
     * Todas las billeteras existentes, id => "Título (correo del
     * dueño)" — el correo hace falta porque, a diferencia del
     * front-end (donde cada quien ve solo lo suyo), acá el superusuario
     * está eligiendo entre las billeteras de TODOS los usuarios, y dos
     * personas distintas pueden haberle puesto el mismo nombre a la
     * suya.
     *
     * @return array<int,string>
     */
    private function billetera_opciones()
    {
        $billeteras = get_posts([
            'post_type'      => Billetera::POST_TYPE,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $opciones = [];
        foreach ($billeteras as $billetera) {
            $dueño              = get_userdata($billetera->post_author);
            $opciones[$billetera->ID] = $dueño
                ? sprintf('%s (%s)', $billetera->post_title, $dueño->user_email)
                : $billetera->post_title;
        }

        return $opciones;
    }

    /**
     * Guarda _monto/_referencia y deriva _debe/_haber de su signo —
     * ver el docblock de register_post_meta() sobre por qué la
     * dirección es esta y no al revés. update_post_meta() ya aplica el
     * sanitize_callback declarado ahí (abs() + redondeo para _debe y
     * _haber), así que guardar 0 en el que no corresponde siempre
     * llega limpio.
     */
    public function guardar_meta_box($post_id)
    {
        if (
            !isset($_POST['_egc_libro_meta_nonce'])
            || !wp_verify_nonce($_POST['_egc_libro_meta_nonce'], 'egc_libro_meta_box')
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $monto = isset($_POST['monto'])
            ? round((float) str_replace(',', '.', wp_unslash($_POST['monto'])), 2)
            : 0.0;

        update_post_meta($post_id, '_monto', $monto);
        update_post_meta($post_id, '_haber', $monto > 0 ? $monto : 0);
        update_post_meta($post_id, '_debe', $monto < 0 ? abs($monto) : 0);

        if (isset($_POST['referencia'])) {
            update_post_meta($post_id, '_referencia', sanitize_text_field(wp_unslash($_POST['referencia'])));
        }
    }

    /**
     * Fuerza `post_parent` (la billetera elegida) y `post_author` (el
     * dueño de esa billetera) ANTES de que WordPress escriba el post —
     * por eso es un filtro sobre `wp_insert_post_data`, no otro
     * `wp_update_post()` colgado de `save_post_libro` (eso dispararía
     * este mismo hook de nuevo, y encima duplicaría en un segundo
     * UPDATE algo que ya puede resolverse en el primero).
     *
     * Solo actúa cuando el guardado viene de ESTE meta box (nonce
     * propio presente): si en el futuro LibroManagement guarda un
     * movimiento desde su propio formulario, va a traer post_parent y
     * post_author ya armados a mano en su propio $data de
     * wp_insert_post()/wp_update_post(), sin pasar por acá — este
     * filtro no le pisa esos valores porque nunca encuentra su nonce.
     */
    public function forzar_billetera_y_autor($data, $postarr)
    {
        if ($data['post_type'] !== self::POST_TYPE) {
            return $data;
        }

        if (
            !isset($_POST['_egc_libro_meta_nonce'])
            || !wp_verify_nonce($_POST['_egc_libro_meta_nonce'], 'egc_libro_meta_box')
        ) {
            return $data;
        }

        $billetera_id = isset($_POST['billetera_id']) ? absint($_POST['billetera_id']) : 0;

        if ($billetera_id && get_post_type($billetera_id) === Billetera::POST_TYPE) {
            $data['post_parent'] = $billetera_id;
            $data['post_author'] = (int) get_post_field('post_author', $billetera_id);
        }

        return $data;
    }
}
