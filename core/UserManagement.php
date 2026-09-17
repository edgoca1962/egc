<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * CRUD de usuarios (nunca del superusuario): estado de aduana y
 * acceso a módulos con roles distintos por recurso.
 *
 * Una sola pantalla sirve a dos audiencias distintas, cada una vista
 * por current_user_can() — nunca por nombre de rol:
 * - Administrador General (edit_users): único que cambia el estado
 *   pendiente/activo/rechazado, porque es una puerta global del
 *   sitio, no de un módulo en particular.
 * - Administrador de módulo (edit_others_{recurso}s): ve y asigna el
 *   rol de sus propios recursos, uno a la vez y mutuamente excluyente
 *   dentro de ese recurso (se agrega con add_role(), nunca set_role(),
 *   para no pisar roles de otros módulos).
 *
 * UserScope resuelve el alcance; esta clase solo aplica los cambios
 * ya autorizados y prepara los datos para la vista.
 */
class UserManagement
{
    use Singleton;

    const SLUG = 'gestion-usuarios';

    const ACTION_STATUS = 'egc_user_status';

    const ACTION_ROLES = 'egc_user_roles';

    const ACTION_ADMIN_GENERAL = 'egc_user_admin_general';

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_STATUS, [$this, 'handle_status_change']);
        add_action('admin_post_' . self::ACTION_ROLES, [$this, 'handle_role_change']);
        add_action('admin_post_' . self::ACTION_ADMIN_GENERAL, [$this, 'handle_admin_general_toggle']);
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Gestión de usuarios', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * Nadie sin sesión, y ningún usuario sin ningún alcance de
     * administración, llega a esta página. Ocultar el link del menú
     * es presentación; esto es lo que de verdad la protege.
     */
    public function guard_access()
    {
        if (!is_page(self::SLUG)) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $scope = UserScope::get_instance();
        if (!$scope->is_general_admin() && empty($scope->managed_post_types())) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * @return array{
     *   can_manage_status: bool,
     *   statuses: array<string,string>,
     *   post_types: array<string,array{label:string,roles:array<string,string>}>,
     *   users: array<int,array>,
     *   error: string,
     *   success: bool,
     *   status_form_action: string,
     *   status_nonce_action: string,
     *   roles_form_action: string,
     *   roles_nonce_action: string,
     *   nonce_name: string,
     * }
     */
    public function view_state()
    {
        $scope      = UserScope::get_instance();
        $post_types = $scope->managed_post_types();

        $post_type_data = [];
        foreach ($post_types as $post_type) {
            $post_type_data[$post_type] = [
                'label' => $scope->module_label($post_type),
                'roles' => $this->role_labels($scope->assignable_roles($post_type)),
            ];
        }

        return [
            'can_manage_status'   => $scope->is_general_admin(),
            'statuses'            => UserStatus::get_instance()->all_statuses(),
            'post_types'          => $post_type_data,
            'users'               => $this->users_rows($post_types, $scope),
            'error'               => $this->message('error'),
            'success'             => (bool) $this->message('ok'),
            'status_form_action'        => admin_url('admin-post.php'),
            'status_nonce_action'       => self::ACTION_STATUS,
            'roles_form_action'         => admin_url('admin-post.php'),
            'roles_nonce_action'        => self::ACTION_ROLES,
            'admin_general_form_action'  => admin_url('admin-post.php'),
            'admin_general_nonce_action' => self::ACTION_ADMIN_GENERAL,
            'nonce_name'                => '_egc_nonce',
        ];
    }

    private function role_labels($role_slugs)
    {
        $names  = wp_roles()->role_names;
        $labels = [];

        foreach ($role_slugs as $slug) {
            $labels[$slug] = $names[$slug] ?? $slug;
        }

        return $labels;
    }

    private function users_rows($post_types, UserScope $scope)
    {
        $rows = [];

        foreach (get_users(['orderby' => 'registered', 'order' => 'DESC']) as $user) {
            if (user_can($user, 'manage_options')) {
                continue; // El superusuario nunca pasa por este CRUD.
            }

            $status = UserStatus::get_instance()->get_status($user->ID);

            $roles = [];
            foreach ($post_types as $post_type) {
                $roles[$post_type] = $this->current_role_for($user, $scope->assignable_roles($post_type));
            }

            $rows[] = [
                'id'                   => $user->ID,
                'email'                => $user->user_email,
                'status'               => $status,
                'status_label'         => UserStatus::get_instance()->all_statuses()[$status] ?? $status,
                'valid_next_statuses'  => UserStatus::get_instance()->valid_next_statuses($status),
                'roles'                => $roles,
                'is_admin_general'     => in_array(AdminGeneralRole::ROLE, (array) $user->roles, true),
            ];
        }

        return $rows;
    }

    private function current_role_for($user, $assignable_roles)
    {
        foreach ($assignable_roles as $role) {
            if (in_array($role, (array) $user->roles, true)) {
                return $role;
            }
        }

        return '';
    }

    private function message($param)
    {
        if ($param === 'ok') {
            return isset($_GET['ok']);
        }

        $error = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';

        switch ($error) {
            case 'forbidden':
                return __('No tenés permiso para hacer eso.', 'egc');
            case 'invalid_transition':
                return __('Ese cambio de estado no está permitido: primero tiene que pasar por Pendiente.', 'egc');
            case 'invalid_role':
                return __('Ese rol no es válido para este recurso.', 'egc');
            default:
                return '';
        }
    }

    public function handle_status_change()
    {
        check_admin_referer(self::ACTION_STATUS, '_egc_nonce');

        if (!current_user_can('edit_users')) {
            $this->back_with_error('forbidden');
        }

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $status  = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

        if (!$user_id || user_can($user_id, 'manage_options')) {
            $this->back_with_error('forbidden');
        }

        if (!UserStatus::get_instance()->set_status($user_id, $status)) {
            $this->back_with_error('invalid_transition');
        }

        $this->back_with_ok();
    }

    public function handle_role_change()
    {
        check_admin_referer(self::ACTION_ROLES, '_egc_nonce');

        $user_id   = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '';
        $new_role  = isset($_POST['role']) ? sanitize_key($_POST['role']) : '';

        if (!$user_id || user_can($user_id, 'manage_options')) {
            $this->back_with_error('forbidden');
        }

        if (!UserScope::get_instance()->manages($post_type)) {
            $this->back_with_error('forbidden');
        }

        $assignable = UserScope::get_instance()->assignable_roles($post_type);

        if ($new_role !== '' && !in_array($new_role, $assignable, true)) {
            $this->back_with_error('invalid_role');
        }

        $user = get_userdata($user_id);
        if (!$user) {
            $this->back_with_error('forbidden');
        }

        // Mutuamente excluyente dentro del recurso: se saca cualquier
        // otro rol asignable de este mismo recurso antes de agregar el
        // nuevo. add_role() (no set_role()) para no tocar los roles de
        // otros módulos.
        foreach ($assignable as $candidate) {
            if ($candidate !== $new_role) {
                $user->remove_role($candidate);
            }
        }

        if ($new_role !== '' && !in_array($new_role, (array) $user->roles, true)) {
            $user->add_role($new_role);
        }

        $this->back_with_ok();
    }

    /**
     * Asigna o quita el rol 'administrador_general' a otro usuario. Es
     * su propia acción (no una más del <select> por recurso) porque no
     * es un rol de módulo: es la misma "puerta global" que el cambio de
     * estado, así que se autoriza con la misma capacidad (edit_users) y
     * nunca toca al superusuario.
     */
    public function handle_admin_general_toggle()
    {
        check_admin_referer(self::ACTION_ADMIN_GENERAL, '_egc_nonce');

        if (!current_user_can('edit_users')) {
            $this->back_with_error('forbidden');
        }

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $grant   = !empty($_POST['is_admin_general']);

        $user = $user_id ? get_userdata($user_id) : null;
        if (!$user || user_can($user, 'manage_options')) {
            $this->back_with_error('forbidden');
        }

        if ($grant) {
            $user->add_role(AdminGeneralRole::ROLE);
        } else {
            $user->remove_role(AdminGeneralRole::ROLE);
        }

        $this->back_with_ok();
    }

    private function back_with_ok()
    {
        wp_safe_redirect(add_query_arg('ok', '1', $this->url()));
        exit;
    }

    private function back_with_error($error)
    {
        wp_safe_redirect(add_query_arg('error', $error, $this->url()));
        exit;
    }
}
