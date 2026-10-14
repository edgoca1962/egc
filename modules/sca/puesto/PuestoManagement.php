<?php

namespace EGC\Modules\Sca\Puesto;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Comite\Comite;
use EGC\Modules\Sca\RecursoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — CRUD de Puesto en el front-end (módulo SCA).
 *
 * Campos: nombre, comité al que pertenece (opcional: sin comité el
 * puesto sirve en todos) y la bandera "coordina" (quien lo ocupa ve
 * todos los acuerdos de su comité).
 */
class PuestoManagement extends RecursoManagement
{
    use Singleton;

    private function __construct()
    {
        parent::__construct();
    }

    protected function post_type()
    {
        return Puesto::POST_TYPE;
    }

    protected function slug_editar()
    {
        return 'puesto-editar';
    }

    protected function titulo_pagina()
    {
        return __('Puesto', 'egc');
    }

    public function titulo_formulario($editando)
    {
        return $editando ? __('Editar puesto', 'egc') : __('Nuevo puesto', 'egc');
    }

    protected function campos_formulario()
    {
        return ['post_title', 'comite_id', 'coordina'];
    }

    protected function mensajes()
    {
        return [
            'nombre_vacio'   => __('El nombre del puesto no puede quedar vacío.', 'egc'),
            'comite_invalido' => __('Seleccioná un comité válido.', 'egc'),
        ];
    }

    protected function valores_de($post)
    {
        return [
            'post_title' => $post->post_title,
            'comite_id'  => (string) (int) $post->post_parent,
            'coordina'   => Puesto::coordina($post->ID) ? '1' : '',
        ];
    }

    protected function opciones()
    {
        return ['comites' => $this->opciones_comites()];
    }

    protected function leer($post_id)
    {
        $nombre = $this->texto('post_title');
        if ($nombre === '') {
            return 'nombre_vacio';
        }

        $comite_id = $this->entero('comite_id');
        if ($comite_id && get_post_type($comite_id) !== Comite::POST_TYPE) {
            return 'comite_invalido';
        }

        return [
            'args' => ['post_title' => $nombre, 'post_parent' => $comite_id],
            'meta' => [Puesto::META_COORDINA => $this->marcado('coordina') ? 1 : 0],
        ];
    }
}
