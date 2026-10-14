<?php

namespace EGC\Modules\Sca;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — fechas "solo día" (AAAA-MM-DD) del módulo SCA.
 *
 * Vigencia de una membresía, fecha de compromiso y fecha de ejecución
 * de un acuerdo son fechas de calendario sin hora, guardadas como
 * texto AAAA-MM-DD en postmeta: ese formato ordena y se compara bien
 * como texto, así que un `meta_query` de WP_Query puede usarlas con
 * `type => DATE` sin conversiones.
 *
 * Existe como clase propia, y no como un método privado, porque ya la
 * necesitan dos recursos (Miembro y Acuerdo) — el criterio del
 * proyecto para extraer una abstracción es que exista el segundo
 * llamador. Sin estado y sin hooks: solo funciones puras, así que es
 * una clase de métodos estáticos y no un Singleton.
 *
 * "Hoy" se toma de la zona horaria del SITIO (wp_date), no de UTC: un
 * acuerdo cuyo compromiso es hoy a las 8 pm en Costa Rica ya es "mañana"
 * en UTC, y con gmdate() se marcaría vencido seis horas antes de tiempo.
 */
final class Fecha
{
    const FORMATO = 'Y-m-d';

    /**
     * Devuelve $valor tal cual si es una fecha real AAAA-MM-DD, o ''
     * si viene vacío o no lo es. Es el sanitize_callback de las metas
     * de fecha: una fecha inexistente (2026-02-31) o con otro formato
     * se descarta en vez de guardarse.
     *
     * @param mixed $valor
     * @return string
     */
    public static function sanitizar($valor)
    {
        if (!is_string($valor)) {
            return '';
        }

        $valor = trim($valor);
        if ($valor === '') {
            return '';
        }

        $fecha = \DateTime::createFromFormat('!' . self::FORMATO, $valor);
        if (!$fecha) {
            return '';
        }

        // createFromFormat() "corrige" fechas imposibles (31 de
        // febrero pasa a marzo) en vez de fallar: reformatear y
        // comparar es lo que detecta esa corrección.
        return $fecha->format(self::FORMATO) === $valor ? $valor : '';
    }

    /**
     * @return string Hoy (AAAA-MM-DD) en la zona horaria del sitio.
     */
    public static function hoy()
    {
        return wp_date(self::FORMATO);
    }

    /**
     * @param string $fecha AAAA-MM-DD; por omisión, hoy.
     * @return string Último día del mes de $fecha (AAAA-MM-DD).
     */
    public static function fin_de_mes($fecha = '')
    {
        $fecha = self::sanitizar($fecha) ?: self::hoy();

        return \DateTime::createFromFormat('!' . self::FORMATO, $fecha)->format('Y-m-t');
    }

    /**
     * @return int Días de calendario de $desde a $hasta (negativo si
     *             $hasta es anterior). 0 si alguna no es una fecha.
     */
    public static function dias($desde, $hasta)
    {
        $desde = self::sanitizar($desde);
        $hasta = self::sanitizar($hasta);

        if ($desde === '' || $hasta === '') {
            return 0;
        }

        $a = \DateTime::createFromFormat('!' . self::FORMATO, $desde);
        $b = \DateTime::createFromFormat('!' . self::FORMATO, $hasta);

        return (int) $a->diff($b)->format('%r%a');
    }

    /**
     * @return string La fecha en el formato de fecha configurado en el
     *                sitio (Ajustes > Generales); '' si no es una fecha.
     */
    public static function legible($fecha)
    {
        $fecha = self::sanitizar($fecha);

        if ($fecha === '') {
            return '';
        }

        $marca = \DateTime::createFromFormat('!' . self::FORMATO, $fecha, wp_timezone())->getTimestamp();

        return wp_date(get_option('date_format') ?: 'd/m/Y', $marca);
    }
}
