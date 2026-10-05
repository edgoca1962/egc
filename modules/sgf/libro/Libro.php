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
 *
 * También es la regla "un movimiento no sobrevive a su billetera":
 * WordPress no propaga la papelera ni el borrado a los posts hijos
 * (`post_parent`) de un post no jerárquico, así que sin esto, borrar
 * una billetera dejaba sus movimientos publicados — apareciendo en el
 * Tablero y, al vaciarse la papelera, huérfanos con un `post_parent`
 * inexistente. Vive acá y no en `Billetera` porque la dependencia va
 * de Libro hacia Billetera (el movimiento es el hijo), nunca al revés:
 * ver `enviar_movimientos_a_papelera()`, `restaurar_movimientos()` y
 * `eliminar_movimientos()`.
 */
class Libro
{
    use Singleton;

    const POST_TYPE = 'libro';

    /**
     * Marca (valor: ID de la billetera) que se pone en cada movimiento
     * que se fue a la papelera ARRASTRADO por su billetera — es lo que
     * permite que restaurar la billetera devuelva solo esos, y no
     * resucite los que el dueño ya había eliminado a mano antes.
     */
    const META_PAPELERA_CON_BILLETERA = '_egc_papelera_con_billetera';

    /**
     * true mientras una cascada de billetera está recorriendo sus
     * movimientos: recalcular_saldo_billetera() la respeta y no recalcula
     * en cada movimiento (el saldo de una billetera que se está
     * mandando a la papelera o eliminando no le importa a nadie, y al
     * restaurar se recalcula una sola vez al final).
     */
    private $en_cascada = false;

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
        add_action('trashed_post', [$this, 'enviar_movimientos_a_papelera']);
        add_action('untrashed_post', [$this, 'restaurar_movimientos']);
        add_action('before_delete_post', [$this, 'eliminar_movimientos']);
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
     *
     * Este es el punto de entrada que reciben los hooks (siempre con
     * el post_id de un MOVIMIENTO) — resuelve su billetera padre y
     * delega el cálculo en sí a recalcular_saldo_de(), que es el que
     * de verdad conoce la fórmula (ver su docblock). Se mantienen
     * separados porque BilleteraManagement (módulo hermano, ver su
     * docblock) necesita el segundo punto de entrada directo, con el
     * ID de la billetera, sin pasar por ningún movimiento — no tiene
     * sentido pedirle que invente un post_id de Libro para conseguir
     * lo mismo.
     */
    public function recalcular_saldo_billetera($post_id)
    {
        if ($this->en_cascada || get_post_type($post_id) !== self::POST_TYPE) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || !$post->post_parent) {
            return;
        }

        $this->recalcular_saldo_de($post->post_parent);
    }

    /**
     * Cuando una billetera va a la papelera, sus movimientos van con
     * ella. Usa `wp_trash_post()` (no un borrado) para que sean
     * recuperables, igual que la billetera — y cada movimiento queda
     * marcado con META_PAPELERA_CON_BILLETERA para que restaurar la
     * billetera devuelva solo estos (ver restaurar_movimientos()).
     *
     * `trashed_post` dispara para cualquier post_type: el primer
     * chequeo descarta todo lo que no sea una billetera (incluidos los
     * propios movimientos que esta cascada manda a la papelera).
     * Solo recorre los movimientos que todavía no están en la papelera:
     * los que el dueño ya había eliminado antes no se tocan.
     */
    public function enviar_movimientos_a_papelera($post_id)
    {
        if (get_post_type($post_id) !== Billetera::POST_TYPE) {
            return;
        }

        $this->en_cascada = true;

        foreach ($this->movimientos_de($post_id, ['publish', 'pending', 'draft', 'private', 'future']) as $movimiento_id) {
            wp_trash_post($movimiento_id);

            // Con EMPTY_TRASH_DAYS = 0, wp_trash_post() elimina en vez
            // de mandar a la papelera: no hay nada que marcar.
            if (get_post_status($movimiento_id) === 'trash') {
                update_post_meta($movimiento_id, self::META_PAPELERA_CON_BILLETERA, (int) $post_id);
            }
        }

        $this->en_cascada = false;
    }

    /**
     * Inverso de enviar_movimientos_a_papelera(): al restaurar una
     * billetera, vuelven SOLO los movimientos que se fueron con ella
     * (los marcados con su ID), no los que el dueño había eliminado a
     * mano por su cuenta.
     *
     * `wp_untrash_post()` deja por defecto todo post restaurado como
     * borrador (`draft`); el filtro nativo `wp_untrash_post_status`
     * (WordPress 5.6+) permite devolverle el estatus que tenía antes de
     * la papelera, que es lo que necesita un movimiento: solo los
     * `publish` cuentan para el saldo y el Tablero. El filtro se
     * agrega y se quita alrededor de la cascada para no alterar ningún
     * otro restaurado.
     *
     * Como durante la cascada no se recalcula el saldo (ver
     * $en_cascada), se hace una sola vez al final.
     */
    public function restaurar_movimientos($post_id)
    {
        if (get_post_type($post_id) !== Billetera::POST_TYPE) {
            return;
        }

        $conservar_estatus = function ($nuevo_estatus, $movimiento_id, $estatus_previo) {
            return $estatus_previo ? $estatus_previo : $nuevo_estatus;
        };

        add_filter('wp_untrash_post_status', $conservar_estatus, 10, 3);
        $this->en_cascada = true;

        $movimientos_ids = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_parent'    => $post_id,
            'post_status'    => 'trash',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_key'       => self::META_PAPELERA_CON_BILLETERA,
            'meta_value'     => (int) $post_id,
        ]);

        foreach ($movimientos_ids as $movimiento_id) {
            wp_untrash_post($movimiento_id);
            delete_post_meta($movimiento_id, self::META_PAPELERA_CON_BILLETERA);
        }

        $this->en_cascada = false;
        remove_filter('wp_untrash_post_status', $conservar_estatus, 10);

        $this->recalcular_saldo_de($post_id);
    }

    /**
     * Cuando una billetera se elimina en forma permanente (a mano desde
     * wp-admin, o sola: WordPress vacía la papelera a los 30 días),
     * se eliminan también TODOS sus movimientos, estén donde estén —
     * publicados o ya en la papelera. `before_delete_post` dispara
     * mientras la billetera todavía existe, así que es el último
     * momento en que se pueden encontrar por su `post_parent`; después
     * quedarían huérfanos (WordPress solo reasigna hijos en post types
     * jerárquicos, y Billetera no lo es).
     */
    public function eliminar_movimientos($post_id)
    {
        if (get_post_type($post_id) !== Billetera::POST_TYPE) {
            return;
        }

        $this->en_cascada = true;

        foreach ($this->movimientos_de($post_id, ['publish', 'pending', 'draft', 'private', 'future', 'trash']) as $movimiento_id) {
            wp_delete_post($movimiento_id, true);
        }

        $this->en_cascada = false;
    }

    /**
     * IDs de los movimientos de una billetera con alguno de los
     * estatus indicados — compartido por las cascadas de arriba.
     *
     * @param string[] $estatus
     * @return int[]
     */
    private function movimientos_de($billetera_id, $estatus)
    {
        return get_posts([
            'post_type'      => self::POST_TYPE,
            'post_parent'    => $billetera_id,
            'post_status'    => $estatus,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
    }

    /**
     * Punto de entrada público para recalcular el saldo ACTUAL de una
     * billetera puntual, dado directamente su ID — lo necesita
     * BilleteraManagement::handle_save() (y Billetera::guardar_meta_box()
     * en wp-admin) cuando el dueño carga o corrige el SALDO INICIAL de
     * una billetera (ver el docblock de calcular_saldo() sobre la
     * diferencia entre `_saldo_inicial` y `_saldo`): ahí no hay ningún
     * movimiento de por medio que dispare recalcular_saldo_billetera()
     * vía sus hooks, así que hace falta poder pedirlo directo con el ID
     * de la billetera. Es el mismo cálculo que ya disparan esos hooks
     * (ver más arriba), extraído a un método público para tener este
     * segundo punto de entrada sin duplicar la fórmula.
     */
    public function recalcular_saldo_de($billetera_id)
    {
        $this->guardar_saldo($billetera_id, $this->calcular_saldo($billetera_id));
    }

    /**
     * El saldo ACTUAL de una billetera es `_saldo_inicial` (lo que esa
     * billetera ya tenía ANTES de empezar a registrarse en la app — un
     * dato que carga su dueño una sola vez, y que este cálculo nunca
     * pisa: ver Billetera::register_post_meta()) más la suma de
     * `_monto` de todos sus movimientos PUBLICADOS.
     *
     * Antes de este cambio se arrancaba siempre en 0.0, como si toda
     * billetera empezara su historia el día en que se carga el primer
     * movimiento en la app — válido para una billetera nueva, pero
     * incorrecto para una cuenta que ya venía funcionando (Edwin lo
     * reportó): en cuanto se guardaba el primer movimiento, este mismo
     * recálculo pisaba cualquier saldo previo. Sumar `_saldo_inicial`
     * como base resuelve eso sin tocar en absoluto cómo se guarda cada
     * movimiento individual.
     *
     * Se recalcula desde cero (saldo_inicial + TODOS los movimientos)
     * en vez de sumar/restar incrementalmente sobre el `_saldo`
     * existente, así un movimiento editado (el monto cambia) o
     * eliminado nunca deja un residuo mal sumado. Con la cantidad de
     * movimientos esperable acá, recorrerlos todos en cada guardado es
     * insignificante.
     */
    private function calcular_saldo($billetera_id)
    {
        $saldo = (float) get_post_meta($billetera_id, '_saldo_inicial', true);

        $movimientos = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_parent'    => $billetera_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

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
