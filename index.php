<?php
/**
 * Único archivo de marcado del tema.
 *
 * No hay header.php ni footer.php: el <head>, el <body>, el navbar y
 * el banner se resuelven aquí mismo, llamando directamente a los
 * servicios del Core. Al no existir single.php, archive.php, page.php
 * ni front-page.php en este tema, WordPress cae siempre en index.php
 * para cualquier tipo de contenido —comportamiento nativo de la
 * jerarquía de plantillas, sin filtros de por medio—.
 */

defined('ABSPATH') || exit;
