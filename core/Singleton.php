<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Esquema Singleton reutilizable vía trait.
 *
 * Cualquier clase que necesite una única instancia por request lo
 * incorpora con `use Singleton;` — sin imponer herencia de una clase
 * base común.
 */
trait Singleton
{
    final public static function get_instance()
    {
        static $instances = [];

        $called_class = get_called_class();
        if (!isset($instances[$called_class])) {
            $instances[$called_class] = new $called_class();
            do_action(sprintf('EGC_singleton_init_%s', $called_class));
        }
        return $instances[$called_class];
    }
}
