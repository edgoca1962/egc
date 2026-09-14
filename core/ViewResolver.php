<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Resuelve qué partial de contenido mostrar.
 *
 * En esta etapa del Core, sin módulos ni páginas propias declaradas,
 * solo sabe distinguir dos casos genéricos de WordPress: hay contenido
 * que mostrar (una Página o Entrada nativa cualquiera) o no lo hay.
 * Cuando existan módulos, esta clase va a crecer para consultar sus
 * manifests (post types de los que son dueños, vistas propias por
 * slug de página) antes de caer a este fallback — no antes.
 */
class ViewResolver
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda desde index.php.
    }

    /**
     * @return string Slug relativo al tema, sin `.php`, listo para
     *                `get_template_part()`.
     */
    public function resolve()
    {
        if (have_posts()) {
            return 'core/views/pagina';
        }

        return 'core/views/sin-contenido';
    }
}
