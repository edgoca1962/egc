<?php

namespace EGC\Modules\Sgf\Migracion;

use EGC\Core\Singleton;
use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Categoria;
use EGC\Modules\Sgf\Libro\Libro;
use EGC\Modules\Sgf\Presupuesto\Presupuesto;
use WP_Error;
use WP_Query;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — exportar e importar los datos de SGF de uno o de todos
 * los usuarios, para llevarlos a otra instalación sin perder el trabajo
 * de clasificación de los movimientos.
 *
 * Por qué código propio y no Herramientas > Exportar de WordPress
 * (PRINCIPIO RECTOR): el exportador nativo (WXR) sí lleva los posts de
 * los tres CPT, sus metadatos, la jerarquía de `sgf_igt` y qué
 * categoría tiene cada movimiento — pero no lleva usuarios, y cada
 * dato de SGF cuelga de un ID de usuario (`post_author` de billeteras,
 * movimientos y presupuestos; el term meta `_user_id` de cada
 * categoría). Al importar en otra instalación esos IDs cambian y
 * WordPress no corrige el que vive DENTRO de un metadato, así que las
 * categorías quedarían a nombre de un usuario equivocado. Acá el
 * dueño viaja identificado por correo electrónico (único en
 * WordPress), nunca por ID, y el importador lo vuelve a resolver en
 * el destino.
 *
 * Formato (JSON, `version` 1) — cada usuario lleva TODO lo suyo
 * adentro, así que exportar "un usuario" o "todos" solo cambia cuántos
 * entran en la lista `usuarios`:
 *
 *   {
 *     formato, version, generado, sitio_origen,
 *     usuarios: [{
 *       email, login, nombre, roles[],
 *       categorias:   [{id, padre, nombre}],          // padres primero
 *       billeteras:   [{id, titulo, estado, fecha, fecha_gmt,
 *                       moneda, saldo_inicial, saldo,
 *                       movimientos: [{id, titulo, estado, fecha,
 *                                      fecha_gmt, monto, debe, haber,
 *                                      referencia, categoria}]}],
 *       presupuestos: [{id, titulo, estado, fecha, fecha_gmt,
 *                       monto, moneda, anio, categoria}],
 *     }]
 *   }
 *
 * Los `id` de categorías, billeteras, movimientos y presupuestos son
 * los del sitio de ORIGEN y solo sirven dentro del archivo: `padre` y
 * `categoria` apuntan a un `id` de `categorias` de ese mismo usuario
 * (0 = sin padre / sin categorizar), y cada movimiento ya viene
 * anidado en su billetera (la relación nativa `post_parent`). No hay
 * hashes de contraseña ni nada de `wp_users` más allá de
 * email/login/nombre/roles: `roles` es informativo, para que quien
 * importe sepa qué asignar — no se aplica solo.
 *
 * No se exporta lo que está en la papelera: cuando una billetera se
 * elimina, sus movimientos se van con ella (ver Libro::
 * enviar_movimientos_a_papelera()), y llevarlos solo ensuciaría el
 * destino. `saldo` (derivado) viaja únicamente para poder comprobar,
 * después de importar, que el recálculo da lo mismo.
 *
 * IMPORTAR — reglas (todas confirmadas con Edwin salvo las marcadas
 * como derivadas de otra regla ya existente del módulo):
 *
 *  - El usuario se busca por correo en el destino. Si no existe, se
 *    OMITE todo lo suyo y se informa: nunca se crea una cuenta (crear
 *    cuentas es de "Gestión de usuarios", y acá no hay contraseña que
 *    asignar). El rol tampoco se asigna: si el usuario todavía no tiene
 *    un rol de SGF, los datos se cargan igual y quedan a la espera —
 *    Categoria::sembrar_arbol_base() no duplica nada porque no siembra
 *    si el usuario ya tiene términos propios.
 *  - Idempotente: cada billetera, movimiento y presupuesto importado
 *    guarda en el postmeta `_egc_origen` de dónde vino
 *    (`sitio|tipo|id`). Volver a importar el mismo archivo encuentra
 *    esos registros y no los repite — también si el primer intento se
 *    cortó a la mitad: la segunda corrida completa lo que falte. Lo
 *    que está en la papelera cuenta como "ya importado" (si el dueño lo
 *    eliminó a propósito, reimportar no lo resucita).
 *  - Categorías: se reutiliza la que ya existe con el mismo nombre bajo
 *    el mismo padre (sin distinguir mayúsculas) — así el árbol base que
 *    sembró el destino no se duplica — y se crea la que falta con
 *    Categoria::crear_termino(), la misma puerta que usa la pantalla
 *    de categorías (slug `{nombre}_{user_id}` y term meta de dueño).
 *  - Presupuestos: igual regla que PresupuestoManagement — uno solo por
 *    categoría y año para cada usuario. Si el destino ya tiene uno, el
 *    del archivo se omite y se avisa (no se pisa ni se suma).
 *  - Fechas: se respeta la fecha LOCAL del origen (la que ven y filtran
 *    el Tablero y Mantenimiento) y la GMT se recalcula con la zona
 *    horaria del destino, en vez de copiar un GMT que puede no
 *    corresponder.
 *  - Saldo: `wp_insert_post()` de cada movimiento dispararía el
 *    recálculo del saldo de su billetera (Libro::recalcular_saldo_
 *    billetera, enganchado a save_post_libro) — con miles de
 *    movimientos serían miles de recorridos completos. Durante la
 *    importación ese hook se desengancha y el saldo se recalcula UNA
 *    vez por billetera tocada, al final, con Libro::recalcular_saldo_de().
 */
class Migracion
{
    use Singleton;

    const FORMATO = 'egc-sgf-migracion';

    const VERSION = 1;

    /**
     * Todos los estatus que cuentan como "datos vivos" — todos menos
     * la papelera y el auto-draft de WordPress.
     */
    const ESTADOS = ['publish', 'pending', 'draft', 'private', 'future'];

    /**
     * Postmeta donde cada registro importado guarda su procedencia
     * (`sitio|tipo|id`) — es lo que vuelve idempotente a importar().
     */
    const META_ORIGEN = '_egc_origen';

    /**
     * Tope de mensajes de detalle en el resultado: con un archivo muy
     * malo no tiene sentido guardar (ni mostrar) miles de líneas; los
     * contadores igual reflejan todo.
     */
    const MAX_AVISOS = 50;

    private function __construct()
    {
    }

    /**
     * Usuarios que tienen algo de SGF (billeteras, movimientos,
     * presupuestos o categorías), con un resumen de cuánto — es lo que
     * necesita la pantalla para ofrecer el selector "un usuario /
     * todos" sin listar cuentas vacías.
     *
     * @return array<int,array{id:int, email:string, nombre:string, resumen:array{billeteras:int, movimientos:int, presupuestos:int, categorias:int}}>
     */
    public function usuarios_con_datos()
    {
        $filas = [];

        foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC']) as $usuario) {
            $resumen = [
                'billeteras'   => $this->contar_posts(Billetera::POST_TYPE, $usuario->ID),
                'movimientos'  => $this->contar_posts(Libro::POST_TYPE, $usuario->ID),
                'presupuestos' => $this->contar_posts(Presupuesto::POST_TYPE, $usuario->ID),
                'categorias'   => $this->contar_categorias($usuario->ID),
            ];

            if (array_sum($resumen) === 0) {
                continue;
            }

            $filas[] = [
                'id'      => (int) $usuario->ID,
                'email'   => $usuario->user_email,
                'nombre'  => $usuario->display_name,
                'resumen' => $resumen,
            ];
        }

        return $filas;
    }

    /**
     * Arma el contenido a exportar para los usuarios pedidos. Un ID
     * que ya no existe se ignora en vez de romper la exportación.
     *
     * @param int[] $user_ids
     * @return array El documento completo (ver el docblock de la clase).
     */
    public function exportar($user_ids)
    {
        $usuarios = [];

        foreach (array_unique(array_map('absint', (array) $user_ids)) as $user_id) {
            $usuario = $user_id ? get_userdata($user_id) : false;

            if (!$usuario) {
                continue;
            }

            $usuarios[] = [
                'email'        => $usuario->user_email,
                'login'        => $usuario->user_login,
                'nombre'       => $usuario->display_name,
                'roles'        => array_values((array) $usuario->roles),
                'categorias'   => $this->exportar_categorias($user_id),
                'billeteras'   => $this->exportar_billeteras($user_id),
                'presupuestos' => $this->exportar_presupuestos($user_id),
            ];
        }

        return [
            'formato'      => self::FORMATO,
            'version'      => self::VERSION,
            'generado'     => gmdate('c'),
            'sitio_origen' => home_url(),
            'usuarios'     => $usuarios,
        ];
    }

    /**
     * Mismo documento de exportar(), ya serializado — lo que descarga
     * el botón. `wp_json_encode()` devuelve false si algún texto no es
     * UTF-8 válido: se informa en vez de entregar un archivo vacío.
     *
     * @param int[] $user_ids
     * @return string|WP_Error
     */
    public function exportar_json($user_ids)
    {
        $json = wp_json_encode(
            $this->exportar($user_ids),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return new WP_Error('json', __('No se pudo generar el archivo: hay texto con una codificación inválida.', 'egc'));
        }

        return $json;
    }

    /**
     * Categorías propias de $user_id, aplanadas con sus padres SIEMPRE
     * antes que sus hijos (ordenadas por profundidad, después por
     * nombre) — así el importador puede recrearlas en una sola pasada,
     * sin buscar hacia adelante. El slug no viaja: el destino lo
     * recalcula con su propio user_id (ver Categoria::crear_termino()).
     *
     * Misma consulta de dueño que Categoria::arbol_de() (meta
     * `_user_id`), pero acá hace falta el padre de cada término, que
     * arbol_de() no devuelve.
     *
     * @return array<int,array{id:int, padre:int, nombre:string}>
     */
    private function exportar_categorias($user_id)
    {
        $terminos = get_terms([
            'taxonomy'   => Categoria::TAXONOMY,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => Categoria::META_USUARIO,
                    'value'   => $user_id,
                    'compare' => '=',
                ],
            ],
        ]);

        if (is_wp_error($terminos) || empty($terminos)) {
            return [];
        }

        $por_id = [];
        foreach ($terminos as $termino) {
            $por_id[(int) $termino->term_id] = $termino;
        }

        $filas = [];
        foreach ($por_id as $term_id => $termino) {
            // Profundidad dentro del conjunto del usuario: un padre que
            // no está en $por_id (dato inconsistente) corta el recorrido
            // y el término cuenta como raíz.
            $profundidad = 0;
            $cursor      = $termino;
            while ((int) $cursor->parent !== 0 && isset($por_id[(int) $cursor->parent])) {
                $cursor = $por_id[(int) $cursor->parent];
                $profundidad++;
            }

            $filas[] = [
                'id'          => $term_id,
                'padre'       => isset($por_id[(int) $termino->parent]) ? (int) $termino->parent : 0,
                'nombre'      => $termino->name,
                'profundidad' => $profundidad,
            ];
        }

        usort($filas, function ($a, $b) {
            return [$a['profundidad'], $a['nombre']] <=> [$b['profundidad'], $b['nombre']];
        });

        return array_map(function ($fila) {
            unset($fila['profundidad']);

            return $fila;
        }, $filas);
    }

    /**
     * @return array<int,array>
     */
    private function exportar_billeteras($user_id)
    {
        $billeteras = get_posts([
            'post_type'      => Billetera::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => self::ESTADOS,
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $filas = [];
        foreach ($billeteras as $billetera) {
            $filas[] = [
                'id'            => (int) $billetera->ID,
                'titulo'        => $billetera->post_title,
                'estado'        => $billetera->post_status,
                'fecha'         => $billetera->post_date,
                'fecha_gmt'     => $billetera->post_date_gmt,
                'moneda'        => (int) get_post_meta($billetera->ID, '_moneda', true),
                'saldo_inicial' => (float) get_post_meta($billetera->ID, '_saldo_inicial', true),
                'saldo'         => (float) get_post_meta($billetera->ID, '_saldo', true),
                'movimientos'   => $this->exportar_movimientos($billetera->ID),
            ];
        }

        return $filas;
    }

    /**
     * Los movimientos de UNA billetera, buscados por `post_parent` (la
     * relación nativa que ya usa todo el módulo) y no por autor: así
     * viajan con su billetera pase lo que pase con el post_author.
     * `debe`/`haber`/`monto` se copian tal cual están guardados, sin
     * recalcular nada a partir de uno de ellos.
     *
     * @return array<int,array>
     */
    private function exportar_movimientos($billetera_id)
    {
        $movimientos = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_parent'    => $billetera_id,
            'post_status'    => self::ESTADOS,
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $filas = [];
        foreach ($movimientos as $movimiento) {
            $filas[] = [
                'id'         => (int) $movimiento->ID,
                'titulo'     => $movimiento->post_title,
                'estado'     => $movimiento->post_status,
                'fecha'      => $movimiento->post_date,
                'fecha_gmt'  => $movimiento->post_date_gmt,
                'monto'      => (float) get_post_meta($movimiento->ID, '_monto', true),
                'debe'       => (float) get_post_meta($movimiento->ID, '_debe', true),
                'haber'      => (float) get_post_meta($movimiento->ID, '_haber', true),
                'referencia' => (string) get_post_meta($movimiento->ID, '_referencia', true),
                'categoria'  => $this->categoria_de($movimiento->ID),
            ];
        }

        return $filas;
    }

    /**
     * @return array<int,array>
     */
    private function exportar_presupuestos($user_id)
    {
        $presupuestos = get_posts([
            'post_type'      => Presupuesto::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => self::ESTADOS,
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $filas = [];
        foreach ($presupuestos as $presupuesto) {
            $filas[] = [
                'id'        => (int) $presupuesto->ID,
                'titulo'    => $presupuesto->post_title,
                'estado'    => $presupuesto->post_status,
                'fecha'     => $presupuesto->post_date,
                'fecha_gmt' => $presupuesto->post_date_gmt,
                'monto'     => (float) get_post_meta($presupuesto->ID, '_monto', true),
                'moneda'    => (int) get_post_meta($presupuesto->ID, '_moneda', true),
                'anio'      => (int) get_post_meta($presupuesto->ID, '_año', true),
                'categoria' => $this->categoria_de($presupuesto->ID),
            ];
        }

        return $filas;
    }

    /**
     * El `term_id` de origen de la categoría asignada (un movimiento o
     * un presupuesto tiene siempre UN solo término de Categoria, ver
     * Libro::guardar_categoria()), o 0 si no está categorizado.
     */
    private function categoria_de($post_id)
    {
        $terminos = get_the_terms($post_id, Categoria::TAXONOMY);

        return (!empty($terminos) && !is_wp_error($terminos)) ? (int) $terminos[0]->term_id : 0;
    }

    private function contar_posts($post_type, $user_id)
    {
        $consulta = new WP_Query([
            'post_type'      => $post_type,
            'author'         => $user_id,
            'post_status'    => self::ESTADOS,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);

        return (int) $consulta->found_posts;
    }

    private function contar_categorias($user_id)
    {
        $ids = get_terms([
            'taxonomy'   => Categoria::TAXONOMY,
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_query' => [
                [
                    'key'     => Categoria::META_USUARIO,
                    'value'   => $user_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return is_wp_error($ids) ? 0 : count($ids);
    }

    /**
     * Mismo flujo que exportar_json() pero hacia adentro: decodifica el
     * contenido del archivo subido y delega en importar().
     *
     * @param string $contenido
     * @return array|WP_Error
     */
    public function importar_json($contenido)
    {
        $datos = json_decode((string) $contenido, true);

        if (!is_array($datos)) {
            return new WP_Error('json', __('El archivo no es un JSON válido.', 'egc'));
        }

        return $this->importar($datos);
    }

    /**
     * Importa un documento con el formato de exportar().
     *
     * No es transaccional (WordPress no ofrece transacciones sobre sus
     * APIs y el proyecto no escribe SQL directo): si algo falla a la
     * mitad, lo ya importado se queda y, gracias a META_ORIGEN, volver
     * a correr el mismo archivo completa lo que faltó sin duplicar.
     *
     * @param array $datos
     * @return array|WP_Error {
     *     usuarios: array<int,array> una fila por usuario del archivo
     *               (ver fila_vacia()),
     *     avisos:   string[] detalle de lo omitido o rechazado (hasta
     *               MAX_AVISOS), avisos_extra: int lo que no entró en
     *               la lista,
     * }
     */
    public function importar($datos)
    {
        $error = $this->validar($datos);
        if (is_wp_error($error)) {
            return $error;
        }

        // Lote largo: más memoria y sin límite de tiempo para ESTA
        // petición (el handler es admin-post.php, nunca una vista).
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        $ctx = [
            'sitio'        => isset($datos['sitio_origen']) ? (string) $datos['sitio_origen'] : '',
            'indice'       => $this->indice_origen(),
            'avisos'       => [],
            'avisos_extra' => 0,
        ];

        $recalculo = [Libro::get_instance(), 'recalcular_saldo_billetera'];
        $prioridad = has_action('save_post_' . Libro::POST_TYPE, $recalculo);
        $filas     = [];

        if ($prioridad !== false) {
            remove_action('save_post_' . Libro::POST_TYPE, $recalculo, $prioridad);
        }
        wp_defer_term_counting(true);
        // Un movimiento sin descripción es un dato válido del origen;
        // wp_insert_post() lo rechazaría por "título, contenido y
        // extracto vacíos".
        add_filter('wp_insert_post_empty_content', '__return_false');

        try {
            foreach ($datos['usuarios'] as $usuario) {
                $filas[] = $this->importar_usuario($usuario, $ctx);
            }
        } finally {
            remove_filter('wp_insert_post_empty_content', '__return_false');
            wp_defer_term_counting(false);
            if ($prioridad !== false) {
                add_action('save_post_' . Libro::POST_TYPE, $recalculo, $prioridad);
            }
        }

        return [
            'usuarios'     => $filas,
            'avisos'       => $ctx['avisos'],
            'avisos_extra' => $ctx['avisos_extra'],
        ];
    }

    /**
     * @return true|WP_Error
     */
    private function validar($datos)
    {
        if (!is_array($datos) || ($datos['formato'] ?? null) !== self::FORMATO) {
            return new WP_Error('formato', __('El archivo no es una exportación de SGF.', 'egc'));
        }

        if ((int) ($datos['version'] ?? 0) < 1 || (int) $datos['version'] > self::VERSION) {
            return new WP_Error('version', __('El archivo viene de una versión de la exportación que esta instalación no conoce.', 'egc'));
        }

        if (!isset($datos['usuarios']) || !is_array($datos['usuarios'])) {
            return new WP_Error('usuarios', __('El archivo no trae la lista de usuarios.', 'egc'));
        }

        return true;
    }

    /**
     * Contadores de un usuario del archivo — la forma que consume la
     * vista del resultado.
     */
    private function fila_vacia($email, $nombre)
    {
        return [
            'email'        => $email,
            'nombre'       => $nombre,
            'importado'    => false,
            'categorias'   => ['creadas' => 0, 'existentes' => 0, 'fallidas' => 0],
            'billeteras'   => ['creadas' => 0, 'existentes' => 0, 'rechazadas' => 0],
            'movimientos'  => ['creados' => 0, 'existentes' => 0, 'rechazados' => 0, 'sin_categoria' => 0],
            'presupuestos' => ['creados' => 0, 'existentes' => 0, 'omitidos' => 0],
        ];
    }

    private function importar_usuario($datos, array &$ctx)
    {
        $email  = is_array($datos) ? sanitize_email((string) ($datos['email'] ?? '')) : '';
        $nombre = is_array($datos) ? sanitize_text_field((string) ($datos['nombre'] ?? '')) : '';
        $fila   = $this->fila_vacia($email, $nombre);

        if ($email === '') {
            $this->avisar($ctx, __('Se omitió una entrada del archivo: no trae correo electrónico.', 'egc'));

            return $fila;
        }

        $usuario = get_user_by('email', $email);
        if (!$usuario) {
            $this->avisar($ctx, sprintf(
                /* translators: %s: correo del usuario */
                __('%s no existe en esta instalación: se omitieron todos sus datos. Creá la cuenta y volvé a importar el archivo.', 'egc'),
                $email
            ));

            return $fila;
        }

        $fila['importado'] = true;

        // Aviso, no bloqueo: sin capacidad sobre movimientos el usuario
        // no vería nada hasta que se le asigne un rol de SGF.
        $cap_libro = get_post_type_object(Libro::POST_TYPE)->cap->edit_posts;
        if (!user_can($usuario, $cap_libro)) {
            $this->avisar($ctx, sprintf(
                /* translators: %s: correo del usuario */
                __('%s todavía no tiene un rol de SGF: sus datos se cargaron, pero no los va a ver hasta que se le asigne uno.', 'egc'),
                $email
            ));
        }

        $categorias = $this->importar_categorias($usuario->ID, $email, (array) ($datos['categorias'] ?? []), $fila, $ctx);

        $this->importar_billeteras($usuario->ID, $email, (array) ($datos['billeteras'] ?? []), $categorias, $fila, $ctx);
        $this->importar_presupuestos($usuario->ID, $email, (array) ($datos['presupuestos'] ?? []), $categorias, $fila, $ctx);

        return $fila;
    }

    /**
     * Resuelve las categorías del archivo contra las del destino y
     * devuelve el mapa `id de origen => term_id de destino`. Los padres
     * vienen siempre antes que sus hijos (ver exportar_categorias()),
     * así que alcanza una sola pasada; una categoría cuyo padre no pudo
     * resolverse se descarta con ese motivo, en vez de caer a la raíz
     * y romper el árbol.
     *
     * @return array<int,int>
     */
    private function importar_categorias($user_id, $email, array $categorias, array &$fila, array &$ctx)
    {
        $categoria = Categoria::get_instance();

        // Índice de lo que el usuario ya tiene: `padre|nombre`.
        $existentes = [];
        $terminos   = get_terms([
            'taxonomy'   => Categoria::TAXONOMY,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => Categoria::META_USUARIO,
                    'value'   => $user_id,
                    'compare' => '=',
                ],
            ],
        ]);
        if (!is_wp_error($terminos)) {
            foreach ($terminos as $termino) {
                $existentes[(int) $termino->parent . '|' . $this->clave_nombre($termino->name)] = (int) $termino->term_id;
            }
        }

        $mapa = [];
        foreach ($categorias as $fila_origen) {
            $origen_id = isset($fila_origen['id']) ? (int) $fila_origen['id'] : 0;
            $padre_id  = isset($fila_origen['padre']) ? (int) $fila_origen['padre'] : 0;
            $nombre    = isset($fila_origen['nombre']) ? trim(sanitize_text_field((string) $fila_origen['nombre'])) : '';

            if (!$origen_id || $nombre === '') {
                $fila['categorias']['fallidas']++;
                continue;
            }

            if ($padre_id && !isset($mapa[$padre_id])) {
                $fila['categorias']['fallidas']++;
                $this->avisar($ctx, sprintf(
                    /* translators: 1: correo, 2: nombre de la categoría */
                    __('%1$s: la categoría «%2$s» se descartó porque no se pudo resolver su categoría padre.', 'egc'),
                    $email,
                    $nombre
                ));
                continue;
            }

            $padre_destino = $padre_id ? $mapa[$padre_id] : 0;
            $clave         = $padre_destino . '|' . $this->clave_nombre($nombre);

            if (isset($existentes[$clave])) {
                $mapa[$origen_id] = $existentes[$clave];
                $fila['categorias']['existentes']++;
                continue;
            }

            $term_id = $categoria->crear_termino($nombre, $padre_destino, $user_id);

            // Un slug ya tomado (`term_exists`) devuelve en `data` el
            // término que lo ocupa: si es de este usuario, es la misma
            // categoría y se reutiliza.
            if (is_wp_error($term_id) && $term_id->get_error_code() === 'term_exists') {
                $ocupante = (int) $term_id->get_error_data('term_exists');
                if ($ocupante && $categoria->pertenece_a($ocupante, $user_id)) {
                    $mapa[$origen_id]     = $ocupante;
                    $existentes[$clave]   = $ocupante;
                    $fila['categorias']['existentes']++;
                    continue;
                }
            }

            if (is_wp_error($term_id)) {
                $fila['categorias']['fallidas']++;
                $this->avisar($ctx, sprintf(
                    /* translators: 1: correo, 2: nombre de la categoría, 3: motivo */
                    __('%1$s: no se pudo crear la categoría «%2$s» (%3$s).', 'egc'),
                    $email,
                    $nombre,
                    $term_id->get_error_message()
                ));
                continue;
            }

            $mapa[$origen_id]   = $term_id;
            $existentes[$clave] = $term_id;
            $fila['categorias']['creadas']++;
        }

        return $mapa;
    }

    private function importar_billeteras($user_id, $email, array $billeteras, array $categorias, array &$fila, array &$ctx)
    {
        foreach ($billeteras as $origen) {
            $origen_id = isset($origen['id']) ? (int) $origen['id'] : 0;
            $titulo    = isset($origen['titulo']) ? sanitize_text_field((string) $origen['titulo']) : '';
            $moneda    = isset($origen['moneda']) ? (int) $origen['moneda'] : 0;
            $clave     = $this->clave_origen($ctx, Billetera::POST_TYPE, $origen_id);
            $nueva     = false;

            if (isset($ctx['indice'][$clave])) {
                $billetera_id = $ctx['indice'][$clave];
                $fila['billeteras']['existentes']++;
            } else {
                $fecha = $this->fecha_de($origen);

                if (!$origen_id || $fecha === null || !in_array($moneda, [Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA], true)) {
                    $fila['billeteras']['rechazadas']++;
                    $this->avisar($ctx, sprintf(
                        /* translators: 1: correo, 2: nombre de la billetera */
                        __('%1$s: la billetera «%2$s» se rechazó (falta el ID, la fecha o la moneda es inválida) y con ella sus movimientos.', 'egc'),
                        $email,
                        $titulo
                    ));
                    continue;
                }

                $billetera_id = $this->insertar([
                    'post_type'     => Billetera::POST_TYPE,
                    'post_title'    => $titulo,
                    'post_author'   => $user_id,
                    'post_status'   => $this->estado_de($origen),
                    'post_date'     => $fecha,
                    'post_date_gmt' => get_gmt_from_date($fecha),
                    'meta_input'    => [
                        '_moneda'         => $moneda,
                        '_saldo_inicial'  => round((float) ($origen['saldo_inicial'] ?? 0), 2),
                        // Provisorio: se recalcula al terminar sus movimientos.
                        '_saldo'          => round((float) ($origen['saldo_inicial'] ?? 0), 2),
                        self::META_ORIGEN => $clave,
                    ],
                ]);

                if (is_wp_error($billetera_id)) {
                    $fila['billeteras']['rechazadas']++;
                    $this->avisar($ctx, sprintf(
                        /* translators: 1: correo, 2: nombre de la billetera, 3: motivo */
                        __('%1$s: no se pudo crear la billetera «%2$s» (%3$s).', 'egc'),
                        $email,
                        $titulo,
                        $billetera_id->get_error_message()
                    ));
                    continue;
                }

                $ctx['indice'][$clave] = $billetera_id;
                $fila['billeteras']['creadas']++;
                $nueva = true;
            }

            $nuevos = $this->importar_movimientos($user_id, $email, $billetera_id, (array) ($origen['movimientos'] ?? []), $categorias, $fila, $ctx);

            if (!$nueva && $nuevos === 0) {
                continue;
            }

            // Un solo recálculo por billetera tocada (ver el docblock
            // de la clase).
            Libro::get_instance()->recalcular_saldo_de($billetera_id);

            if ($nueva && isset($origen['saldo'])) {
                $saldo = (float) get_post_meta($billetera_id, '_saldo', true);

                if (abs($saldo - (float) $origen['saldo']) > 0.005) {
                    $this->avisar($ctx, sprintf(
                        /* translators: 1: correo, 2: nombre de la billetera, 3: saldo recalculado, 4: saldo del archivo */
                        __('%1$s: el saldo de «%2$s» recalculado (%3$s) no coincide con el del archivo (%4$s). Conviene revisarla.', 'egc'),
                        $email,
                        $titulo,
                        number_format_i18n($saldo, 2),
                        number_format_i18n((float) $origen['saldo'], 2)
                    ));
                }
            }
        }
    }

    /**
     * @return int Cuántos movimientos NUEVOS se crearon (para saber si
     *             hay que recalcular el saldo de una billetera ya
     *             existente).
     */
    private function importar_movimientos($user_id, $email, $billetera_id, array $movimientos, array $categorias, array &$fila, array &$ctx)
    {
        $creados = 0;

        foreach ($movimientos as $origen) {
            $origen_id = isset($origen['id']) ? (int) $origen['id'] : 0;
            $clave     = $this->clave_origen($ctx, Libro::POST_TYPE, $origen_id);

            if (isset($ctx['indice'][$clave])) {
                $fila['movimientos']['existentes']++;
                continue;
            }

            $fecha = $this->fecha_de($origen);
            if (!$origen_id || $fecha === null) {
                $fila['movimientos']['rechazados']++;
                $this->avisar($ctx, sprintf(
                    /* translators: 1: correo, 2: descripción del movimiento */
                    __('%1$s: un movimiento («%2$s») se rechazó por falta de ID o de fecha válida.', 'egc'),
                    $email,
                    isset($origen['titulo']) ? sanitize_text_field((string) $origen['titulo']) : ''
                ));
                continue;
            }

            $monto = round((float) ($origen['monto'] ?? 0), 2);

            $movimiento_id = $this->insertar([
                'post_type'     => Libro::POST_TYPE,
                'post_parent'   => $billetera_id,
                // Siempre el dueño de la billetera, igual que
                // LibroManagement::handle_save().
                'post_author'   => $user_id,
                'post_title'    => isset($origen['titulo']) ? sanitize_text_field((string) $origen['titulo']) : '',
                'post_status'   => $this->estado_de($origen),
                'post_date'     => $fecha,
                'post_date_gmt' => get_gmt_from_date($fecha),
                'meta_input'    => [
                    '_monto'          => $monto,
                    '_haber'          => isset($origen['haber']) ? abs(round((float) $origen['haber'], 2)) : ($monto > 0 ? $monto : 0),
                    '_debe'           => isset($origen['debe']) ? abs(round((float) $origen['debe'], 2)) : ($monto < 0 ? abs($monto) : 0),
                    '_referencia'     => isset($origen['referencia']) ? sanitize_text_field((string) $origen['referencia']) : '',
                    self::META_ORIGEN => $clave,
                ],
            ]);

            if (is_wp_error($movimiento_id)) {
                $fila['movimientos']['rechazados']++;
                $this->avisar($ctx, sprintf(
                    /* translators: 1: correo, 2: motivo */
                    __('%1$s: un movimiento no se pudo crear (%2$s).', 'egc'),
                    $email,
                    $movimiento_id->get_error_message()
                ));
                continue;
            }

            $ctx['indice'][$clave] = $movimiento_id;
            $creados++;
            $fila['movimientos']['creados']++;

            $categoria_origen = isset($origen['categoria']) ? (int) $origen['categoria'] : 0;
            if ($categoria_origen && isset($categorias[$categoria_origen])) {
                wp_set_object_terms($movimiento_id, [$categorias[$categoria_origen]], Categoria::TAXONOMY, false);
            } elseif ($categoria_origen) {
                $fila['movimientos']['sin_categoria']++;
            }
        }

        return $creados;
    }

    private function importar_presupuestos($user_id, $email, array $presupuestos, array $categorias, array &$fila, array &$ctx)
    {
        // Lo que el usuario ya tiene en el destino: `categoría|año`
        // (la misma unicidad que PresupuestoManagement).
        $ocupados = [];
        foreach (get_posts([
            'post_type'      => Presupuesto::POST_TYPE,
            'author'         => $user_id,
            'post_status'    => self::ESTADOS,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]) as $existente) {
            $ocupados[$this->categoria_de($existente->ID) . '|' . (int) get_post_meta($existente->ID, '_año', true)] = true;
        }

        foreach ($presupuestos as $origen) {
            $origen_id = isset($origen['id']) ? (int) $origen['id'] : 0;
            $clave     = $this->clave_origen($ctx, Presupuesto::POST_TYPE, $origen_id);

            if (isset($ctx['indice'][$clave])) {
                $fila['presupuestos']['existentes']++;
                continue;
            }

            $fecha  = $this->fecha_de($origen);
            $moneda = isset($origen['moneda']) ? (int) $origen['moneda'] : 0;
            $anio   = isset($origen['anio']) ? (int) $origen['anio'] : 0;
            $monto  = round((float) ($origen['monto'] ?? 0), 2);

            if (!$origen_id || $fecha === null || $anio < 1 || $monto <= 0 || !in_array($moneda, [Billetera::MONEDA_LOCAL, Billetera::MONEDA_EXTRANJERA], true)) {
                $fila['presupuestos']['omitidos']++;
                $this->avisar($ctx, sprintf(
                    /* translators: 1: correo, 2: título del presupuesto */
                    __('%1$s: el presupuesto «%2$s» se omitió por datos inválidos (ID, fecha, año, monto o moneda).', 'egc'),
                    $email,
                    isset($origen['titulo']) ? sanitize_text_field((string) $origen['titulo']) : ''
                ));
                continue;
            }

            $categoria_origen  = isset($origen['categoria']) ? (int) $origen['categoria'] : 0;
            $categoria_destino = $categoria_origen && isset($categorias[$categoria_origen]) ? $categorias[$categoria_origen] : 0;
            $ocupado           = $categoria_destino . '|' . $anio;

            if (isset($ocupados[$ocupado])) {
                $fila['presupuestos']['omitidos']++;
                $this->avisar($ctx, sprintf(
                    /* translators: 1: correo, 2: título del presupuesto, 3: año */
                    __('%1$s: el presupuesto «%2$s» (%3$d) se omitió porque ya hay uno para esa categoría y año.', 'egc'),
                    $email,
                    isset($origen['titulo']) ? sanitize_text_field((string) $origen['titulo']) : '',
                    $anio
                ));
                continue;
            }

            $presupuesto_id = $this->insertar([
                'post_type'     => Presupuesto::POST_TYPE,
                'post_title'    => isset($origen['titulo']) ? sanitize_text_field((string) $origen['titulo']) : '',
                'post_author'   => $user_id,
                'post_status'   => $this->estado_de($origen),
                'post_date'     => $fecha,
                'post_date_gmt' => get_gmt_from_date($fecha),
                'meta_input'    => [
                    '_monto'          => $monto,
                    '_moneda'         => $moneda,
                    '_año'            => $anio,
                    self::META_ORIGEN => $clave,
                ],
            ]);

            if (is_wp_error($presupuesto_id)) {
                $fila['presupuestos']['omitidos']++;
                $this->avisar($ctx, sprintf(
                    /* translators: 1: correo, 2: motivo */
                    __('%1$s: un presupuesto no se pudo crear (%2$s).', 'egc'),
                    $email,
                    $presupuesto_id->get_error_message()
                ));
                continue;
            }

            if ($categoria_destino) {
                wp_set_object_terms($presupuesto_id, [$categoria_destino], Categoria::TAXONOMY, false);
            }

            $ctx['indice'][$clave] = $presupuesto_id;
            $ocupados[$ocupado]    = true;
            $fila['presupuestos']['creados']++;
        }
    }

    /**
     * wp_insert_post() espera los datos con "slashes" de PHP (igual que
     * lo que llega por $_POST), por eso el wp_slash(): sin él, una
     * barra invertida en una descripción o referencia se perdería.
     *
     * @return int|WP_Error
     */
    private function insertar(array $postarr)
    {
        return wp_insert_post(wp_slash($postarr), true);
    }

    /**
     * Todo lo ya importado alguna vez, `origen => post_id`, de los tres
     * tipos y en cualquier estado (papelera incluida: ver el docblock
     * de la clase). `update_meta_cache()` trae el postmeta de todos de
     * una sola consulta — sin él, cada get_post_meta() sería un SELECT.
     *
     * @return array<string,int>
     */
    private function indice_origen()
    {
        $ids = get_posts([
            'post_type'      => [Billetera::POST_TYPE, Libro::POST_TYPE, Presupuesto::POST_TYPE],
            'post_status'    => array_merge(self::ESTADOS, ['trash']),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => self::META_ORIGEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        ]);

        if (empty($ids)) {
            return [];
        }

        update_meta_cache('post', $ids);

        $indice = [];
        foreach ($ids as $id) {
            $origen = (string) get_post_meta($id, self::META_ORIGEN, true);
            if ($origen !== '') {
                $indice[$origen] = (int) $id;
            }
        }

        return $indice;
    }

    private function clave_origen(array $ctx, $post_type, $origen_id)
    {
        return $ctx['sitio'] . '|' . $post_type . '|' . (int) $origen_id;
    }

    /**
     * Estado del archivo si es uno de los vivos conocidos; cualquier
     * otra cosa (incluida la papelera, que no se exporta) cae a
     * `publish`.
     */
    private function estado_de(array $origen)
    {
        $estado = isset($origen['estado']) ? (string) $origen['estado'] : '';

        return in_array($estado, self::ESTADOS, true) ? $estado : 'publish';
    }

    /**
     * La fecha local del origen si es un `Y-m-d H:i:s` real, o null.
     * createFromFormat() "corrige" fechas imposibles (31 de febrero →
     * marzo), así que se compara contra el formato de vuelta.
     *
     * @return string|null
     */
    private function fecha_de(array $origen)
    {
        $fecha = isset($origen['fecha']) ? (string) $origen['fecha'] : '';
        $dt    = \DateTime::createFromFormat('Y-m-d H:i:s', $fecha);

        return ($dt && $dt->format('Y-m-d H:i:s') === $fecha) ? $fecha : null;
    }

    private function clave_nombre($nombre)
    {
        $nombre = trim((string) $nombre);

        return function_exists('mb_strtolower') ? mb_strtolower($nombre, 'UTF-8') : strtolower($nombre);
    }

    private function avisar(array &$ctx, $mensaje)
    {
        if (count($ctx['avisos']) < self::MAX_AVISOS) {
            $ctx['avisos'][] = $mensaje;
        } else {
            $ctx['avisos_extra']++;
        }
    }
}
