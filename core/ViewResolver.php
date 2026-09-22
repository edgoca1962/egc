<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Resuelve qué partial de contenido mostrar.
 *
 * Cuatro niveles, en este orden (el primero que matchea gana):
 *
 * 1. Las 6 páginas propias del Core (por slug fijo, ver
 *    core_page_views()) — Páginas nativas sin post_content, porque el
 *    contenido lo arma la vista misma a partir del view_state() de su
 *    clase.
 * 2. Cualquier otra Página nativa cuyo slug tenga una vista propia
 *    dentro de un módulo (module_page_view()) — misma idea que el
 *    punto 1 pero por convención de nombre de archivo, para que un
 *    módulo no tenga que declarar nada: alcanza con crear
 *    modules/<slug>/views/<slug-de-la-página>.php. Es el mismo
 *    mecanismo que ya se usaba en el WP Modulado original. Igual que
 *    en el punto 3, primero se prueba dentro de la subcarpeta propia de
 *    cada CPT del módulo (modules/<módulo>/<post_type>/views/…) y si no
 *    está ahí, se cae a la carpeta plana — una Página como
 *    "billetera-editar" pertenece a un CPT puntual de un módulo con más
 *    de uno, así que sigue la misma convención de carpeta que ya usan
 *    su single.php y su archive.php.
 * 3. El post_type actual (no "page"), si algún módulo lo declaró en
 *    `post_types` de su manifest (module_content_view()) — ese módulo
 *    es dueño de todo ese tipo de contenido, vista single o
 *    archivo/listado según corresponda. Primero se busca la vista
 *    dentro de la subcarpeta propia del CPT
 *    (modules/<módulo>/<post_type>/views/…) — la convención para un
 *    módulo con más de un CPT, como SGF — y si no está ahí, se cae a
 *    la carpeta plana del módulo (modules/<módulo>/views/…), la
 *    convención de Blog, que con un solo CPT nunca necesitó
 *    subcarpeta.
 * 4. Fallback genérico: cualquier Página u otro contenido nativo sin
 *    dueño ('core/views/pagina'), o nada ('core/views/sin-contenido').
 *
 * Entre módulos no hay validación de colisiones: gana el primero que
 * discover() devuelva (orden de disco) — mismo costo/beneficio ya
 * documentado en el WP Modulado original.
 */
class ViewResolver
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda desde index.php.
    }

    /**
     * @return string Slug relativo al tema, sin `.php`, listo para
     *                `get_template_part()`.
     */
    public function resolve()
    {
        foreach ($this->core_page_views() as $slug => $view) {
            if (is_page($slug)) {
                return $view;
            }
        }

        $module_page = $this->module_page_view();
        if ($module_page) {
            return $module_page;
        }

        $module_content = $this->module_content_view();
        if ($module_content) {
            return $module_content;
        }

        if (have_posts()) {
            return 'core/views/pagina';
        }

        return 'core/views/sin-contenido';
    }

    /**
     * El manifest del módulo dueño de lo que se está viendo ahora mismo
     * — página propia de un módulo o post_type declarado por uno —, o
     * null si es una página del Core o contenido sin dueño. Es la misma
     * pregunta que ya resuelven module_page_view() y
     * module_content_view() para elegir la vista; se expone acá para
     * que Banner (que necesita la misma respuesta para el título) la
     * consulte en vez de tener su propia versión de este cálculo.
     *
     * @return array|null
     */
    public function current_module()
    {
        $slug = $this->current_module_slug();

        return $slug ? ModuleLoader::get_instance()->discover()[$slug] : null;
    }

    private function current_module_slug()
    {
        if (is_page()) {
            $page = get_queried_object();
            if (!$page || empty($page->post_name)) {
                return null;
            }

            foreach (ModuleLoader::get_instance()->discover() as $slug => $manifest) {
                if ($this->page_view_if_exists($slug, $manifest, $page->post_name)) {
                    return $slug;
                }
            }

            return null;
        }

        $post_type = $this->current_post_type();

        foreach (ModuleLoader::get_instance()->discover() as $slug => $manifest) {
            if (!in_array($post_type, $this->post_types_of($manifest), true)) {
                continue;
            }

            if (is_singular($post_type) || is_post_type_archive($post_type) || ($post_type === 'post' && is_home())) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @return array<string,string> slug de Página => vista propia.
     */
    /**
     * El post_type que está pidiendo la URL actual, según la propia
     * consulta — no según el primer resultado que haya encontrado.
     *
     * A propósito NO se usa `get_post_type()` sin argumento: esa
     * función lee el global `$post`, que WordPress solo llena con el
     * primer resultado de la consulta (`$wp_query->post`). En un
     * archive de un CPT con cero registros — el caso de Billetera
     * recién creado, antes de que exista la primera — ese global queda
     * vacío, `get_post_type()` devuelve `false`, y todo el mecanismo de
     * `ViewResolver` terminaba creyendo que el post_type era `post`
     * (por el resguardo `?: 'post'`), perdiendo por completo la vista
     * del módulo dueño aunque estuviera bien ubicada en disco. Con
     * Blog el bug quedaba oculto porque su post_type real también es
     * `post` — el resguardo equivocado coincidía con la respuesta
     * correcta por casualidad.
     *
     * `get_query_var('post_type')` en cambio refleja lo que WordPress
     * resolvió al interpretar la URL (`WP_Query::parse_query()`),
     * antes de ejecutar la consulta — es correcto haya o no resultados.
     *
     * @return string
     */
    private function current_post_type()
    {
        $post_type = get_query_var('post_type');

        if (is_array($post_type)) {
            $post_type = reset($post_type);
        }

        return $post_type ?: 'post';
    }

    private function core_page_views()
    {
        return [
            LoginPage::SLUG        => 'core/views/ingresar',
            UserRegistration::SLUG => 'core/views/solicitar-ingreso',
            PasswordReset::SLUG    => 'core/views/definir-contrasena',
            UserManagement::SLUG   => 'core/views/gestion-usuarios',
            Account::SLUG          => 'core/views/mi-cuenta',
            PasswordChange::SLUG   => 'core/views/cambiar-contrasena',
        ];
    }

    /**
     * Página nativa (no una de las 6 del Core) cuyo slug coincide con
     * un archivo de vista dentro de algún módulo presente.
     *
     * @return string|null
     */
    private function module_page_view()
    {
        if (!is_page()) {
            return null;
        }

        $page = get_queried_object();
        if (!$page || empty($page->post_name)) {
            return null;
        }

        $slug = $this->current_module_slug();
        if (!$slug) {
            return null;
        }

        return $this->page_view_if_exists($slug, ModuleLoader::get_instance()->discover()[$slug], $page->post_name);
    }

    /**
     * Como content_view_if_exists(), pero para una Página nativa: la
     * "billetera-editar" de SGF no es el single ni el archive de un CPT,
     * es una Página que pertenece a uno de los CPT del módulo, así que
     * sigue el mismo criterio de subcarpeta-primero-luego-plana en vez
     * de buscar solo en la carpeta plana del módulo — de lo contrario
     * un módulo con más de un CPT (como SGF, que ya usa esta subcarpeta
     * para su single.php y su archive.php) nunca encontraría la vista de
     * sus propias Páginas.
     *
     * @return string|null
     */
    private function page_view_if_exists($module_slug, $manifest, $page_slug)
    {
        foreach ($this->post_types_of($manifest) as $post_type) {
            $scoped = $this->view_if_exists("{$module_slug}/{$post_type}", $page_slug);
            if ($scoped) {
                return $scoped;
            }
        }

        return $this->view_if_exists($module_slug, $page_slug);
    }

    /**
     * Contenido nativo que no es Página (una entrada, un CPT) cuyo
     * post_type está declarado en `post_types` del manifest de algún
     * módulo presente: single.php o archive.php de ese módulo, según
     * si se está viendo un elemento o el listado.
     *
     * @return string|null
     */
    private function module_content_view()
    {
        if (is_page()) {
            return null;
        }

        $post_type = $this->current_post_type();
        $slug      = $this->current_module_slug();

        if (!$slug) {
            return null;
        }

        if (is_singular($post_type)) {
            return $this->content_view_if_exists($slug, $post_type, 'single');
        }

        if (is_post_type_archive($post_type) || ($post_type === 'post' && is_home())) {
            return $this->content_view_if_exists($slug, $post_type, 'archive');
        }

        return null;
    }

    /**
     * Como view_if_exists(), pero para la vista de un post_type
     * concreto: prueba primero dentro de la subcarpeta propia del CPT
     * (modules/<módulo>/<post_type>/views/…) y, si no existe ahí, cae
     * a la carpeta plana del módulo. No rompe nada de lo ya instalado
     * — Blog nunca tuvo esa subcarpeta, así que siempre cae al mismo
     * lugar de antes.
     *
     * @return string|null
     */
    private function content_view_if_exists($module_slug, $post_type, $view_name)
    {
        $scoped = $this->view_if_exists("{$module_slug}/{$post_type}", $view_name);

        return $scoped ?: $this->view_if_exists($module_slug, $view_name);
    }

    private function view_if_exists($module_slug, $view_name)
    {
        $path = EGC_DIR . "/modules/{$module_slug}/views/{$view_name}.php";
        return file_exists($path) ? "modules/{$module_slug}/views/{$view_name}" : null;
    }

    private function post_types_of($manifest)
    {
        if (!isset($manifest['post_types']) || !is_array($manifest['post_types'])) {
            return [];
        }

        return $manifest['post_types'];
    }
}
