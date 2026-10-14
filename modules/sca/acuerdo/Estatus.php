<?php

namespace EGC\Modules\Sca\Acuerdo;

use EGC\Modules\Sca\Fecha;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — estatus de un acuerdo (módulo SCA).
 *
 * El estatus NO se guarda: se deriva de dos fechas del acuerdo contra
 * "hoy". Guardarlo obligaría a un proceso diario que lo actualice (y
 * quedaría desfasado si el proceso no corre); derivarlo hace que
 * "vence mañana" y "venció ayer" sean ciertos sin que nadie haga nada.
 *
 *   Ejecutado   — tiene `_fecha_ejecucion` (solo la marca el
 *                 administrador). Gana sobre todo lo demás: un acuerdo
 *                 cumplido tarde no vuelve a aparecer como vencido.
 *   Vencido     — no ejecutado y hoy es MAYOR que la fecha de
 *                 compromiso (el día del compromiso aún no está vencido).
 *   Por vencer  — no ejecutado, hoy <= compromiso <= último día de este
 *                 mes ("por vencer este mes").
 *   En proceso  — no ejecutado y el compromiso es de un mes posterior
 *                 (o, por un dato incompleto, no tiene compromiso).
 *
 * Dos formas de preguntarlo, que DEBEN coincidir y se prueban juntas:
 *
 *  - de($id): el estatus de UN acuerdo, para pintar su tarjeta.
 *  - meta_query($estatus): la condición equivalente para WP_Query, para
 *    listar y contar por estatus. Contar con una consulta nativa
 *    (found_posts) evita cargar todos los acuerdos para clasificarlos
 *    en PHP.
 *
 * Para que meta_query() funcione `_fecha_ejecucion` debe EXISTIR solo
 * cuando hay fecha (una meta vacía existe para WP_Query): lo garantiza
 * Sincronizacion en cada guardado.
 *
 * "Hoy" es el de la zona horaria del sitio (Fecha::hoy()). Se puede
 * pasar uno explícito para probar o para consultar "al día X".
 */
final class Estatus
{
    const EJECUTADO  = 'ejecutado';
    const VENCIDO    = 'vencido';
    const POR_VENCER = 'por-vencer';
    const EN_PROCESO = 'en-proceso';

    /**
     * @return array<string,string> slug => etiqueta, en el orden en que
     *                              se muestran en la barra lateral.
     */
    public static function todos()
    {
        return [
            self::VENCIDO    => __('Vencidos', 'egc'),
            self::POR_VENCER => __('Por vencer este mes', 'egc'),
            self::EN_PROCESO => __('En proceso', 'egc'),
            self::EJECUTADO  => __('Ejecutados', 'egc'),
        ];
    }

    /**
     * @return string Etiqueta en singular para la tarjeta de un acuerdo.
     */
    public static function etiqueta($estatus)
    {
        $etiquetas = [
            self::VENCIDO    => __('Vencido', 'egc'),
            self::POR_VENCER => __('Por vencer', 'egc'),
            self::EN_PROCESO => __('En proceso', 'egc'),
            self::EJECUTADO  => __('Ejecutado', 'egc'),
        ];

        return $etiquetas[$estatus] ?? '';
    }

    public static function valido($estatus)
    {
        return is_string($estatus) && isset(self::todos()[$estatus]);
    }

    /**
     * @param string $hoy AAAA-MM-DD; por omisión, hoy en la zona del sitio.
     * @return string Uno de los slugs de esta clase.
     */
    public static function de($acuerdo_id, $hoy = '')
    {
        $hoy = $hoy ?: Fecha::hoy();

        if (Fecha::sanitizar(get_post_meta($acuerdo_id, Acuerdo::META_FECHA_EJECUCION, true)) !== '') {
            return self::EJECUTADO;
        }

        $compromiso = Fecha::sanitizar(get_post_meta($acuerdo_id, Acuerdo::META_FECHA_COMPROMISO, true));

        if ($compromiso === '') {
            return self::EN_PROCESO;
        }

        if ($compromiso < $hoy) {
            return self::VENCIDO;
        }

        return $compromiso <= Fecha::fin_de_mes($hoy) ? self::POR_VENCER : self::EN_PROCESO;
    }

    /**
     * @param string $hoy AAAA-MM-DD; por omisión, hoy en la zona del sitio.
     * @return array meta_query de WP_Query ([] si el estatus no existe).
     */
    public static function meta_query($estatus, $hoy = '')
    {
        $hoy = $hoy ?: Fecha::hoy();
        $fin = Fecha::fin_de_mes($hoy);

        $ejecutado    = ['key' => Acuerdo::META_FECHA_EJECUCION, 'compare' => 'EXISTS'];
        $no_ejecutado = ['key' => Acuerdo::META_FECHA_EJECUCION, 'compare' => 'NOT EXISTS'];
        $compromiso   = function ($compare, $value) {
            return [
                'key'     => Acuerdo::META_FECHA_COMPROMISO,
                'value'   => $value,
                'compare' => $compare,
                'type'    => 'DATE',
            ];
        };

        switch ($estatus) {
            case self::EJECUTADO:
                return [$ejecutado];

            case self::VENCIDO:
                return ['relation' => 'AND', $no_ejecutado, $compromiso('<', $hoy)];

            case self::POR_VENCER:
                return ['relation' => 'AND', $no_ejecutado, $compromiso('BETWEEN', [$hoy, $fin])];

            case self::EN_PROCESO:
                return [
                    'relation' => 'AND',
                    $no_ejecutado,
                    [
                        'relation' => 'OR',
                        $compromiso('>', $fin),
                        ['key' => Acuerdo::META_FECHA_COMPROMISO, 'compare' => 'NOT EXISTS'],
                    ],
                ];
        }

        return [];
    }
}
