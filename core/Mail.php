<?php

namespace EGC\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Configuración del correo saliente del sitio, para cualquier
 * wp_mail() (incluido el "olvidé mi contraseña" nativo), no solo el de
 * ActivationNotice — por eso vive acá y no en esa clase.
 *
 * Qué resuelve WordPress y qué no (PRINCIPIO RECTOR): WordPress manda
 * con el `mail()` de PHP y no trae ninguna pantalla ni ajuste para
 * SMTP; lo que sí trae es el punto de extensión exacto, el hook
 * `phpmailer_init`, que es el que usan los plugins de SMTP. Esta clase
 * es ese mismo enganche, sin plugin: así el tema sigue siendo
 * desplegable copiando carpetas y cada instalación configura su
 * correo sin tocar código.
 *
 * Por qué hace falta: en hosting compartido (p. ej. Hostinger) el
 * `mail()` suele estar limitado, y un remitente sin buzón real ni
 * SPF/DKIM (el `no-reply@{host}` por defecto, que en un subdominio ni
 * siquiera existe como correo) se descarta o cae en spam. Con SMTP
 * autenticado contra un buzón real, el correo sale firmado por el
 * proveedor.
 *
 * Se configura con constantes en `wp-config.php` (no en el tema ni en
 * la base de datos: las credenciales no deben viajar con el código ni
 * quedar en un dump que se comparte):
 *
 *   define('EGC_SMTP_HOST',   'smtp.hostinger.com');
 *   define('EGC_SMTP_PORT',   465);                      // 465 = SSL, 587 = TLS
 *   define('EGC_SMTP_USER',   'no-reply@midominio.org'); // buzón real
 *   define('EGC_SMTP_PASS',   '********');
 *   define('EGC_SMTP_SECURE', 'ssl');                    // opcional: 'ssl' | 'tls'
 *   define('EGC_MAIL_FROM',   'no-reply@midominio.org'); // opcional
 *
 * Sin `EGC_SMTP_HOST` (p. ej. en desarrollo local) no se toca el
 * transporte: WordPress sigue mandando como siempre. `EGC_SMTP_SECURE`
 * si falta se deduce del puerto. El remitente es `EGC_MAIL_FROM` si
 * está; si no, con SMTP activo el propio usuario SMTP (los proveedores
 * rechazan un From que no pertenece al buzón autenticado); y sin SMTP,
 * el `no-reply@{dominio}` de siempre.
 */
class Mail
{
    use Singleton;

    private function __construct()
    {
        add_filter('wp_mail_from', [$this, 'from_address']);
        add_filter('wp_mail_from_name', [$this, 'from_name']);
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        add_action('wp_mail_failed', [$this, 'log_failure']);
    }

    public function from_address($original)
    {
        if (defined('EGC_MAIL_FROM') && is_email(EGC_MAIL_FROM)) {
            return sanitize_email(EGC_MAIL_FROM);
        }

        if ($this->smtp_enabled() && defined('EGC_SMTP_USER') && is_email(EGC_SMTP_USER)) {
            return sanitize_email(EGC_SMTP_USER);
        }

        $domain = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';

        // Sin "www.": más prolijo, y evita que algunos servidores de
        // correo desconfíen de un From con ese subdominio.
        $domain = preg_replace('/^www\./', '', $domain);

        return 'no-reply@' . $domain;
    }

    public function from_name($original)
    {
        return get_bloginfo('name');
    }

    /**
     * Cambia el transporte de este envío a SMTP autenticado. $phpmailer
     * llega por referencia de objeto (así lo entrega WordPress), no hace
     * falta devolverlo.
     *
     * La contraseña solo se pasa a PHPMailer; nunca se escribe en el
     * registro de errores (ver log_failure()).
     */
    public function configure_smtp($phpmailer)
    {
        if (!$this->smtp_enabled()) {
            return;
        }

        $port   = defined('EGC_SMTP_PORT') ? (int) EGC_SMTP_PORT : 587;
        $secure = defined('EGC_SMTP_SECURE') ? strtolower((string) EGC_SMTP_SECURE) : ($port === 465 ? 'ssl' : 'tls');

        $phpmailer->isSMTP();
        $phpmailer->Host       = EGC_SMTP_HOST;
        $phpmailer->Port       = $port;
        $phpmailer->SMTPSecure = in_array($secure, ['ssl', 'tls'], true) ? $secure : '';
        $phpmailer->SMTPAuth   = defined('EGC_SMTP_USER') && EGC_SMTP_USER !== '';

        if ($phpmailer->SMTPAuth) {
            $phpmailer->Username = EGC_SMTP_USER;
            $phpmailer->Password = defined('EGC_SMTP_PASS') ? EGC_SMTP_PASS : '';
        }
    }

    /**
     * wp_mail() solo devuelve false; el motivo real (credenciales
     * rechazadas, puerto bloqueado, remitente no permitido…) viaja en
     * este hook. Se deja en el registro de errores para poder
     * diagnosticar en el servidor sin plugins de logging.
     */
    public function log_failure($error)
    {
        error_log('EGC: fallo al enviar correo — ' . $error->get_error_message());
    }

    private function smtp_enabled()
    {
        return defined('EGC_SMTP_HOST') && EGC_SMTP_HOST !== '';
    }
}
