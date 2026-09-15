<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Qué ubicaciones de menú (de las que declara Menus) le corresponden al
 * usuario actual, en el orden en que deben mostrarse.
 *
 * "Público" lo ve siempre cualquiera, esté o no logueado — incluido el
 * superusuario. A eso se le agrega, como máximo, una ubicación de
 * administración: general y de módulo siguen siendo mutuamente
 * excluyentes entre sí (is_general_admin() se resuelve primero, el
 * superusuario nunca cae en la de módulo), pero ninguna reemplaza a
 * "Público" — se agrega junto a ella.
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

    /**
     * @return string[] Ubicaciones a mostrar, en orden: "Público"
     *                   siempre primero, seguida de la de administración
     *                   que corresponda (si corresponde alguna).
     */
    public function locations()
    {
        $locations = [Menus::LOC_PUBLICO];

        if (!is_user_logged_in()) {
            return $locations;
        }

        $scope = UserScope::get_instance();

        if ($scope->is_general_admin()) {
            $locations[] = Menus::LOC_ADMIN_GENERAL;
        } elseif (!empty($scope->managed_post_types())) {
            $locations[] = Menus::LOC_ADMIN_MODULO;
        }

        return $locations;
    }
}
