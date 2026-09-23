<?php

namespace EGC\Modules\Sgf;

use EGC\Modules\Sgf\Billetera\Billetera;
use EGC\Modules\Sgf\Billetera\BilleteraManagement;
use EGC\Modules\Sgf\Categoria;
use EGC\Modules\Sgf\CategoriaManagement;
use EGC\Modules\Sgf\Libro\Libro;
use EGC\Modules\Sgf\Libro\LibroImportacion;
use EGC\Modules\Sgf\Libro\LibroManagement;
use EGC\Modules\Sgf\Presupuesto\Presupuesto;
use EGC\Modules\Sgf\Presupuesto\PresupuestoManagement;

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
 * EGC\Modules\Sgf pero las clases de Billetera y Libro viven un nivel
 * más adentro (EGC\Modules\Sgf\Billetera\…, EGC\Modules\Sgf\Libro\…,
 * cada una en su propia subcarpeta) — por eso hacen falta los `use`
 * explícitos de arriba; una referencia directa sin ellos, estando este
 * archivo en el namespace EGC\Modules\Sgf, resolvería al namespace
 * equivocado y el autoloader nunca las encontraría.
 */
Billetera::get_instance();
BilleteraManagement::get_instance();
Libro::get_instance();
Categoria::get_instance();
CategoriaManagement::get_instance();
LibroManagement::get_instance();
LibroImportacion::get_instance();
Presupuesto::get_instance();
PresupuestoManagement::get_instance();

/**
 * Sembrado proactivo de las páginas de alta/edición: igual que Blog, no
 * se deja que se creen solas la primera vez que algo las enlace — es la
 * misma causa del bug ya visto con "Gestión de usuarios" (nadie
 * llamaba a su url() y la página nunca se creaba). find_or_create() no
 * repite trabajo si ya existe.
 */
BilleteraManagement::get_instance()->url_editar();
LibroManagement::get_instance()->url_editar();
LibroManagement::get_instance()->url_mantenimiento();
LibroImportacion::get_instance()->url();
PresupuestoManagement::get_instance()->url_editar();
PresupuestoManagement::get_instance()->url_listado();
CategoriaManagement::get_instance()->url();
