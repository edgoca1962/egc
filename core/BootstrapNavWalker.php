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
        $is_active    = array_intersect(
            ['current-menu-item', 'current-menu-parent', 'current-menu-ancestor'],
            $classes
        );

        $li_classes = ['nav-item'];
        if ($has_children) {
            $li_classes[] = 'dropdown';
        }

        $link_classes = $depth > 0 ? ['dropdown-item'] : ['nav-link'];
        if ($has_children) {
            $link_classes[] = $depth > 0 ? 'dropdown-toggle' : 'nav-link dropdown-toggle';
        }
        if ($is_active) {
            $link_classes[] = 'active';
        }

        $attributes  = !empty($item->url) ? ' href="' . esc_url($item->url) . '"' : '';
        $attributes .= $has_children
            ? ' role="button" data-bs-toggle="dropdown" aria-expanded="false"'
            : '';

        $title = apply_filters('the_title', $item->title, $item->ID);

        $output .= '<li class="' . esc_attr(implode(' ', array_unique($li_classes))) . '">';
        $output .= '<a class="' . esc_attr(implode(' ', array_unique($link_classes))) . '"' . $attributes . '>';
        $output .= esc_html($title);
        $output .= '</a>';
    }

    public function end_el(&$output, $item, $depth = 0, $args = null)
    {
        $output .= "</li>\n";
    }
}
