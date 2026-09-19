<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Qué recursos y qué roles puede administrar el usuario actual.
 *
 * Servicio pasivo (sin hooks): UserManagement lo consulta bajo demanda
 * para saber, del usuario logeado, sobre qué post_types tiene permiso
 * de "administrar" (edit_others_*) y qué roles puede ofrecer al
 * asignar acceso a otro usuario — sin que la vista de gestión de
 * usuarios tenga que conocer capacidades ni manifests directamente.
 *
 * No decide nada nuevo: solo junta current_user_can() (autorización
 * real) con lo que cada manifest declaró (roles, post_types,
 * assignable_roles).
 */
class UserScope
{
    use Singleton;

    private function __construct()
    {
        // Sin hooks propios: se consulta bajo demanda.
    }

    /**
     * @return bool Si el usuario actual es Administrador General. Se
     *              apoya en la capacidad marcadora, nunca en el nombre
     *              del rol.
     */
    public function is_general_admin()
    {
        return current_user_can('edit_users');
    }

    /**
     * @return bool Si el usuario actual administra este post_type: o
     *              bien es Administrador General (accede a todo), o
     *              bien tiene la capacidad "de otros" de ese recurso
     *              (es administrador de ese módulo).
     *
     * La capacidad se lee de get_post_type_object($post_type)->cap, no
     * se reconstruye agregando una "s": esa "s" no es un plural
     * gramatical, es el sufijo que arma register_post_type() cuando el
     * capability_type es un string simple, y falla apenas un módulo
     * declara un plural irregular (capability_type => ['actividad',
     * 'actividades']). WordPress ya calculó el nombre real de la
     * capacidad al registrar el CPT; acá solo se lee.
     */
    public function manages($post_type)
    {
        if ($this->is_general_admin()) {
            return true;
        }

        $post_type_object = get_post_type_object($post_type);
        if (!$post_type_object) {
            return false;
        }

        return current_user_can($post_type_object->cap->edit_others_posts);
    }

    /**
     * @return bool Si el usuario actual tiene su propio CRUD sobre
     *              este post_type (autor/contributor: puede crear y
     *              editar lo suyo) pero NO lo administra. Es la
     *              contraparte de manages(), no una condición
     *              independiente — si ya administra el recurso esto es
     *              false, para que un mismo post_type nunca cuente a
     *              la vez como "administrado" y como "propio" para el
     *              mismo usuario (el bloque de Autor/Contributor del
     *              dropdown del avatar depende de esta exclusión).
     *
     * Igual que manages(), lee la capacidad real desde
     * get_post_type_object($post_type)->cap — nunca compara nombres de
     * rol ni reconstruye el nombre de la capacidad a mano.
     */
    public function authors($post_type)
    {
        if ($this->manages($post_type)) {
            return false;
        }

        $post_type_object = get_post_type_object($post_type);
        if (!$post_type_object) {
            return false;
        }

        return current_user_can($post_type_object->cap->edit_posts);
    }

    /**
     * @return string[] post_types (de todos los manifests) que el
     *                   usuario actual administra. Es lo que
     *                   UserManagement usa para saber qué columnas de
     *                   acceso mostrar y qué puede editar.
     */
    public function managed_post_types()
    {
        $managed = [];

        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            foreach ($this->post_types_of($manifest) as $post_type) {
                if ($this->manages($post_type)) {
                    $managed[] = $post_type;
                }
            }
        }

        return array_values(array_unique($managed));
    }

    /**
     * @return array<int, array{modulo: string, items: array<int, array{label: string, url: string}>}>
     *         Enlaces para el bloque "Administrador de Módulo" del
     *         dropdown del avatar: por cada módulo presente donde el
     *         usuario administra al menos un CPT, su nombre y la lista
     *         de esos CPT (etiqueta + URL de su archive) — ambos datos
     *         nativos de WordPress (get_post_type_object()->labels,
     *         get_post_type_archive_link()), leídos a partir de lo que
     *         cada manifest ya declaró en `post_types`. Ningún módulo
     *         tiene que escribir código ni declarar nada extra para
     *         aparecer acá.
     *
     *         Vacío para el Administrador General: ese caso ya lo
     *         cubre el menú nativo "Administrador general" (ver
     *         Menus::LOC_ADMIN_GENERAL) y no hace falta, además, un
     *         listado con todos los módulos — por eso este método NO
     *         se apoya en manages() tal cual (que da true para todo
     *         con el Administrador General), sino que excluye ese caso
     *         explícitamente.
     */
    public function modulo_links()
    {
        if ($this->is_general_admin()) {
            return [];
        }

        return $this->links_by([$this, 'manages'], 'modulo');
    }

    /**
     * @return array<int, array{modulo: string, items: array<int, array{label: string, url: string}>}>
     *         Igual forma que modulo_links(), pero para el bloque
     *         "Autor/Contributor": los CPT donde el usuario tiene su
     *         propio CRUD sin administrar el recurso. Se apoya en
     *         authors(), que ya excluye por su cuenta al Administrador
     *         General y a quien administra ese CPT puntual — acá no
     *         hace falta ningún resguardo adicional.
     */
    public function autor_links()
    {
        return $this->links_by([$this, 'authors'], 'autor');
    }

    /**
     * Recorre los módulos presentes y arma, agrupados por módulo, los
     * CPT de cada uno para los que $condition($post_type) es true.
     * Compartido por modulo_links() y autor_links() — la única
     * diferencia entre esos dos bloques es qué condición de
     * autorización aplican por CPT, no cómo se recorre ni se agrupa.
     *
     * Por cada post_type que calificó, se aplica el filtro
     * `egc_dropdown_items_{$post_type}` — el punto de extensión para
     * cuando un módulo necesita sumar, a su propio grupo, un enlace que
     * no sale de un archive nativo de CPT. Blog lo usa para "Artículos
     * pendientes de publicar": esa pantalla es una Página propia del
     * módulo (una cola de revisión), no el archive de `post`, así que
     * no hay forma de que este método genérico la infiera solo. $tier
     * ('modulo' o 'autor') le llega al filtro para que cada módulo
     * decida en cuál de los dos bloques corresponde sumar el suyo — acá
     * no se decide nada de autorización, eso es responsabilidad de
     * quien se cuelga del filtro.
     *
     * @param  callable $condition function(string $post_type): bool
     * @param  string   $tier      'modulo' o 'autor', para el filtro de extensión
     * @return array<int, array{modulo: string, items: array<int, array{label: string, url: string}>}>
     */
    private function links_by(callable $condition, $tier)
    {
        $groups = [];

        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            $items = [];

            foreach ($this->post_types_of($manifest) as $post_type) {
                if (!$condition($post_type)) {
                    continue;
                }

                $post_type_object = get_post_type_object($post_type);
                $url              = get_post_type_archive_link($post_type);

                if ($post_type_object && $url) {
                    $items[] = [
                        'label' => $post_type_object->labels->name,
                        'url'   => $url,
                    ];
                }

                $items = apply_filters("egc_dropdown_items_{$post_type}", $items, $tier);
            }

            if ($items) {
                $groups[] = [
                    'modulo' => $manifest['nombre'] ?? '',
                    'items'  => $items,
                ];
            }
        }

        return $groups;
    }

    /**
     * @return string[] Roles asignables para $post_type según el
     *                   manifest de su módulo (mutuamente excluyentes
     *                   entre sí: UserManagement solo permite elegir
     *                   uno de esta lista por recurso).
     */
    public function assignable_roles($post_type)
    {
        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            if (!in_array($post_type, $this->post_types_of($manifest), true)) {
                continue;
            }

            if (isset($manifest['assignable_roles'][$post_type]) && is_array($manifest['assignable_roles'][$post_type])) {
                return $manifest['assignable_roles'][$post_type];
            }
        }

        return [];
    }

    /**
     * @return string Nombre corto para la columna de "Gestión de
     *                 usuarios": la 'sigla' del manifest si la declaró,
     *                 si no su 'nombre', y si tampoco declaró nada, el
     *                 post_type tal cual (mejor eso que una columna sin
     *                 título).
     */
    public function module_label($post_type)
    {
        foreach (ModuleLoader::get_instance()->discover() as $manifest) {
            if (!in_array($post_type, $this->post_types_of($manifest), true)) {
                continue;
            }

            return $manifest['sigla'] ?? $manifest['nombre'] ?? $post_type;
        }

        return $post_type;
    }

    private function post_types_of($manifest)
    {
        if (!isset($manifest['post_types']) || !is_array($manifest['post_types'])) {
            return [];
        }

        return $manifest['post_types'];
    }
}
