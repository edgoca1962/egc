<?php

namespace EGC\Modules\Sca;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Acta\Acta;
use EGC\Modules\Sca\Acuerdo\Acuerdo;
use EGC\Modules\Sca\Comite\Comite;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — invariantes derivados de actas, acuerdos y comités
 * (módulo SCA).
 *
 * Qué mantiene, SIEMPRE, sin importar por dónde se escriba (formulario
 * del front-end, wp-admin, importador, WP-CLI):
 *
 * 1. Número consecutivo: un acta/acuerdo sin `_numero` recibe el
 *    siguiente de su comité (ver Consecutivo).
 * 2. Título y slug. Se guardan en `post_title` / `post_name`, no se
 *    calculan al mostrar, para que la búsqueda nativa de WordPress
 *    (`s`), el orden y los enlaces los vean:
 *      Acta/Minuta:  `Acta-001 2026-10-13 Junta Directiva`
 *      Acuerdo:      `Acuerdo-004 Acta-001 2026-10-13 Junta Directiva`
 *    La palabra es "Acta" si el comité tiene alcance total (Junta
 *    Directiva) y "Minuta" en los demás; la fecha es la de la reunión.
 * 3. Fecha de la reunión: los acuerdos de un acta tienen SIEMPRE el
 *    `post_date` de su acta. Se corrige el acuerdo al guardarlo y se
 *    propaga a todos sus acuerdos cuando cambia el acta.
 * 4. Un acuerdo "no ejecutado" no tiene la meta `_fecha_ejecucion` (en
 *    vez de tenerla vacía), y lo mismo `_fecha_compromiso`. Es lo que
 *    permite filtrar por estatus con EXISTS / NOT EXISTS (clase
 *    Estatus): una meta vacía SÍ existe para WP_Query.
 * 5. Cuando cambia el nombre de un comité, o su bandera de alcance
 *    total (que decide Acta/Minuta), se regeneran los títulos de todas
 *    sus actas y acuerdos.
 *
 * Por qué hooks `save_post_*` y no código en los handlers de los
 * formularios: los invariantes valen para toda vía de escritura. Un
 * handler que "se acuerde" de recalcular el título es un handler que
 * algún día se olvida. A cambio, esta clase no valida ni rechaza nada
 * (un hook posterior al guardado no puede): las validaciones de
 * entrada están en los handlers (paso 6).
 *
 * Reentrancia: para corregir el post esta clase llama a
 * wp_update_post(), que vuelve a disparar save_post. La bandera
 * `$ocupado` hace que esas llamadas internas no se reprocesen.
 *
 * Una clase para las tres cosas (número, título/fecha, regeneración
 * por comité) porque cambian juntas: son un solo concepto, "lo que se
 * deriva de la jerarquía comité → acta → acuerdo".
 */
class Sincronizacion
{
    use Singleton;

    /** Meta del comité con la última "palabra|nombre" usada en los títulos. */
    const META_FIRMA = '_titulos_firma';

    private $ocupado = false;

    private function __construct()
    {
        add_action('save_post_' . Comite::POST_TYPE, [$this, 'al_guardar_comite'], 10, 2);
        add_action('save_post_' . Acta::POST_TYPE, [$this, 'al_guardar_acta'], 10, 2);
        add_action('save_post_' . Acuerdo::POST_TYPE, [$this, 'al_guardar_acuerdo'], 10, 2);
    }

    public function al_guardar_comite($comite_id, $comite)
    {
        if (!$this->aplica($comite_id, $comite)) {
            return;
        }

        $firma = $this->firma($comite_id);
        if ($firma === get_post_meta($comite_id, self::META_FIRMA, true)) {
            return; // Ni el nombre ni la bandera cambiaron: nada que regenerar.
        }

        $this->ocupado = true;
        try {
            update_post_meta($comite_id, self::META_FIRMA, $firma);

            foreach (Consecutivo::actas_de($comite_id) as $acta_id) {
                $this->sincronizar_acta($acta_id);
            }
        } finally {
            $this->ocupado = false;
        }
    }

    public function al_guardar_acta($acta_id, $acta)
    {
        if (!$this->aplica($acta_id, $acta)) {
            return;
        }

        $this->ocupado = true;
        try {
            $this->sincronizar_acta($acta_id);
        } finally {
            $this->ocupado = false;
        }
    }

    public function al_guardar_acuerdo($acuerdo_id, $acuerdo)
    {
        if (!$this->aplica($acuerdo_id, $acuerdo)) {
            return;
        }

        $this->ocupado = true;
        try {
            $this->sincronizar_acuerdo($acuerdo_id);
        } finally {
            $this->ocupado = false;
        }
    }

    /**
     * Título de un acta/minuta: `Acta-001 2026-10-13 Junta Directiva`.
     * Función pura (sin WordPress) para poder probarla y reusarla.
     */
    public static function titulo_acta($palabra, $numero, $fecha, $comite)
    {
        return sprintf('%s-%03d %s %s', $palabra, $numero, $fecha, $comite);
    }

    /**
     * Título de un acuerdo:
     * `Acuerdo-004 Acta-001 2026-10-13 Junta Directiva`.
     */
    public static function titulo_acuerdo($palabra, $numero_acuerdo, $numero_acta, $fecha, $comite)
    {
        return sprintf('Acuerdo-%03d %s-%03d %s %s', $numero_acuerdo, $palabra, $numero_acta, $fecha, $comite);
    }

    /**
     * @return string "Acta" para el comité con alcance total, "Minuta" para los demás.
     *                Sin __(): es un dato que se guarda en la base, y no debe cambiar
     *                según el idioma del usuario que lo guarde.
     */
    public static function palabra($comite_id)
    {
        return Comite::alcance_total($comite_id) ? 'Acta' : 'Minuta';
    }

    // ------------------------------------------------------------------

    private function sincronizar_acta($acta_id)
    {
        $acta = get_post($acta_id);
        if (!$acta || $acta->post_type !== Acta::POST_TYPE) {
            return;
        }

        $comite_id = (int) $acta->post_parent;
        $nombre    = Comite::nombre($comite_id);
        if ($nombre === '') {
            return; // Sin comité no se puede formar el título ni numerar.
        }

        $numero = Consecutivo::asignar($acta_id);

        $titulo = self::titulo_acta(
            self::palabra($comite_id),
            $numero,
            $this->dia($acta->post_date),
            $nombre
        );

        $this->actualizar($acta, ['post_title' => $titulo, 'post_name' => sanitize_title($titulo)]);

        // La fecha de la reunión (y por tanto el título) puede haber cambiado.
        foreach ($this->acuerdos_de($acta_id) as $acuerdo_id) {
            $this->sincronizar_acuerdo($acuerdo_id);
        }
    }

    private function sincronizar_acuerdo($acuerdo_id)
    {
        $acuerdo = get_post($acuerdo_id);
        if (!$acuerdo || $acuerdo->post_type !== Acuerdo::POST_TYPE) {
            return;
        }

        // Invariante 4: una fecha vacía o inválida = sin meta, nunca meta vacía.
        foreach ([Acuerdo::META_FECHA_EJECUCION, Acuerdo::META_FECHA_COMPROMISO] as $meta_fecha) {
            if (metadata_exists('post', $acuerdo_id, $meta_fecha)
                && Fecha::sanitizar(get_post_meta($acuerdo_id, $meta_fecha, true)) === '') {
                delete_post_meta($acuerdo_id, $meta_fecha);
            }
        }

        $acta = get_post($acuerdo->post_parent);
        if (!$acta || $acta->post_type !== Acta::POST_TYPE) {
            return; // Sin acta no hay comité, fecha ni título que derivar.
        }

        $comite_id = (int) $acta->post_parent;
        $nombre    = Comite::nombre($comite_id);
        if ($nombre === '') {
            return;
        }

        $numero_acuerdo = Consecutivo::asignar($acuerdo_id);
        $numero_acta    = (int) get_post_meta($acta->ID, Acta::META_NUMERO, true);

        $titulo = self::titulo_acuerdo(
            self::palabra($comite_id),
            $numero_acuerdo,
            $numero_acta,
            $this->dia($acta->post_date),
            $nombre
        );

        // Invariante 3: la fecha del acuerdo es la de su acta. WordPress
        // exige post_date Y post_date_gmt (con solo el primero conserva
        // el viejo gmt) y edit_date para aceptar el cambio.
        $this->actualizar($acuerdo, [
            'post_title'    => $titulo,
            'post_name'     => sanitize_title($titulo),
            'post_date'     => $acta->post_date,
            'post_date_gmt' => $acta->post_date_gmt,
            'edit_date'     => true,
        ]);
    }

    /**
     * Escribe solo si algo cambia, para no disparar guardados inútiles.
     *
     * @param \WP_Post $post
     * @param array    $cambios
     */
    private function actualizar($post, array $cambios)
    {
        $datos = ['ID' => $post->ID];

        foreach ($cambios as $campo => $valor) {
            if ($campo === 'edit_date') {
                continue;
            }
            if ((string) $post->$campo !== (string) $valor) {
                $datos[$campo] = $valor;
            }
        }

        if (count($datos) === 1) {
            return;
        }

        if (isset($cambios['edit_date'])) {
            $datos['edit_date'] = true;
        }

        // wp_update_post() espera datos "con slashes", como los que llegan de $_POST.
        wp_update_post(wp_slash($datos));
    }

    /**
     * @return int[] Acuerdos del acta, en cualquier estado.
     */
    private function acuerdos_de($acta_id)
    {
        return get_posts([
            'post_type'          => Acuerdo::POST_TYPE,
            'post_parent'        => (int) $acta_id,
            'post_status'        => Consecutivo::ESTADOS,
            'posts_per_page'     => -1,
            'fields'             => 'ids',
            'no_found_rows'      => true,
            'suppress_filters'   => true,
            Acuerdo::SIN_ALCANCE => true,
        ]);
    }

    private function firma($comite_id)
    {
        return self::palabra($comite_id) . '|' . Comite::nombre($comite_id);
    }

    /** @return string AAAA-MM-DD de un post_date. */
    private function dia($post_date)
    {
        return substr((string) $post_date, 0, 10);
    }

    /**
     * Ignora lo que no es un guardado "de verdad": llamadas internas
     * (reentrancia), revisiones, autoguardados, borradores
     * automáticos y la papelera.
     */
    private function aplica($post_id, $post)
    {
        if ($this->ocupado) {
            return false;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return false;
        }

        return $post && !in_array($post->post_status, ['auto-draft', 'trash', 'inherit'], true);
    }
}
