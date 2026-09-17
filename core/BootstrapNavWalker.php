<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Pinta wp_nav_menu() con clases de Bootstrap 5 (nav-item, nav-link,
 * dropdown) en vez de las <li>/<a> planas que trae WordPress por
 * defecto. No reimplementa nada de la lógica del menú (orden, hijos,
 * item activo): lee las clases que WordPress ya calcula
 * (menu-item-has-children, current-menu-item/-parent/-ancestor) y solo
 * traduce esas señales nativas a clases de Bootstrap.
 *
 * Profundidad arbitraria, resuelta 100% con lo nativo de Bootstrap
 * (sin CSS propio):
 * - Nivel 0 con hijos: "dropdown" — el toggle abre debajo.
 * - Nivel 1+ con hijos: "dropdown dropstart" — dropstart es la misma
 *   mecánica que dropdown pero abre al costado izquierdo (el navbar
 *   está contra el borde derecho, así que un dropend se saldría de la
 *   pantalla); Popper ya trae "flip" activado, así que si en algún
 *   viewport no hay lugar a la izquierda lo abre solo del otro lado —
 *   nada que detectar a mano.
 * - data-bs-auto-close="outside" en TODO ítem con hijos (no solo el
 *   de nivel 0): sin esto, un clic en el toggle de un submenú hijo
 *   ocurre "adentro" del dropdown-menu del padre, y el auto-close por
 *   default de Bootstrap cierra el padre entero en cada clic interno.
 */
class BootstrapNavWalker extends \Walker_Nav_Menu
{
    public function start_lvl(&$output, $depth = 0, $args = null)
    {
        $indent = str_repeat("\t", $depth + 1);
        $output .= "\n{$indent}<ul class=\"dropdown-menu\">\n";
    }

    public function end_lvl(&$output, $depth = 0, $args = null)
    {
        $indent = str_repeat("\t", $depth + 1);
        $output .= "{$indent}</ul>\n";
    }

    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0)
    {
        $classes = empty($item->classes) ? [] : (array) $item->classes;

        $has_children = in_array('menu-item-has-children', $classes, true);
        $is_top_level = $depth === 0;
        $is_active    = array_intersect(
            ['current-menu-item', 'current-menu-parent', 'current-menu-ancestor'],
            $classes
        );

        $li_classes = [];
        if ($is_top_level) {
            $li_classes[] = 'nav-item';
            if ($has_children) {
                $li_classes[] = 'dropdown';
            }
        } elseif ($has_children) {
            $li_classes[] = 'dropdown';
            $li_classes[] = 'dropstart';
        }

        $link_classes = [$is_top_level ? 'nav-link' : 'dropdown-item'];
        if ($has_children) {
            $link_classes[] = 'dropdown-toggle';
        }
        if ($is_active) {
            $link_classes[] = 'active';
        }

        $attributes  = !empty($item->url) ? ' href="' . esc_url($item->url) . '"' : '';
        $attributes .= $has_children
            ? ' role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"'
            : '';
        $attributes .= in_array('current-menu-item', $classes, true) ? ' aria-current="page"' : '';

        $title = apply_filters('the_title', $item->title, $item->ID);

        $li_attr = empty($li_classes) ? '' : ' class="' . esc_attr(implode(' ', array_unique($li_classes))) . '"';

        $output .= '<li' . $li_attr . '>';
        $output .= '<a class="' . esc_attr(implode(' ', array_unique($link_classes))) . '"' . $attributes . '>';
        $output .= esc_html($title);
        $output .= '</a>';
    }

    public function end_el(&$output, $item, $depth = 0, $args = null)
    {
        $output .= "</li>\n";
    }
}
