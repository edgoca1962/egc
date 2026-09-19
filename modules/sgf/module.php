<?php

namespace EGC\Modules\Sgf;

use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Billetera\BilleteraManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Bootstrap del módulo SGF — mismo patrón que Core.php y que
 * modules/blog/module.php: acá se instancian los servicios activos del
 * módulo. Lo incluye ModuleLoader::load_modules() una sola vez, en
 * after_setup_theme.
 *
 * A diferencia de Blog, este archivo vive en el namespace
 * EGC\Modules\Sgf pero las clases de Billetera viven un nivel más
 * adentro (EGC\Modules\Sgf\Billetera\…, en su propia subcarpeta) — por
 * eso hacen falta los `use` explícitos de arriba; una referencia
 * directa sin ellos, estando este archivo en el namespace
 * EGC\Modules\Sgf, resolvería al namespace equivocado y el autoloader
 * nunca las encontraría.
 */
Billetera::get_instance();
BilleteraManagement::get_instance();

/**
 * Sembrado proactivo de la página de alta/edición: igual que Blog, no
 * se deja que se cree sola la primera vez que algo la enlace — es la
 * misma causa del bug ya visto con "Gestión de usuarios" (nadie
 * llamaba a su url() y la página nunca se creaba). find_or_create() no
 * repite trabajo si ya existe.
 */
BilleteraManagement::get_instance()->url_editar();
