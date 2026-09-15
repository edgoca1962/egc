<?php

use EGC\Core\Account;
use EGC\Core\BootstrapNavWalker;
use EGC\Core\LoginPage;
use EGC\Core\MenuResolver;
use EGC\Core\PasswordChange;
use EGC\Core\UserManagement;
use EGC\Core\UserRegistration;
use EGC\Core\UserScope;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Excepción a "sin lógica en la vista": is_user_logged_in(),
 * get_avatar_url(), wp_logout_url() son consultas nativas de
 * presentación, no validación de entrada — igual que en el resto del
 * proyecto, la navbar puede usarlas directo.
 */
$locations = MenuResolver::get_instance()->locations();
?>
<nav class="navbar navbar-expand-lg bg-transparent fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo esc_url(home_url('/')); ?>">
            <?php if (has_custom_logo()):
                echo wp_get_attachment_image(get_theme_mod('custom_logo'), 'full', false, [
                    'id' => 'site-logo',
                    'width' => 60,
                    'height' => 60,
                    'style' => 'object-fit:contain;',
                ]);
            else:
                bloginfo('name');
            endif; ?>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#egcNavbar"
            aria-controls="egcNavbar" aria-expanded="false" aria-label="<?php esc_attr_e('Menú', 'egc'); ?>">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="egcNavbar">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?php foreach ($locations as $location):
                    wp_nav_menu([
                        'theme_location' => $location,
                        'container' => false,
                        'items_wrap' => '%3$s',
                        'walker' => new BootstrapNavWalker(),
                        'fallback_cb' => false,
                    ]);
                endforeach; ?>
            </ul>

            <ul class="navbar-nav mb-2 mb-lg-0">
                <li class="nav-item dropdown">
                    <?php if (is_user_logged_in()): ?>
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo esc_url(get_avatar_url(get_current_user_id())); ?>" alt="" width="28"
                                height="28" class="rounded-circle border border-2 border-primary bg-primary"
                                style="object-fit:cover;">
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(Account::get_instance()->url()); ?>">
                                    <?php esc_html_e('Mi cuenta', 'egc'); ?>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item"
                                    href="<?php echo esc_url(PasswordChange::get_instance()->url()); ?>">
                                    <?php esc_html_e('Cambio contraseña', 'egc'); ?>
                                </a>
                            </li>
                            <?php if (UserScope::get_instance()->is_general_admin() || !empty(UserScope::get_instance()->managed_post_types())): ?>
                                <li>
                                    <a class="dropdown-item"
                                        href="<?php echo esc_url(UserManagement::get_instance()->url()); ?>">
                                        <?php esc_html_e('Gestión de usuarios', 'egc'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
                                    <?php esc_html_e('Salir', 'egc'); ?>
                                </a>
                            </li>
                        </ul>
                    <?php else: ?>
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo esc_url(Account::get_instance()->generic_avatar_url()); ?>" alt=""
                                width="28" height="28" class="rounded-circle border border-2" style="object-fit:cover;">
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(LoginPage::get_instance()->url()); ?>">
                                    <?php esc_html_e('Ingresar', 'egc'); ?>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item"
                                    href="<?php echo esc_url(UserRegistration::get_instance()->url()); ?>">
                                    <?php esc_html_e('Solicitar ingreso', 'egc'); ?>
                                </a>
                            </li>
                        </ul>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
</nav>
