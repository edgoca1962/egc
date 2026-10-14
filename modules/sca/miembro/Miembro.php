<?php

namespace EGC\Modules\Sca\Miembro;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Fecha;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Miembro (módulo SCA).
 *
 * Un Miembro es una MEMBRESÍA, no una persona: "esta persona ocupa
 * este puesto en este comité durante este período". Quien está en
 * Junta Directiva, es coordinador de otro comité y miembro de un
 * tercero tiene tres registros. La persona sigue siendo un usuario
 * nativo de WordPress; esta clase solo registra la relación.
 *
 * - Comité: `post_parent` nativo.
 * - `_puesto_id`: el Puesto (otro CPT) que ocupa.
 * - `_user_id`: el usuario de WordPress, 0 mientras la persona todavía
 *   no se enrola (ver `_email`).
 * - `_email`: correo de la persona. Es lo que permite completar
 *   `_user_id` cuando se enrole y active su cuenta, sin que el Core
 *   sepa que SCA existe (paso 5, vía el hook egc_user_status_changed).
 * - `_desde` / `_hasta`: vigencia AAAA-MM-DD. Solo se exige para los
 *   puestos de un comité con `_alcance_total` (Junta Directiva); en el
 *   resto quedan vacías y la membresía no vence. La regla se aplica en
 *   el handler de guardado (paso 6) y en el servicio de alcance
 *   (paso 2): register_post_meta() solo normaliza, no valida.
 *
 * No es público en el front-end: lo administra el editor del módulo y
 * el superusuario.
 */
class Miembro
{
    use Singleton;

    const POST_TYPE = 'miembro';

    const META_USUARIO = '_user_id';
    const META_EMAIL   = '_email';
    const META_PUESTO  = '_puesto_id';
    const META_DESDE   = '_desde';
    const META_HASTA   = '_hasta';

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_meta']);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Miembros', 'egc'),
                'singular_name'      => __('Miembro', 'egc'),
                'add_new_item'       => __('Agregar miembro', 'egc'),
                'edit_item'          => __('Editar miembro', 'egc'),
                'new_item'           => __('Nuevo miembro', 'egc'),
                'view_item'          => __('Ver miembro', 'egc'),
                'search_items'       => __('Buscar miembros', 'egc'),
                'not_found'          => __('No se encontraron miembros', 'egc'),
                'not_found_in_trash' => __('No hay miembros en la papelera', 'egc'),
            ],
            'public'          => false,
            'show_ui'         => true,
            'has_archive'     => false,
            'rewrite'         => false,
            'query_var'       => false,
            'supports'        => ['title'],
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }

    public function register_post_meta()
    {
        $auth_callback = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };

        foreach ([self::META_USUARIO, self::META_PUESTO] as $meta_key) {
            register_post_meta(self::POST_TYPE, $meta_key, [
                'type'              => 'integer',
                'single'            => true,
                'default'           => 0,
                'show_in_rest'      => false,
                'sanitize_callback' => 'absint',
                'auth_callback'     => $auth_callback,
            ]);
        }

        register_post_meta(self::POST_TYPE, self::META_EMAIL, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_email',
            'auth_callback'     => $auth_callback,
        ]);

        foreach ([self::META_DESDE, self::META_HASTA] as $meta_key) {
            register_post_meta(self::POST_TYPE, $meta_key, [
                'type'              => 'string',
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => false,
                'sanitize_callback' => [Fecha::class, 'sanitizar'],
                'auth_callback'     => $auth_callback,
            ]);
        }
    }

    /**
     * Vigencia ESTRICTA: exige las dos fechas y desde <= hoy <= hasta.
     * Faltar una (o las dos) equivale a no vigente: una membresía de
     * Junta Directiva sin fechas no puede dar acceso por omisión. Las
     * cadenas AAAA-MM-DD se comparan como texto, que ordena igual que
     * el calendario.
     *
     * @param string $hoy AAAA-MM-DD; por omisión, hoy en la zona del sitio.
     */
    public static function vigente($miembro_id, $hoy = '')
    {
        $desde = Fecha::sanitizar(get_post_meta($miembro_id, self::META_DESDE, true));
        $hasta = Fecha::sanitizar(get_post_meta($miembro_id, self::META_HASTA, true));
        $hoy   = $hoy ?: Fecha::hoy();

        return $desde !== '' && $hasta !== '' && $desde <= $hoy && $hoy <= $hasta;
    }
}
