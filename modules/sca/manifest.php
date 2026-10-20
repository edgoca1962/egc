<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Manifest del módulo SCA (Sistema de Control de Acuerdos).
 *
 * Recursos (`post_types`), cada uno con `capability_type` propio y
 * `map_meta_cap => true` (ver su clase en modules/sca/<recurso>/),
 * así que sus capacidades quedan aisladas de las de cualquier otro
 * módulo — WordPress las computa a partir del plural declarado
 * (`edit_comites`, `edit_actas`, `edit_acuerdos`, `edit_miembros`,
 * `edit_puestos`), no de "post". Por eso las claves de abajo van con el
 * plural de cada recurso.
 *
 * - `comite`: el órgano (Junta Directiva, Comité Financiero…).
 * - `puesto`: Coordinador, Miembro, Presidente, Tesorero… Un puesto
 *   cuyo `post_parent` es un comité solo sirve en ese comité; sin padre
 *   sirve en todos.
 * - `miembro`: una MEMBRESÍA — persona + comité (`post_parent`) +
 *   puesto + vigencia —, no una persona. Quien está en tres comités
 *   tiene tres registros.
 * - `acta`: cuelga de un comité (`post_parent`). La palabra "Minuta"
 *   en lugar de "Acta" se decide por el comité, no por el recurso.
 * - `acuerdo`: cuelga de un acta (`post_parent`).
 *
 * Dos roles, y ninguno de visibilidad:
 *
 * - `sca_editor` es el administrador del módulo: todas las capacidades
 *   de los cinco recursos, "others" incluidas. Es el único que crea,
 *   edita, borra y marca como ejecutado (junto con el superusuario de
 *   WordPress, que entra por `manage_options`). También se le pueden
 *   asignar acuerdos, como a cualquier persona enrolada: un acuerdo sin
 *   asignar es el que no tiene autor (`post_author` = 0).
 * - `sca_lector` es el rol de todos los demás usuarios: solo `read`.
 *   Sin ninguna capacidad `edit_*` ni `delete_*`, así que WordPress
 *   mismo les niega cualquier escritura y la vista no muestra
 *   botones de acción.
 *
 * Qué acuerdos VE cada lector no se resuelve con roles: depende de sus
 * membresías vigentes (Junta Directiva ve todo, un coordinador ve su
 * comité, el resto solo lo asignado a él) y de vigencias que cambian
 * con el calendario. Eso lo resuelve el servicio de alcance del
 * módulo, sobre las consultas — las capacidades de rol son estáticas
 * y no pueden expresarlo.
 *
 * `assignable_roles` tiene una sola entrada (`comite`) a propósito:
 * los cinco recursos comparten un único alcance ("¿participa de
 * SCA?"), y una entrada por recurso obligaría a "Gestión de usuarios"
 * (core/UserManagement.php) a mostrar columnas repetidas para asignar
 * el mismo rol varias veces por usuario.
 */
return [
    'nombre' => __('Sistema de Control de Acuerdos', 'egc'),

    'sigla' => 'SCA',

    'post_types' => ['comite', 'acta', 'acuerdo', 'miembro', 'puesto'],

    'roles' => [
        'sca_editor' => [
            'name' => __('Editor de SCA', 'egc'),
            'capabilities' => [
                'read' => true,

                'edit_comites' => true,
                'edit_others_comites' => true,
                'edit_published_comites' => true,
                'edit_private_comites' => true,
                'publish_comites' => true,
                'read_private_comites' => true,
                'delete_comites' => true,
                'delete_others_comites' => true,
                'delete_published_comites' => true,
                'delete_private_comites' => true,

                'edit_actas' => true,
                'edit_others_actas' => true,
                'edit_published_actas' => true,
                'edit_private_actas' => true,
                'publish_actas' => true,
                'read_private_actas' => true,
                'delete_actas' => true,
                'delete_others_actas' => true,
                'delete_published_actas' => true,
                'delete_private_actas' => true,

                'edit_acuerdos' => true,
                'edit_others_acuerdos' => true,
                'edit_published_acuerdos' => true,
                'edit_private_acuerdos' => true,
                'publish_acuerdos' => true,
                'read_private_acuerdos' => true,
                'delete_acuerdos' => true,
                'delete_others_acuerdos' => true,
                'delete_published_acuerdos' => true,
                'delete_private_acuerdos' => true,

                'edit_miembros' => true,
                'edit_others_miembros' => true,
                'edit_published_miembros' => true,
                'edit_private_miembros' => true,
                'publish_miembros' => true,
                'read_private_miembros' => true,
                'delete_miembros' => true,
                'delete_others_miembros' => true,
                'delete_published_miembros' => true,
                'delete_private_miembros' => true,

                'edit_puestos' => true,
                'edit_others_puestos' => true,
                'edit_published_puestos' => true,
                'edit_private_puestos' => true,
                'publish_puestos' => true,
                'read_private_puestos' => true,
                'delete_puestos' => true,
                'delete_others_puestos' => true,
                'delete_published_puestos' => true,
                'delete_private_puestos' => true,
            ],
        ],
        'sca_lector' => [
            'name' => __('Lector de SCA', 'egc'),
            'capabilities' => [
                'read' => true,
            ],
        ],
    ],

    'assignable_roles' => [
        'comite' => ['sca_editor', 'sca_lector'],
    ],
];
