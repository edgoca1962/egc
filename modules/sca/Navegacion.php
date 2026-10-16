<?php

namespace EGC\Modules\Sca;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Acuerdo\Acuerdo;
use EGC\Modules\Sca\Acuerdo\Asignacion;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — cómo se llega a SCA (módulo SCA): las dos entradas del
 * módulo al resto del tema, ambas por ganchos que el Core ya expone
 * (el Core no sabe que SCA existe).
 *
 * 1. Menú del avatar. El Core arma solo el enlace "Acuerdos" a partir
 *    del archive nativo del CPT (UserScope::links_by()), tanto para el
 *    administrador (bloque de módulo) como para el lector (bloque
 *    "participa", ver UserScope::lector_links()). Lo único que no sale
 *    de un archive es la pantalla de administración, que es una
 *    Página: se suma por el filtro `egc_dropdown_items_acuerdo`, y solo
 *    en el bloque del administrador ($tier = 'modulo').
 *
 * 2. Aterrizaje tras el login (`egc_login_redirect`). Quien participa
 *    en SCA (editor o lector) entra directo a la lista de acuerdos en
 *    vez de a la portada. El Core solo ofrece el filtro cuando el
 *    destino es la portada: si la persona venía pidiendo otra página,
 *    se respeta. No se aplica al superusuario ni al Administrador
 *    General (que no tienen rol de SCA salvo que se lo den): ellos
 *    siguen entrando a la portada. En un sitio con varios módulos
 *    gana el primero que cambia el destino; hoy solo SCA lo hace.
 *
 * Una clase aparte de Administracion y de Listado porque su razón de
 * cambiar es otra: cambia cuando cambia la navegación del sitio, no
 * cuando cambian las pantallas.
 */
class Navegacion
{
    use Singleton;

    /** Roles que dan entrada a SCA (declarados en el manifest). */
    const ROLES = ['sca_editor', Asignacion::ROL_LECTOR];

    private function __construct()
    {
        add_filter('egc_dropdown_items_' . Acuerdo::POST_TYPE, [$this, 'items_menu'], 10, 2);
        add_filter('egc_login_redirect', [$this, 'aterrizaje'], 10, 2);
    }

    /**
     * @param array  $items Enlaces ya armados del grupo.
     * @param string $tier  'modulo' | 'autor' | 'lector'.
     * @return array
     */
    public function items_menu($items, $tier)
    {
        if ($tier !== 'modulo') {
            return $items;
        }

        $items[] = [
            'label' => __('Administración de acuerdos', 'egc'),
            'url'   => Administracion::get_instance()->url('comites'),
        ];

        return $items;
    }

    /**
     * @param string        $url  Destino actual (la portada).
     * @param \WP_User|mixed $user Quien acaba de ingresar.
     * @return string
     */
    public function aterrizaje($url, $user)
    {
        if (!($user instanceof \WP_User) || !array_intersect(self::ROLES, (array) $user->roles)) {
            return $url;
        }

        $archivo = get_post_type_archive_link(Acuerdo::POST_TYPE);

        return $archivo ?: $url;
    }
}
