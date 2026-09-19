<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Pinta, dentro del dropdown del avatar, un grupo de <li> anidados por
 * módulo — la misma forma que devuelven UserScope::modulo_links() y
 * UserScope::autor_links(), así que este único partial sirve para los
 * dos bloques (Core lo incluye una vez por bloque, pasándole el
 * arreglo que corresponda en $grupos).
 *
 * Mismo lenguaje visual que ya usa BootstrapNavWalker para los
 * submenús: "dropdown dropstart" (abre hacia la izquierda, porque el
 * dropdown del avatar ya está contra el borde derecho) y
 * data-bs-auto-close="outside" para que un clic en el toggle del
 * módulo no cierre todo el dropdown del avatar.
 *
 * Sin lógica de autorización acá: quien arma $grupos (UserScope) ya
 * decidió qué módulos y qué CPT corresponden a este usuario — esta
 * vista solo pinta lo que recibió.
 *
 * @var array<int, array{modulo: string, items: array<int, array{label: string, url: string}>}> $grupos
 */
foreach ($grupos as $grupo) :
    ?>
    <li class="dropdown dropstart">
        <a class="dropdown-item dropdown-toggle" href="#" role="button"
           data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
            <?php echo esc_html($grupo['modulo']); ?>
        </a>
        <ul class="dropdown-menu">
            <?php foreach ($grupo['items'] as $item) : ?>
                <li>
                    <a class="dropdown-item" href="<?php echo esc_url($item['url']); ?>">
                        <?php echo esc_html($item['label']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </li>
    <?php
endforeach;
