<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Atributos del banner de cabecera. El Core solo pone un default
 * genérico (nombre y descripción del sitio); cualquier módulo o
 * página puede cambiarlo colgándose del filtro egc_banner_atributos
 * sin que el Core tenga que conocer a ese módulo — así "cambia según
 * la página/módulo actual" sin una tabla de reglas propia que
 * mantener acá.
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
        $default = [
            'title'    => get_bloginfo('name'),
            'subtitle' => get_bloginfo('description'),
            'image'    => $this->generic_image_url(),
        ];

        return apply_filters('egc_banner_atributos', $default);
    }

    private function generic_image_url()
    {
        $path = EGC_DIR . '/assets/img/banner-generico.jpg';

        return file_exists($path) ? EGC_URL . '/assets/img/banner-generico.jpg' : '';
    }
}
