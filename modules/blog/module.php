<?php

namespace EGC\Modules\Blog;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Bootstrap del módulo Blog — mismo patrón que Core.php: acá se
 * instancian los servicios activos del módulo (los que se cuelgan de
 * un hook). Lo incluye ModuleLoader::load_modules() una sola vez, en
 * after_setup_theme.
 */
PostManagement::get_instance();

/**
 * Sembrado proactivo de la página del panel: a diferencia de las 6
 * páginas del Core (que se crean la primera vez que el navbar las
 * enlaza), acá se crea directamente en el bootstrap del módulo, para no
 * depender de que algo la enlace primero — es la causa exacta del bug
 * ya visto con "gestión de usuarios" (nadie llamaba a su url() y la
 * página nunca se creaba). find_or_create() no repite trabajo si ya
 * existe.
 */
PostManagement::get_instance()->url();
