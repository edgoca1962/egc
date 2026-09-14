<?php

use EGC\Core\Account;
use EGC\Core\BootstrapNavWalker;
use EGC\Core\LoginPage;
use EGC\Core\MenuResolver;
use EGC\Core\PasswordChange;
use EGC\Core\UserRegistration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Excepción a "sin lógica en la vista": is_user_logged_in(),
 * get_avatar_url(), wp_logout_url() son consultas nativas de
 * presentación, no validación de entrada — igual que en el resto del
 * proyecto, la navbar puede usarlas directo.
 */
$location = MenuResolver::get_instance()->location();
?>
<nav class="navbar navbar-expand-lg bg-body-tertiary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo esc_url(home_url('/')); ?>">
            <?php bloginfo('name'); ?>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#egcNavbar" aria-controls="egcNavbar"
                aria-expanded="false" aria-label="<?php esc_attr_e('Menú', 'egc'); ?>">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="egcNavbar">
            <?php
            wp_nav_menu([
                'theme_location' => $location,
                'container'      => false,
                'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
                'menu_class'     => 'navbar-nav me-auto mb-2 mb-lg-0',
                'walker'         => new BootstrapNavWalker(),
                'fallback_cb'    => false,
            ]);
            ?>

            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <?php if (is_user_logged_in()) : ?>
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo esc_url(get_avatar_url(get_current_user_id())); ?>" alt=""
                                 width="28" height="28" class="rounded-circle">
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(Account::get_instance()->url()); ?>">
                                    <?php esc_html_e('Mi cuenta', 'egc'); ?>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(PasswordChange::get_instance()->url()); ?>">
                                    <?php esc_html_e('Cambio contraseña', 'egc'); ?>
                                </a>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
                                    <?php esc_html_e('Salir', 'egc'); ?>
                                </a>
                            </li>
                        </ul>
                    <?php else : ?>
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                                 viewBox="0 0 16 16" aria-hidden="true">
                                <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4Zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10Z"/>
                            </svg>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(LoginPage::get_instance()->url()); ?>">
                                    <?php esc_html_e('Ingresar', 'egc'); ?>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php echo esc_url(UserRegistration::get_instance()->url()); ?>">
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
