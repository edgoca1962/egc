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
        $html    = $this->activation_email_html(get_bloginfo('name'), $url);

        $sent = $this->send_html($user->user_email, $subject, $html);
        if (!$sent) {
            error_log(sprintf('EGC: wp_mail() devolvió false al notificar la activación del usuario #%d.', $user_id));
        }
    }

    /**
     * wp_mail() manda texto plano por defecto; WordPress no tiene una
     * forma nativa de mandar HTML "de a ratos" sin afectar a los demás
     * correos del sitio, así que se agrega y se saca el filtro
     * alrededor de este único envío.
     */
    private function send_html($to, $subject, $html)
    {
        $force_html = function () {
            return 'text/html';
        };

        add_filter('wp_mail_content_type', $force_html);
        $sent = wp_mail($to, $subject, $html);
        remove_filter('wp_mail_content_type', $force_html);

        return $sent;
    }

    /**
     * Estilos en línea a propósito: los clientes de correo no cargan
     * hojas de estilo externas ni ejecutan JS, así que esto no pasa
     * por el webpack del sitio — es contenido de un correo, no de una
     * vista.
     */
    private function activation_email_html($site, $url)
    {
        return sprintf(
            '<!doctype html>
<html>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:32px 0;">
<tr><td align="center">
<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;">
<tr><td style="background-color:#f2683d;padding:24px 32px;">
<span style="color:#ffffff;font-size:18px;font-weight:bold;">%1$s</span>
</td></tr>
<tr><td style="padding:32px;color:#212529;font-size:15px;line-height:1.5;">
<p style="margin:0 0 16px;">%2$s</p>
<p style="margin:0 0 24px;">%3$s</p>
<p style="margin:0 0 24px;text-align:center;">
<a href="%4$s" style="display:inline-block;background-color:#f2683d;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;">%5$s</a>
</p>
<p style="margin:0;color:#6c757d;font-size:13px;">%6$s<br>
<a href="%4$s" style="color:#212529;">%4$s</a>
</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
            esc_html($site),
            sprintf(esc_html__('Tu acceso a %s fue activado.', 'egc'), '<strong>' . esc_html($site) . '</strong>'),
            esc_html__('Para definir tu contraseña, hacé clic en el siguiente botón:', 'egc'),
            esc_url($url),
            esc_html__('Definir contraseña', 'egc'),
            esc_html__('Si el botón no funciona, copiá y pegá este enlace en tu navegador:', 'egc')
        );
    }
}
