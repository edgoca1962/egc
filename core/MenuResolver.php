<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Qué ubicación de menú (de las que declara Menus) le corresponde al
 * usuario actual. Administrador general y administrador de módulo son
 * mutuamente excluyentes por diseño: es un if/elseif que devuelve la
 * primera que aplica, y is_general_admin() se resuelve antes que
 * "administra algún módulo" — el superusuario, que siempre es
 * Administrador General, nunca cae en el menú de módulo.
 *
 * Servicio pasivo: la vista de la navbar lo consulta bajo demanda.
 */
class MenuResolver
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda desde el partial de navbar.
    }

    public function location()
    {
        if (!is_user_logged_in()) {
            return Menus::LOC_PUBLICO;
        }

        $scope = UserScope::get_instance();

        if ($scope->is_general_admin()) {
            return Menus::LOC_ADMIN_GENERAL;
        }

        if (!empty($scope->managed_post_types())) {
            return Menus::LOC_ADMIN_MODULO;
        }

        // Usuario logeado sin ningún rol de administración (por ejemplo,
        // un suscriptor base recién activado): sigue viendo el menú público.
        return Menus::LOC_PUBLICO;
    }
}
