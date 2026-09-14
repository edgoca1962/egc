<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Descubre los módulos presentes en disco.
 *
 * Un módulo es cualquier carpeta directa de `modules/` que tenga un
 * `manifest.php` — ese archivo, no una lista central que haya que
 * editar a mano, es la única fuente de verdad de qué módulos existen.
 * Agregar o quitar un módulo es agregar o eliminar su carpeta.
 */
class ModuleLoader
{
    use Singleton;

    private $manifests;

    private function __construct()
    {
        $this->manifests = null;
    }

    /**
     * Devuelve los manifests de los módulos presentes, indexados por
     * el slug de su carpeta. Se calcula una sola vez por request.
     *
     * @return array<string, array>
     */
    public function discover()
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];

        $paths = glob(EGC_DIR . '/modules/*/manifest.php');
        if ($paths === false) {
            return $this->manifests;
        }

        foreach ($paths as $path) {
            $slug = basename(dirname($path));
            $manifest = include $path;

            if (!is_array($manifest)) {
                continue;
            }

            $this->manifests[$slug] = $manifest;
        }

        return $this->manifests;
    }
}
