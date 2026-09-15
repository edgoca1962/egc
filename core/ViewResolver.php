<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Resuelve qué partial de contenido mostrar.
 *
 * Antes de caer al genérico (Página/Entrada nativa cualquiera), reconoce
 * las páginas propias del Core: son Páginas nativas sin post_content
 * (Pages::find_or_create() no les pone contenido, porque el contenido
 * lo arma la vista misma a partir del view_state() de su clase) — sin
 * este mapa, WordPress las mostraría vacías con el template genérico.
 *
 * Cuando existan módulos, esta clase va a crecer para consultar sus
 * manifests (páginas propias por slug) de la misma manera, antes de
 * caer a este fallback.
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
        foreach ($this->core_page_views() as $slug => $view) {
            if (is_page($slug)) {
                return $view;
            }
        }

        if (have_posts()) {
            return 'core/views/pagina';
        }

        return 'core/views/sin-contenido';
    }

    /**
     * @return array<string,string> slug de Página => vista propia.
     */
    private function core_page_views()
    {
        return [
            LoginPage::SLUG        => 'core/views/ingresar',
            UserRegistration::SLUG => 'core/views/solicitar-ingreso',
            PasswordReset::SLUG    => 'core/views/definir-contrasena',
            UserManagement::SLUG   => 'core/views/gestion-usuarios',
            Account::SLUG          => 'core/views/mi-cuenta',
            PasswordChange::SLUG   => 'core/views/cambiar-contrasena',
        ];
    }
}
