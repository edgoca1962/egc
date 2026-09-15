<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Atributos del banner de cabecera. El Core solo pone un default; los
 * pasos siguientes son:
 * - Página de un módulo (su post_type está en el `post_types` de un
 *   manifest): título = nombre del módulo (`nombre` del manifest),
 *   subtítulo = título de esa página.
 * - Página propia del Core (post_type = 'page'): título = título de
 *   la página, sin subtítulo.
 * - Portada / archivos (no es singular): título = nombre del sitio,
 *   subtítulo = su descripción.
 *
 * Cualquier módulo o página puede seguir pisando esto con el filtro
 * egc_banner_atributos, sin que el Core tenga que conocerlo.
 */
class Banner
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda desde el partial de banner.
    }

    /**
     * @return array{title:string,subtitle:string,image:string}
     */
    public function attributes()
    {
        $module = $this->current_module();

        $default = [
            'title' => $this->default_title($module),
            'subtitle' => $this->default_subtitle($module),
            'image' => $this->generic_image_url(),
        ];

        return apply_filters('egc_banner_atributos', $default);
    }

    private function default_title($module)
    {
        if ($module) {
            return $module['nombre'] ?? '';
        }

        return is_singular() ? get_the_title() : get_bloginfo('name');
    }

    private function default_subtitle($module)
    {
        if ($module) {
            return get_the_title();
        }

        return is_singular() ? '' : get_bloginfo('description');
    }

    /**
     * @return array|null El manifest del módulo dueño del post_type
     *                     actual, o null si no es una página de módulo
     *                     (portada, archivo, o página propia del Core).
     */
    private function current_module()
    {
        if (!is_singular()) {
            return null;
        }

        $post_type = get_post_type();

        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            if (isset($manifest['post_types']) && in_array($post_type, (array) $manifest['post_types'], true)) {
                return $manifest;
            }
        }

        return null;
    }

    private function generic_image_url()
    {
        $path = EGC_DIR . '/assets/img/core/banner.jpg';

        return file_exists($path) ? EGC_URL . '/assets/img/core/banner.jpg' : '';
    }
}
