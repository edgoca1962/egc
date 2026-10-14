<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Core\UserRegistration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — correo de invitación a quien tiene un acuerdo asignado
 * pero todavía no tiene cuenta (módulo SCA).
 *
 * El correo lleva al enlace de la página "Solicitar ingreso" que ya
 * existe en el Core (UserRegistration): SCA no crea un flujo de alta
 * propio. Cuando el administrador del sitio apruebe esa solicitud, el
 * Core avisa con `egc_user_status_changed` y Asignacion completa la
 * asignación pendiente.
 *
 * Por qué tiene su propio envío HTML y no reutiliza el de
 * ActivationNotice: ese método es privado y está pegado a su correo
 * ("definir contraseña"). Hoy son dos lugares con el mismo recurso de
 * ~10 líneas (forzar text/html alrededor de un único wp_mail()); el
 * día que haya un tercero, el candidato es subirlo al Core como
 * servicio de correo HTML. No se hace ahora para no tocar el Core por
 * un solo módulo.
 *
 * Clase de métodos estáticos: sin estado ni hooks.
 */
final class Notificacion
{
    /**
     * @return bool Si wp_mail() aceptó el mensaje.
     */
    public static function invitar($email, $acuerdo_id)
    {
        $acuerdo = get_post($acuerdo_id);
        if (!$acuerdo || !is_email($email)) {
            return false;
        }

        $sitio   = get_bloginfo('name');
        $url     = UserRegistration::get_instance()->url();
        $asunto  = sprintf(__('[%s] Se te asignó un acuerdo', 'egc'), $sitio);
        $mensaje = self::html($sitio, $acuerdo->post_title, $url);

        $forzar_html = function () {
            return 'text/html';
        };

        add_filter('wp_mail_content_type', $forzar_html);
        $enviado = wp_mail($email, $asunto, $mensaje);
        remove_filter('wp_mail_content_type', $forzar_html);

        if (!$enviado) {
            error_log(sprintf('EGC SCA: wp_mail() devolvió false al invitar a %s por el acuerdo #%d.', $email, $acuerdo_id));
        }

        return (bool) $enviado;
    }

    /**
     * Estilos en línea a propósito (los clientes de correo no cargan
     * hojas de estilo): es contenido de un correo, no de una vista.
     */
    private static function html($sitio, $titulo_acuerdo, $url)
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
<p style="margin:0 0 16px;"><strong>%3$s</strong></p>
<p style="margin:0 0 24px;">%4$s</p>
<p style="margin:0 0 24px;text-align:center;">
<a href="%5$s" style="display:inline-block;background-color:#f2683d;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;">%6$s</a>
</p>
<p style="margin:0;color:#6c757d;font-size:13px;">%7$s<br>
<a href="%5$s" style="color:#212529;">%5$s</a>
</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
            esc_html($sitio),
            esc_html__('Se te asignó el siguiente acuerdo:', 'egc'),
            esc_html($titulo_acuerdo),
            esc_html__('Para ver y dar seguimiento a tus acuerdos necesitás una cuenta. Solicitá tu ingreso con este mismo correo; cuando sea aprobado, el acuerdo quedará a tu nombre.', 'egc'),
            esc_url($url),
            esc_html__('Solicitar ingreso', 'egc'),
            esc_html__('Si el botón no funciona, copiá y pegá este enlace en tu navegador:', 'egc')
        );
    }
}
