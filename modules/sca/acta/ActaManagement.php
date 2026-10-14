<?php

namespace EGC\Modules\Sca\Acta;

use EGC\Core\Singleton;
use EGC\Modules\Sca\Comite\Comite;
use EGC\Modules\Sca\Fecha;
use EGC\Modules\Sca\RecursoManagement;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Lógica — CRUD de Acta/Minuta en el front-end (módulo SCA).
 *
 * El administrador solo ingresa tres cosas: el comité (al crear; luego
 * es inmutable, porque de él dependen el número y el título), la fecha
 * de la reunión (obligatoria, real y no futura) y el contenido
 * (wp_editor → post_content, que conserva la estructura de bloques).
 * El número y el título los pone Sincronizacion al guardar.
 *
 * La fecha de la reunión es el `post_date` nativo del acta. WordPress
 * exige pasar post_date Y post_date_gmt (y `edit_date`) para que un
 * cambio de fecha en un post ya publicado se respete; y no admite
 * fechas futuras en un post publicado (lo convertiría en "programado"),
 * por eso la validación "no futura".
 */
class ActaManagement extends RecursoManagement
{
    use Singleton;

    private function __construct()
    {
        parent::__construct();
    }

    protected function post_type()
    {
        return Acta::POST_TYPE;
    }

    protected function slug_editar()
    {
        return 'acta-editar';
    }

    protected function titulo_pagina()
    {
        return __('Acta', 'egc');
    }

    public function titulo_formulario($editando)
    {
        return $editando ? __('Editar acta o minuta', 'egc') : __('Nueva acta o minuta', 'egc');
    }

    protected function campos_formulario()
    {
        return ['comite_id', 'fecha_reunion', 'post_content'];
    }

    protected function mensajes()
    {
        return [
            'comite_invalido' => __('Seleccioná un comité válido.', 'egc'),
            'fecha_requerida' => __('La fecha de la reunión es obligatoria.', 'egc'),
            'fecha_futura'    => __('La fecha de la reunión no puede ser futura.', 'egc'),
        ];
    }

    protected function valores_nuevo()
    {
        return [
            'comite_id'     => isset($_GET['comite_id']) ? (string) absint($_GET['comite_id']) : '',
            'fecha_reunion' => '',
            'post_content'  => '',
            'comite_nombre' => '',
            'titulo'        => '',
        ];
    }

    protected function valores_de($post)
    {
        return [
            'comite_id'     => (string) (int) $post->post_parent,
            'comite_nombre' => Comite::nombre($post->post_parent),
            'fecha_reunion' => substr($post->post_date, 0, 10),
            'post_content'  => $post->post_content,
            'titulo'        => $post->post_title,
        ];
    }

    protected function opciones()
    {
        return ['comites' => $this->opciones_comites()];
    }

    protected function leer($post_id)
    {
        if ($post_id) {
            $comite_id = (int) get_post($post_id)->post_parent; // inmutable
        } else {
            $comite_id = $this->entero('comite_id');
            if (!$comite_id || get_post_type($comite_id) !== Comite::POST_TYPE) {
                return 'comite_invalido';
            }
        }

        $fecha = $this->fecha('fecha_reunion');
        if ($fecha === false) {
            return 'fecha_invalida';
        }
        if ($fecha === '') {
            return 'fecha_requerida';
        }
        if ($fecha > Fecha::hoy()) {
            return 'fecha_futura';
        }

        $local = $fecha . ' 00:00:00';

        $args = [
            'post_content'  => wp_kses_post(wp_unslash(isset($_POST['post_content']) ? $_POST['post_content'] : '')),
            'post_date'     => $local,
            'post_date_gmt' => get_gmt_from_date($local),
            'edit_date'     => true,
        ];

        if (!$post_id) {
            // Título provisional: WordPress no acepta un post sin título
            // ni contenido; Sincronizacion lo reemplaza al guardar.
            $args['post_title']  = __('Acta nueva', 'egc');
            $args['post_parent'] = $comite_id;
        }

        return ['args' => $args, 'meta' => []];
    }
}
