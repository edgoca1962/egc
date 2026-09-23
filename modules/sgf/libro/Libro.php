<?php

namespace EGC\Modules\Sgf\Libro;

use EGC\Core\Singleton;
use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Categoria;
use WP_Post;

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
 * Esta clase declara el recurso (el CPT y sus cuatro postmeta) y las
 * reglas que tienen que cumplirse SIEMPRE, sea cual sea la puerta por
 * la que se guarde un movimiento — el meta box de wp-admin de acá
 * abajo, o el formulario propio de `LibroManagement` (paso siguiente):
 * forzar `post_parent`/`post_author` al dueño de la billetera
 * (`forzar_billetera_y_autor()`) y recalcular el saldo de esa
 * billetera (`recalcular_saldo_billetera()`), ambas colgadas de hooks
 * nativos de WordPress que disparan sin importar el llamador. Lo que
 * SÍ es específico del formulario front-end — sus guards de acceso,
 * su `view_state()`, sus handlers de `admin-post.php` — va en
 * `LibroManagement`, misma separación de razones de cambio que ya
 * existe entre `Billetera` y `BilleteraManagement`.
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
        add_action('save_post_' . self::POST_TYPE, [$this, 'recalcular_saldo_billetera']);
        add_action('trashed_post', [$this, 'recalcular_saldo_billetera']);
        add_action('untrashed_post', [$this, 'recalcular_saldo_billetera']);
        add_action('before_delete_post', [$this, 'recalcular_saldo_billetera']);
        add_filter('wp_insert_post_data', [$this, 'forzar_billetera_y_autor'], 10, 2);
    }

    /**
     * `public => false` a propósito (corregido en este paso — antes
     * decía `true` con `has_archive => true`, copiado por analogía de
     * Billetera sin que correspondiera): un movimiento nunca se ve por
     * fuera del detalle de su billetera, así que no necesita URL propia
     * de WordPress. Con `public => false` WordPress ni siquiera genera
     * esas rutas, así que no hace falta ningún guard de single/archive
     * acá ni en LibroManagement. `show_ui => true` explícito para que
     * el superusuario lo siga viendo en wp-admin (con `public => true`
     * eso venía gratis; al apagarlo hay que pedirlo aparte), mismo
     * criterio que ya tiene Billetera con `public => true`.
     */
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
            'public'          => false,
            'show_ui'         => true,
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

    /**
     * Las categorías válidas son las del DUEÑO de la billetera elegida
     * (mismo criterio que Categoria::arbol_de() ya documenta para
     * LibroManagement), no las de quien está mirando wp-admin — acá
     * siempre es el superusuario, que no tiene categorías propias de
     * Libro. Por eso hace falta resolver primero la billetera
     * (`post_parent`) antes de poder armar el `<select>` de categoría.
     *
     * CRUD Y SEGURIDAD pide server-side sin JS: no hay forma de
     * recalcular ESTE `<select>` en el momento en que el superusuario
     * cambia el de Billetera sin JavaScript. Por eso, para un
     * movimiento nuevo (sin `post_parent` todavía) el `<select>` de
     * categoría no tiene de dónde salir — la vista muestra un aviso en
     * vez de un combo vacío, y el flujo queda en dos guardados: primero
     * elegir la billetera (con lo que WordPress ya fija el
     * `post_parent` vía forzar_billetera_y_autor()), volver a abrir el
     * mismo movimiento, y ahí sí aparece el combo con las categorías
     * del dueño de esa billetera. Mismo espíritu que ya tiene el propio
     * `<select>` de Billetera: nada dinámico, todo resuelto en el
     * servidor antes de pintar.
     */
    public function render_meta_box($post)
    {
        $billetera_id = (int) $post->post_parent;
        $billetera    = $billetera_id ? get_post($billetera_id) : null;
        $dueño_id     = ($billetera instanceof WP_Post && $billetera->post_type === Billetera::POST_TYPE)
            ? (int) $billetera->post_author
            : 0;

        $terminos     = $post->ID ? get_the_terms($post->ID, Categoria::TAXONOMY) : [];
        $categoria_id = (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;

        $estado = [
            'monto'              => (float) get_post_meta($post->ID, '_monto', true),
            'referencia'         => (string) get_post_meta($post->ID, '_referencia', true),
            'billetera_id'       => $billetera_id,
            'billetera_opciones' => $this->billetera_opciones(),
            'categoria_id'       => $categoria_id,
            'categoria_opciones' => $dueño_id ? Categoria::get_instance()->arbol_de($dueño_id) : [],
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

        $this->guardar_categoria($post_id);
    }

    /**
     * Asigna la categoría elegida en el meta box — misma validación y
     * mismo método (Categoria::pertenece_a()) que ya usa
     * LibroManagement::handle_save() para el formulario propio: tiene
     * que ser una categoría del DUEÑO de la billetera, no de quien
     * guarda (acá, el superusuario). Si no hay billetera válida
     * todavía, o la categoría no es de su dueño, o directamente no se
     * eligió ninguna, el movimiento queda sin categoría — "sin
     * categoría" es una elección válida, no se deja lo que hubiera
     * antes.
     */
    private function guardar_categoria($post_id)
    {
        $billetera_id = isset($_POST['billetera_id']) ? absint($_POST['billetera_id']) : 0;
        $billetera    = $billetera_id ? get_post($billetera_id) : null;

        if (!$billetera instanceof WP_Post || $billetera->post_type !== Billetera::POST_TYPE) {
            wp_set_object_terms($post_id, [], Categoria::TAXONOMY, false);
            return;
        }

        $categoria_id = isset($_POST['categoria_id']) ? absint($_POST['categoria_id']) : 0;
        $dueño_id     = (int) $billetera->post_author;

        if ($categoria_id && !Categoria::get_instance()->pertenece_a($categoria_id, $dueño_id)) {
            $categoria_id = 0;
        }

        wp_set_object_terms($post_id, $categoria_id ? [$categoria_id] : [], Categoria::TAXONOMY, false);
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

    /**
     * Recalcula el saldo de la billetera padre cada vez que un
     * movimiento se guarda, se manda a la papelera, se restaura, o se
     * elimina en forma permanente — sea cual sea la puerta por la que
     * pasó (el meta box de acá arriba, o
     * LibroManagement::handle_save()/handle_trash(), paso siguiente):
     * son hooks nativos de WordPress que disparan siempre, así que la
     * regla queda en un solo lugar en vez de repetida en cada
     * llamador — ver el docblock de la clase.
     *
     * `trashed_post`/`untrashed_post`/`before_delete_post` disparan
     * para CUALQUIER post_type (a diferencia de `save_post_libro`, que
     * ya viene filtrado por el nombre del hook), por eso el primer
     * chequeo descarta todo lo que no sea un movimiento.
     *
     * Orden de registro importa: en el constructor, `guardar_meta_box`
     * se engancha a `save_post_libro` ANTES que este método — con la
     * misma prioridad, WordPress ejecuta los hooks en el orden en que
     * se agregaron, así que `_monto` ya quedó escrito por
     * `guardar_meta_box()` (o por el `meta_input` de
     * `LibroManagement::handle_save()`, que WordPress procesa antes de
     * disparar `save_post`) para cuando este método lo lee.
     */
    public function recalcular_saldo_billetera($post_id)
    {
        if (get_post_type($post_id) !== self::POST_TYPE) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || !$post->post_parent) {
            return;
        }

        $this->guardar_saldo($post->post_parent, $this->calcular_saldo($post->post_parent));
    }

    /**
     * Suma `_monto` de todos los movimientos PUBLICADOS de esta
     * billetera — recalcula desde cero en vez de sumar/restar
     * incrementalmente sobre el `_saldo` existente, así un movimiento
     * editado (el monto cambia) o eliminado nunca deja un residuo mal
     * sumado. Con la cantidad de movimientos esperable acá, recorrerlos
     * todos en cada guardado es insignificante.
     */
    private function calcular_saldo($billetera_id)
    {
        $movimientos = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_parent'    => $billetera_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        $saldo = 0.0;
        foreach ($movimientos as $movimiento_id) {
            $saldo += (float) get_post_meta($movimiento_id, '_monto', true);
        }

        return round($saldo, 2);
    }

    /**
     * wp_update_post() sobre la billetera dispara a su vez
     * save_post_billetera (Billetera::guardar_meta_box()), pero sin
     * riesgo de bucle ni de pisar nada: ese método exige su propio
     * nonce de meta box (`_egc_billetera_meta_nonce`) antes de tocar
     * cualquier dato, y acá nunca está presente — así que se corta solo
     * en su primera línea. No hace falta remove_action()/add_action()
     * alrededor de este wp_update_post() por ese motivo.
     */
    private function guardar_saldo($billetera_id, $saldo)
    {
        wp_update_post([
            'ID'         => $billetera_id,
            'meta_input' => ['_saldo' => $saldo],
        ]);
    }
}
