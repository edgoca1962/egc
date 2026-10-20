<?php

namespace EGC\Modules\Sca;

use EGC\Core\Banner;
use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Modules\Sca\Acuerdo\Acuerdo;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Puente — la página "Principal" del módulo SCA: una portada a
 * pantalla completa con el nombre y la descripción del sitio y un botón
 * hacia la lista de acuerdos (o al login, si no hay sesión).
 *
 * Qué resuelve WordPress y qué no (PRINCIPIO RECTOR): la página es una
 * Página nativa (Pages::find_or_create, el mismo helper que usa
 * Administracion) y la vista se encuentra por la convención de nombre
 * del Core (modules/sca/views/<slug>.php), sin declarar nada. El nombre
 * y la descripción salen de get_bloginfo(), la forma que devuelve el
 * texto de bloginfo() sin imprimirlo: la vista solo hace echo. Lo único
 * propio es el estado que pinta la vista y apagar la cabecera estándar
 * en esta página, con el filtro `egc_mostrar_cabecera` que el Core ya
 * ofrece (el mismo que usa el login).
 *
 * Mismo aspecto que el login: la imagen genérica del banner
 * (Banner::generic_image_url(), el mismo archivo, no una copia) con la
 * capa oscura, a pantalla completa (`min-vh-100`). Por eso no se pinta
 * el banner estándar de index.php: sería la misma imagen dos veces. La
 * navbar sí se conserva, dentro de la propia vista, para no dejar a
 * quien llega sin navegación.
 *
 * No cambia el aterrizaje tras el login (sigue siendo la lista de
 * acuerdos, ver Navegacion). La página se siembra al entrar al
 * wp-admin el super usuario, que es quien arma los menús: así aparece
 * en Apariencia > Menús para enlazarla donde se quiera.
 *
 * Es una clase aparte de Administracion y de Navegacion porque cambia
 * por otra razón: cambia cuando cambia la portada, no las pantallas de
 * mantenimiento ni los caminos de entrada.
 */
class Principal
{
    use Singleton;

    const SLUG = 'sca-principal';

    /** Capa oscura sobre la imagen: la misma que usa la vista del login. */
    const CAPA = 'linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5))';

    private $url = null;

    private function __construct()
    {
        add_filter('egc_mostrar_cabecera', [$this, 'ocultar_cabecera']);
        add_action('admin_init', [$this, 'url']);
    }

    /**
     * Solo en esta página; nunca pisa lo que otro filtro haya decidido
     * antes.
     */
    public function ocultar_cabecera($mostrar)
    {
        return is_page(self::SLUG) ? false : $mostrar;
    }

    /**
     * Dirección de la página (la crea si todavía no existe).
     */
    public function url()
    {
        if ($this->url === null) {
            $id        = Pages::get_instance()->find_or_create(__('Principal', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * Todo lo que pinta la vista.
     *
     * @return array{titulo:string,descripcion:string,estilo:string,tiene_imagen:bool,boton:array{texto:string,url:string}}
     */
    public function view_state()
    {
        $imagen = Banner::get_instance()->generic_image_url();
        $archivo = get_post_type_archive_link(Acuerdo::POST_TYPE);

        return [
            'titulo'       => get_bloginfo('name'),
            'descripcion'  => get_bloginfo('description'),
            'tiene_imagen' => (bool) $imagen,
            'estilo'       => $imagen
                ? 'background-image:' . self::CAPA . ',url(' . esc_url($imagen) . ');background-size:cover;background-position:center;'
                : '',
            'boton'        => is_user_logged_in()
                ? ['texto' => __('Ir a Acuerdos', 'egc'), 'url' => $archivo ?: home_url('/')]
                : ['texto' => __('Ingresar', 'egc'), 'url' => LoginPage::get_instance()->url()],
        ];
    }
}
