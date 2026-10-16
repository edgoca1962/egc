<?php

namespace EGC\Modules\Sca;

use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Acta\ActaManagement;
use EGC\Modules\Sca\Acuerdo\Acuerdo;
use EGC\Modules\Sca\Acuerdo\AcuerdoManagement;
use EGC\Modules\Sca\Acuerdo\Asignacion;
use EGC\Modules\Sca\Acuerdo\AvisoComentario;
use EGC\Modules\Sca\Acuerdo\Comentarios;
use EGC\Modules\Sca\Acuerdo\Consulta;
use EGC\Modules\Sca\Comite\Comite;
use EGC\Modules\Sca\Comite\ComiteManagement;
use EGC\Modules\Sca\Miembro\Miembro;
use EGC\Modules\Sca\Miembro\MiembroManagement;
use EGC\Modules\Sca\Puesto\Puesto;
use EGC\Modules\Sca\Puesto\PuestoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Bootstrap del módulo SCA — mismo patrón que Core.php y que
 * modules/sgf/module.php: acá se instancian los servicios activos del
 * módulo. Lo incluye ModuleLoader::load_modules() una sola vez, en
 * after_setup_theme.
 *
 * Cada recurso vive en su propia subcarpeta
 * (EGC\Modules\Sca\<Recurso>\<Recurso>), así que hacen falta los `use`
 * de arriba: este archivo está en EGC\Modules\Sca y una referencia
 * directa sin ellos resolvería al namespace equivocado.
 *
 * Orden: primero los cinco recursos y sus metas; después los servicios
 * de reglas (alcance, sincronización de títulos y fechas, consultas
 * de acuerdos, asignación, comentarios y su aviso por correo); luego el CRUD del
 * administrador y, al final, las entradas de navegación (menú del
 * avatar y aterrizaje tras el login). Listado (puente de las vistas)
 * no se instancia acá: lo pide la vista cuando la necesita.
 */
Comite::get_instance();
Puesto::get_instance();
Miembro::get_instance();
Acta::get_instance();
Acuerdo::get_instance();

Alcance::get_instance();
Sincronizacion::get_instance();
Cascada::get_instance();
Consulta::get_instance();
Asignacion::get_instance();
Comentarios::get_instance();
AvisoComentario::get_instance();

// CRUD del administrador (formularios, handlers de admin-post.php) y
// su pantalla de listados.
ComiteManagement::get_instance();
PuestoManagement::get_instance();
MiembroManagement::get_instance();
ActaManagement::get_instance();
AcuerdoManagement::get_instance();
Administracion::get_instance();
Navegacion::get_instance();
