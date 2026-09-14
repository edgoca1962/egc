<?php

use EGC\Core\UserManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$state = UserManagement::get_instance()->view_state();
?>
<div class="container py-5">
    <h1 class="h3 mb-4"><?php esc_html_e('Gestión de usuarios', 'egc'); ?></h1>

    <?php if ($state['success']) : ?>
        <div class="alert alert-success"><?php esc_html_e('Cambio guardado.', 'egc'); ?></div>
    <?php endif; ?>

    <?php if ($state['error']) : ?>
        <div class="alert alert-danger"><?php echo esc_html($state['error']); ?></div>
    <?php endif; ?>

    <?php if (empty($state['users'])) : ?>
        <p><?php esc_html_e('No hay usuarios para mostrar.', 'egc'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Correo', 'egc'); ?></th>
                        <?php if ($state['can_manage_status']) : ?>
                            <th><?php esc_html_e('Estado', 'egc'); ?></th>
                        <?php endif; ?>
                        <?php foreach ($state['post_types'] as $post_type => $data) : ?>
                            <th><?php echo esc_html($data['label']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($state['users'] as $user) : ?>
                    <tr>
                        <td><?php echo esc_html($user['email']); ?></td>

                        <?php if ($state['can_manage_status']) : ?>
                            <td>
                                <?php if (empty($user['valid_next_statuses'])) : ?>
                                    <span class="badge text-bg-secondary"><?php echo esc_html($user['status_label']); ?></span>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url($state['status_form_action']); ?>" class="d-flex gap-1">
                                        <?php wp_nonce_field($state['status_nonce_action'], $state['nonce_name']); ?>
                                        <input type="hidden" name="action" value="egc_user_status">
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr($user['id']); ?>">
                                        <select class="form-select form-select-sm" name="status">
                                            <option value="<?php echo esc_attr($user['status']); ?>" selected>
                                                <?php echo esc_html($user['status_label']); ?>
                                            </option>
                                            <?php foreach ($user['valid_next_statuses'] as $next) : ?>
                                                <option value="<?php echo esc_attr($next); ?>">
                                                    <?php echo esc_html($state['statuses'][$next] ?? $next); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <?php esc_html_e('Guardar', 'egc'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <?php foreach ($state['post_types'] as $post_type => $data) : ?>
                            <td>
                                <form method="post" action="<?php echo esc_url($state['roles_form_action']); ?>" class="d-flex gap-1">
                                    <?php wp_nonce_field($state['roles_nonce_action'], $state['nonce_name']); ?>
                                    <input type="hidden" name="action" value="egc_user_roles">
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr($user['id']); ?>">
                                    <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>">
                                    <select class="form-select form-select-sm" name="role">
                                        <option value=""<?php selected($user['roles'][$post_type], ''); ?>>
                                            <?php esc_html_e('Sin acceso', 'egc'); ?>
                                        </option>
                                        <?php foreach ($data['roles'] as $role_slug => $role_label) : ?>
                                            <option value="<?php echo esc_attr($role_slug); ?>"<?php selected($user['roles'][$post_type], $role_slug); ?>>
                                                <?php echo esc_html($role_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <?php esc_html_e('Guardar', 'egc'); ?>
                                    </button>
                                </form>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
