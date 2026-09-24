<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Nomenclatura de `post_name` para los CPT propios del framework.
 *
 * Regla (a partir de ahora, confirmada por Edwin — no retroactiva):
 * el post_name de un registro nuevo se arma como
 * `{post_type}_{10 caracteres alfanuméricos al azar}` — por ejemplo
 * `libro_a1b2c3d4e5` — en vez del slug que WordPress arma por
 * defecto a partir del título (`sanitize_title($post_title)`).
 *
 * Por qué hace falta código propio: WordPress SÍ resuelve solo la
 * mecánica de "generar un slug y garantizar que sea único"
 * (`sanitize_title()` + `wp_unique_post_slug()`, que esta clase sigue
 * usando más abajo — no se reinventa esa parte). Lo que no resuelve es
 * DE QUÉ TEXTO sale ese slug: por defecto siempre sale del título.
 * Cambiar la fuente (un identificador al azar en vez del título) es
 * justo la parte que WordPress no cubre, así que es la única parte que
 * se escribe acá.
 *
 * Motivo del cambio: el título de estos CPT es un dato del negocio (la
 * descripción de un movimiento, el nombre de una billetera) — un slug
 * derivado de él queda desactualizado si el título cambia, puede
 * colisionar entre registros (WordPress lo resuelve agregando "-2",
 * "-3", ...) y, en un CPT público como Billetera (`public => true`,
 * con URL propia — ver Billetera::register_post_type()), ese dato
 * queda expuesto en la URL. Un identificador al azar evita las tres
 * cosas a la vez.
 *
 * Alcance: CUALQUIER CPT propio de CUALQUIER módulo, presente o
 * futuro — nunca un post_type nativo de WordPress ('post', 'page',
 * 'attachment', 'revision', ...), y nunca el `post` que reutiliza el
 * módulo Blog en vez de registrar el suyo propio (ver
 * modules/blog/manifest.php). La distinción la hace WordPress mismo:
 * `get_post_types(['_builtin' => false])` devuelve exactamente los
 * post_types que algún código registró con `register_post_type()`,
 * nunca los nativos — así que no hace falta mantener acá ningún
 * registro a mano de "a qué post_types aplica esto": un módulo nuevo
 * que registre su propio CPT queda cubierto solo con que exista, sin
 * tocar esta clase (mismo espíritu que ARQUITECTURA MODULAR: nada
 * central que haya que editar cada vez que se agrega un módulo).
 */
class PostSlugs
{
    use Singleton;

    private function __construct()
    {
        add_filter('wp_insert_post_data', [$this, 'generar_post_name'], 10, 2);
    }

    /**
     * Cuelga de `wp_insert_post_data` — el mismo filtro que WordPress
     * ya usa internamente para terminar de armar los datos del post
     * justo antes de guardarlos — en vez de fijar `post_name` a mano
     * antes de cada `wp_insert_post()`: así cubre toda alta de estos
     * CPT sin importar desde qué clase o módulo se dispare (incluida
     * la carga masiva de LibroImportacion, sin tocar ese archivo),
     * sin tener que acordarse de armar el post_name en cada punto de
     * inserción por separado.
     *
     * Tres condiciones para actuar, las tres necesarias:
     * - Alta nueva, no edición (`empty($postarr['ID'])`) — la regla es
     *   "a partir de ahora": el post_name de un registro ya existente
     *   nunca se toca.
     * - `post_type` es un CPT propio, nunca uno nativo de WordPress
     *   (ver el docblock de la clase sobre `get_post_types()`).
     * - `post_status` no es `auto-draft` — un auto-draft de Gutenberg
     *   (que ninguno de estos CPT debería generar hoy: su UI está
     *   pensada para el superusuario, no para cargar contenido con el
     *   editor de bloques, pero por las dudas) se descarta solo y se
     *   reemplaza por un post real la primera vez que se guarda de
     *   verdad — no tiene sentido gastarle un identificador antes.
     */
    public function generar_post_name($data, $postarr)
    {
        if (!empty($postarr['ID']) || $data['post_status'] === 'auto-draft') {
            return $data;
        }

        $post_type = $data['post_type'];
        if (!in_array($post_type, get_post_types(['_builtin' => false]), true)) {
            return $data;
        }

        $candidato = $post_type . '_' . strtolower(wp_generate_password(10, false, false));

        // wp_unique_post_slug() es la misma función nativa que ya usa
        // WordPress para esto: si por una coincidencia astronómicamente
        // improbable (36^10 combinaciones posibles) el candidato ya
        // existiera, agrega "-2", "-3", ... sola — no hace falta
        // escribir ningún chequeo de unicidad propio.
        $data['post_name'] = wp_unique_post_slug(
            $candidato,
            0,
            $data['post_status'],
            $post_type,
            (int) ($data['post_parent'] ?? 0)
        );

        return $data;
    }
}
