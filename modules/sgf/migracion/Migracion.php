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
 * Capa Lógica — exportar (y, en el paso siguiente, importar) los datos
 * de SGF de uno o de todos los usuarios, para llevarlos a otra
 * instalación sin perder el trabajo de clasificación de los
 * movimientos.
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
}
