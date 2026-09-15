<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Orquestador del Core.
 *
 * Punto único de arranque del tema, instanciado desde `functions.php`
 * en `after_setup_theme`. No coordina lógica propia: solo instancia
 * los servicios que necesitan colgarse de un hook (los pasivos —
 * ModuleLoader, ViewResolver, Pages, UserScope, MenuResolver,
 * Banner — se consultan bajo demanda y no aparecen acá).
 */
class Core
{
    use Singleton;

    private function __construct()
    {
        Setup::get_instance();
        AdminGuard::get_instance();
        Assets::get_instance();
        RoleSync::get_instance();
        UserStatus::get_instance();
        AdminGeneralRole::get_instance();
        LoginGuard::get_instance();
        ActivationNotice::get_instance();
        PasswordReset::get_instance();
        LoginPage::get_instance();
        UserRegistration::get_instance();
        UserManagement::get_instance();
        Account::get_instance();
        PasswordChange::get_instance();
        Menus::get_instance();
    }
}
