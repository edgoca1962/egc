<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Envía el correo de "definir tu contraseña" cuando un usuario pasa a
 * Activo. No decide el cambio de estado (eso es de UserStatus): solo
 * reacciona a egc_user_status_changed, que por la regla de aduana solo
 * llega a Activo viniendo de Pendiente, así que este correo solo se
 * dispara cuando corresponde dar acceso por primera vez.
 *
 * Reutiliza get_password_reset_key(), la misma función nativa que usa
 * el "olvidé mi contraseña" de WordPress: para el sistema es la misma
 * operación (generar una clave de un solo uso para fijar contraseña),
 * solo cambia quién la dispara y qué vista la recibe.
 */
class ActivationNotice
{
    use Singleton;

    private function __construct()
    {
        add_action('egc_user_status_changed', [$this, 'notify_on_activation'], 10, 3);
    }

    public function notify_on_activation($user_id, $new_status, $previous_status)
    {
        if ($new_status !== UserStatus::ACTIVO) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            error_log(sprintf('EGC: no se pudo generar la clave de activación para el usuario #%d.', $user_id));
            return;
        }

        $url = add_query_arg([
            'key'   => rawurlencode($key),
            'login' => rawurlencode($user->user_login),
        ], PasswordReset::get_instance()->url());

        $subject = sprintf(__('[%s] Tu acceso fue activado', 'egc'), get_bloginfo('name'));

        $message = sprintf(
            /* translators: 1: nombre del sitio, 2: link para definir contraseña. */
            __("Tu acceso a %1\$s fue activado.\n\nPara definir tu contraseña, entrá a este enlace:\n%2\$s", 'egc'),
            get_bloginfo('name'),
            $url
        );

        wp_mail($user->user_email, $subject, $message);
    }
}
