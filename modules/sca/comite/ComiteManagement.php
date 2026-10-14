<?php

namespace EGC\Modules\Sca\Comite;

use EGC\Core\Singleton;
use EGC\Modules\Sca\RecursoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — CRUD de Comité en el front-end (módulo SCA). La
 * mecánica común está en RecursoManagement; acá solo lo propio del
 * comité: qué campos tiene y cómo se validan.
 *
 * Campos: nombre, bandera de alcance total (Junta Directiva) y los dos
 * consecutivos "próximo número" — los únicos números que el
 * administrador toca a mano, tras una migración de datos históricos.
 * Bajar un consecutivo por debajo de uno ya usado no genera duplicados
 * (Consecutivo salta los números ocupados).
 *
 * El nombre es único (sin distinguir mayúsculas): forma parte del
 * título de todas sus actas y acuerdos, y dos comités con el mismo
 * nombre darían títulos indistinguibles.
 */
class ComiteManagement extends RecursoManagement
{
    use Singleton;

    private function __construct()
    {
        parent::__construct();
    }

    protected function post_type()
    {
        return Comite::POST_TYPE;
    }

    protected function slug_editar()
    {
        return 'comite-editar';
    }

    protected function titulo_pagina()
    {
        return __('Comité', 'egc');
    }

    public function titulo_formulario($editando)
    {
        return $editando ? __('Editar comité', 'egc') : __('Nuevo comité', 'egc');
    }

    protected function campos_formulario()
    {
        return ['post_title', 'alcance_total', 'proximo_acta', 'proximo_acuerdo'];
    }

    protected function mensajes()
    {
        return [
            'nombre_vacio'    => __('El nombre del comité no puede quedar vacío.', 'egc'),
            'nombre_repetido' => __('Ya existe un comité con ese nombre.', 'egc'),
        ];
    }

    protected function valores_de($post)
    {
        return [
            'post_title'      => $post->post_title,
            'alcance_total'   => Comite::alcance_total($post->ID) ? '1' : '',
            'proximo_acta'    => (string) max(1, (int) get_post_meta($post->ID, Comite::META_PROXIMO_ACTA, true)),
            'proximo_acuerdo' => (string) max(1, (int) get_post_meta($post->ID, Comite::META_PROXIMO_ACUERDO, true)),
        ];
    }

    protected function leer($post_id)
    {
        $nombre = $this->texto('post_title');

        if ($nombre === '') {
            return 'nombre_vacio';
        }

        if ($this->nombre_repetido($nombre, $post_id)) {
            return 'nombre_repetido';
        }

        // Un consecutivo en blanco conserva el actual (o 1 en un alta).
        $proximo_acta = $this->entero('proximo_acta')
            ?: max(1, (int) ($post_id ? get_post_meta($post_id, Comite::META_PROXIMO_ACTA, true) : 1));
        $proximo_acuerdo = $this->entero('proximo_acuerdo')
            ?: max(1, (int) ($post_id ? get_post_meta($post_id, Comite::META_PROXIMO_ACUERDO, true) : 1));

        return [
            'args' => ['post_title' => $nombre],
            'meta' => [
                Comite::META_ALCANCE_TOTAL   => $this->marcado('alcance_total') ? 1 : 0,
                Comite::META_PROXIMO_ACTA    => $proximo_acta,
                Comite::META_PROXIMO_ACUERDO => $proximo_acuerdo,
            ],
        ];
    }

    /**
     * Comparación en PHP y no con una consulta por título: son pocos
     * comités, y WP_Query no ofrece comparación exacta sin distinguir
     * mayúsculas en todas las versiones.
     */
    private function nombre_repetido($nombre, $excluir_id)
    {
        foreach ($this->opciones_comites() as $id => $existente) {
            if ($id !== (int) $excluir_id && mb_strtolower($existente) === mb_strtolower($nombre)) {
                return true;
            }
        }

        return false;
    }
}
