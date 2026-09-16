<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Descubre los módulos presentes en disco y carga su lógica.
 *
 * Un módulo es cualquier carpeta directa de `modules/` que tenga un
 * `manifest.php` — ese archivo, no una lista central que haya que
 * editar a mano, es la única fuente de verdad de qué módulos existen.
 * Agregar o quitar un módulo es agregar o eliminar su carpeta.
 *
 * Las clases de un módulo viven bajo el namespace `EGC\Modules\<Nombre>`,
 * por convención igual al nombre de su carpeta con la primera letra en
 * mayúscula (carpeta `blog` -> namespace `EGC\Modules\Blog`). No se usa
 * el autoload PSR-4 de Composer para esto: ese mapeo es estático (haría
 * falta `composer dump-autoload` cada vez que se agrega un módulo,
 * rompiendo la promesa de "agregar una carpeta alcanza"). Se registra
 * en cambio un autoloader propio que resuelve la ruta en tiempo de
 * ejecución a partir de los módulos que discover() encuentra.
 *
 * Si el módulo tiene un archivo `module.php` (misma convención de
 * nombre que ya usan las vistas: alcanza con crearlo, no hace falta
 * declararlo en el manifest), ese archivo es su lógica activa — se
 * incluye una sola vez, y ahí el módulo instancia sus propios servicios
 * con hooks, igual que Core.php hace con los suyos.
 */
class ModuleLoader
{
    use Singleton;

    private $manifests;

    private $loaded = false;

    private function __construct()
    {
        $this->manifests = null;
        $this->register_autoloading();
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

    /**
     * Incluye el `module.php` de cada módulo presente que lo tenga, una
     * sola vez por request. Lo llama Core, en el mismo momento
     * (after_setup_theme) en que instancia sus propios servicios
     * activos — así el módulo puede colgarse de hooks posteriores
     * (init, template_redirect, admin_post_*, etc.) con tiempo de sobra.
     */
    public function load_modules()
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        foreach (array_keys($this->discover()) as $slug) {
            $bootstrap = EGC_DIR . '/modules/' . $slug . '/module.php';
            if (file_exists($bootstrap)) {
                include $bootstrap;
            }
        }
    }

    /**
     * Autoload de las clases de módulos: `EGC\Modules\<Nombre>\Clase`
     * -> `modules/<carpeta>/Clase.php`, donde `<carpeta>` es `<Nombre>`
     * en minúsculas. Dinámico, no PSR-4 de Composer, porque los
     * módulos se descubren en tiempo de ejecución (ver docblock de la
     * clase).
     */
    private function register_autoloading()
    {
        spl_autoload_register(function ($class) {
            $prefix = 'EGC\\Modules\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $parts    = explode('\\', $relative);
            $module   = strtolower(array_shift($parts));

            $path = EGC_DIR . '/modules/' . $module . '/' . implode('/', $parts) . '.php';
            if (file_exists($path)) {
                require $path;
            }
        });
    }
}
