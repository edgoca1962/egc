<?php

namespace EGC\Modules\Sgf\Billetera;

use EGC\Core\Singleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — registro de recursos del CPT Billetera.
 *
 * A diferencia de Blog (que reutiliza `post` nativo), acá sí hace
 * falta `register_post_type()` propio: `capability_type` aislado y
 * `map_meta_cap => true`, tal como pide AUTORIZACIÓN, para que las
 * capacidades de Billetera no se mezclen con las de ningún otro CPT.
 *
 * Esta clase se limita a declarar el recurso ante WordPress (el CPT y
 * sus dos postmeta). La consulta scoped por usuario, los guards de
 * acceso, los handlers de admin-post.php y el `view_state_*()` para
 * las vistas van en una clase aparte (`BilleteraManagement`, paso 4) —
 * son una razón de cambio distinta: esta clase cambia si cambia la
 * FORMA del recurso (sus labels, sus campos), la otra cambia si
 * cambia el FLUJO de uso (quién ve qué, qué botones hay).
 */
class Billetera
{
    use Singleton;

    const POST_TYPE = 'billetera';

    /**
     * Valores válidos de `_moneda`. Enteros, no strings, porque así
     * los pidió el requerimiento ("_moneda es un campo numérico").
     */
    const MONEDA_LOCAL = 1;
    const MONEDA_EXTRANJERA = 2;

    private function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_meta']);
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Billeteras', 'egc'),
                'singular_name'      => __('Billetera', 'egc'),
                'add_new_item'       => __('Agregar billetera', 'egc'),
                'edit_item'          => __('Editar billetera', 'egc'),
                'new_item'           => __('Nueva billetera', 'egc'),
                'view_item'          => __('Ver billetera', 'egc'),
                'search_items'       => __('Buscar billeteras', 'egc'),
                'not_found'          => __('No se encontraron billeteras', 'egc'),
                'not_found_in_trash' => __('No hay billeteras en la papelera', 'egc'),
            ],
            'public'          => true,
            'has_archive'     => true,
            'rewrite'         => ['slug' => self::POST_TYPE],
            'supports'        => ['title'],
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ]);
    }

    /**
     * `_saldo`: numérico con signo — positivo o negativo son ambos
     * válidos (ver la regla de signos: una cuenta bancaria sobregirada
     * o una tarjeta de crédito son saldos negativos legítimos), así
     * que el sanitize_callback normaliza a float sin forzar unsigned.
     *
     * `_moneda`: entero, 1 = Moneda Local, 2 = Moneda Extranjera.
     * `register_post_meta()` no tiene forma de RECHAZAR un valor fuera
     * de ese rango (su sanitize_callback normaliza, no valida) — la
     * validación real de "tiene que ser 1 o 2" va en
     * BilleteraManagement::handle_save() (paso 4), mismo lugar donde
     * Blog valida el título vacío.
     *
     * `auth_callback` en ambos: usa `edit_post` sobre el post_id del
     * meta, para que nadie pueda escribir el saldo o la moneda de una
     * billetera ajena por fuera del formulario (defensa en profundidad
     * — el handler de guardado ya lo va a revisar también).
     */
    public function register_post_meta()
    {
        $auth_callback = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };

        register_post_meta(self::POST_TYPE, '_saldo', [
            'type'              => 'number',
            'single'            => true,
            'default'           => 0,
            'show_in_rest'      => false,
            'sanitize_callback' => function ($meta_value) {
                return round((float) $meta_value, 2);
            },
            'auth_callback' => $auth_callback,
        ]);

        register_post_meta(self::POST_TYPE, '_moneda', [
            'type'              => 'integer',
            'single'            => true,
            'default'           => self::MONEDA_LOCAL,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
        ]);
    }
}
