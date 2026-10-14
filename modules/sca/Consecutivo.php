<?php

namespace EGC\Modules\Sca;

use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Acuerdo\Acuerdo;
use EGC\Modules\Sca\Comite\Comite;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — numeración consecutiva por comité (módulo SCA).
 *
 * Tanto las actas como los acuerdos se numeran DENTRO de su comité: el
 * acuerdo 12 de Finanzas es el duodécimo de todo el comité, no del acta
 * que lo contiene. El comité guarda el PRÓXIMO número a usar de cada
 * tipo (`_proximo_acta`, `_proximo_acuerdo`, ver Comite).
 *
 * Por qué no un contador global ni calcular "máximo + 1" en cada
 * guardado: tras migrar datos históricos el administrador fija a mano
 * el siguiente número de cada comité, y un contador guardado por
 * comité es justo lo que le permite hacerlo. Pero como un número mal
 * configurado (o una migración que trajo números altos) no puede
 * generar duplicados, `siguiente()` salta cualquier número que ya esté
 * en uso en el comité.
 *
 * Los números los pone el sistema; ningún formulario los recibe. Solo
 * una migración de datos los fija a mano (guardándolos en `_numero`
 * antes de que dispare save_post), y esta clase respeta un `_numero`
 * ya presente.
 *
 * Clase de métodos estáticos y no Singleton: sin estado ni hooks, solo
 * la regla de numeración. Quien la dispara es Sincronizacion; vive
 * aparte porque cambia por otra razón (la política de numeración) y
 * porque la usarán también la validación del comité (número ya usado)
 * y la futura migración de datos.
 *
 * Limitación conocida: dos guardados simultáneos del mismo comité
 * podrían leer el mismo contador. Con un solo editor por módulo no es
 * un caso real; si dejara de serlo, el candidato es un bloqueo por
 * comité alrededor de asignar().
 */
final class Consecutivo
{
    const ACTA    = Acta::POST_TYPE;
    const ACUERDO = Acuerdo::POST_TYPE;

    /** Estados que cuentan como "número en uso" (la papelera también: restaurar no debe duplicar). */
    const ESTADOS = ['publish', 'future', 'draft', 'pending', 'private', 'trash'];

    /**
     * @param string $tipo self::ACTA o self::ACUERDO
     * @return int El número que recibiría el siguiente registro del
     *             comité: el contador, o el primero libre a partir de él.
     */
    public static function siguiente($comite_id, $tipo)
    {
        $numero = max(1, (int) get_post_meta($comite_id, self::meta_contador($tipo), true));

        while (self::existe($comite_id, $tipo, $numero)) {
            $numero++;
        }

        return $numero;
    }

    /**
     * Le pone `_numero` al acta/acuerdo si todavía no lo tiene y
     * adelanta el contador de su comité.
     *
     * @return int El número del registro (0 si no se pudo determinar
     *             su comité, caso en que no se asigna nada).
     */
    public static function asignar($post_id)
    {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, [self::ACTA, self::ACUERDO], true)) {
            return 0;
        }

        $actual = (int) get_post_meta($post_id, '_numero', true);
        if ($actual > 0) {
            return $actual;
        }

        $comite_id = self::comite_de($post);
        if (!$comite_id) {
            return 0;
        }

        $numero = self::siguiente($comite_id, $post->post_type);

        update_post_meta($post_id, '_numero', $numero);
        update_post_meta($comite_id, self::meta_contador($post->post_type), $numero + 1);

        return $numero;
    }

    /**
     * @param int $excluir_id Registro a ignorar (el que se está editando).
     * @return bool Si ya hay un acta/acuerdo con ese número en el comité.
     */
    public static function existe($comite_id, $tipo, $numero, $excluir_id = 0)
    {
        $consulta = [
            'post_status'      => self::ESTADOS,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            Acuerdo::SIN_ALCANCE => true,
            'meta_query'       => [[
                'key'   => '_numero',
                'value' => (int) $numero,
                'type'  => 'NUMERIC',
            ]],
        ];

        if ($excluir_id) {
            $consulta['post__not_in'] = [(int) $excluir_id];
        }

        if ($tipo === self::ACTA) {
            $consulta['post_type']   = Acta::POST_TYPE;
            $consulta['post_parent'] = (int) $comite_id;

            return (bool) get_posts($consulta);
        }

        // Acuerdo: el comité está dos saltos arriba (acuerdo → acta →
        // comité), así que se buscan entre los acuerdos de sus actas.
        $actas = self::actas_de($comite_id);
        if (!$actas) {
            return false; // post_parent__in vacío se ignoraría y devolvería TODO.
        }

        $consulta['post_type']       = Acuerdo::POST_TYPE;
        $consulta['post_parent__in'] = $actas;

        return (bool) get_posts($consulta);
    }

    /**
     * @return int[] IDs de las actas del comité, en cualquier estado.
     */
    public static function actas_de($comite_id)
    {
        return get_posts([
            'post_type'        => Acta::POST_TYPE,
            'post_parent'      => (int) $comite_id,
            'post_status'      => self::ESTADOS,
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
    }

    private static function comite_de($post)
    {
        return $post->post_type === self::ACTA
            ? (int) $post->post_parent
            : Acuerdo::comite_id($post->ID);
    }

    private static function meta_contador($tipo)
    {
        return $tipo === self::ACTA ? Comite::META_PROXIMO_ACTA : Comite::META_PROXIMO_ACUERDO;
    }
}
