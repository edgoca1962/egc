<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Atributos del banner de cabecera. El Core solo pone un default; los
 * pasos siguientes son:
 * - Página, archivo/listado o entrada de un módulo (ViewResolver la
 *   reconoce como suya): título = nombre del módulo (`nombre` del
 *   manifest). Subtítulo: el título de la entrada/página si es un
 *   contenido singular, vacío si es un listado (archivo).
 * - Página propia del Core (post_type = 'page'): título = título de
 *   la página, sin subtítulo.
 * - Portada / archivos sin dueño (no es singular): título = nombre
 *   del sitio, subtítulo = su descripción.
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
        // "Qué módulo es dueño de esto" ya lo resuelve ViewResolver
        // para elegir la vista; se reutiliza esa misma respuesta acá
        // en vez de tener una segunda versión de ese cálculo que se
        // pueda desincronizar de la primera.
        $module = ViewResolver::get_instance()->current_module();

        $default = [
            'title'    => $this->default_title($module),
            'subtitle' => $this->default_subtitle($module),
            'image'    => $this->generic_image_url(),
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
            return is_singular() ? get_the_title() : '';
        }

        return is_singular() ? '' : get_bloginfo('description');
    }

    private function generic_image_url()
    {
        $path = EGC_DIR . '/assets/img/core/banner.jpg';

        return file_exists($path) ? EGC_URL . '/assets/img/core/banner.jpg' : '';
    }
}
