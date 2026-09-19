<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Qué ubicaciones de menú (de las que declara Menus) le corresponden al
 * usuario actual, en el orden en que deben mostrarse.
 *
 * Hoy es solo "Público" — lo ve cualquiera, esté o no logueado,
 * incluido el Administrador General. "Administrador general" ya no se
 * ofrece acá: vive únicamente en el dropdown del avatar (ver
 * navbar.php), para no repetir el mismo menú dos veces en la misma
 * página. Lo mismo pasó antes con "administrador de módulo" (ver
 * abajo) — la barra superior quedó reservada para navegación pública,
 * toda la navegación de administración vive en el dropdown.
 *
 * Ya no hay una ubicación para "administrador de módulo" tampoco: esos
 * enlaces son dinámicos y viven en el dropdown del avatar (ver
 * UserScope::modulo_links()/autor_links()) — un menú nativo armado a
 * mano no puede variar según los módulos presentes ni según el rol del
 * usuario en cada uno.
 *
 * Se mantiene como servicio propio (en vez de que navbar.php arme el
 * arreglo directo) para que un futuro cambio de qué ubicaciones ofrece
 * la barra superior tenga un único lugar donde resolverse.
 */
class MenuResolver
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda desde el partial de navbar.
    }

    /**
     * @return string[] Ubicaciones a mostrar en la barra superior.
     */
    public function locations()
    {
        return [Menus::LOC_PUBLICO];
    }
}
