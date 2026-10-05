<?php

namespace EGC\Modules\Sgf\Migracion;

use EGC\Core\Singleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Puente — la pantalla "Herramientas > Migración SGF" de wp-admin
 * y sus dos handlers (exportar e importar). Todo el trabajo de datos
 * lo hace Migracion; esta clase solo decide quién puede, valida lo que
 * llega, y le prepara a la vista lo que tiene que pintar.
 *
 * Qué resuelve WordPress y qué no (PRINCIPIO RECTOR): la entrada del
 * menú es `add_management_page()` — el submenú nativo de Herramientas,
 * con su propio chequeo de capacidad — y los formularios van por
 * admin-post.php con nonce, igual que todo el CRUD del proyecto. Lo
 * único propio es la descarga del JSON (headers + echo): WordPress no
 * trae un "descargar este string" genérico.
 *
 * Autorización: `manage_options`, la misma capacidad con la que
 * AdminGuard decide quién entra a wp-admin — o sea, solo el
 * superusuario. Se revalida en los dos handlers (el menú oculto es
 * presentación, no seguridad; y admin-post.php está excluido del guard
 * de wp-admin, así que ahí nadie lo hace por nosotros). Importar crea
 * contenido para CUALQUIER usuario, por eso no hay una versión "para
 * lo mío" de esta pantalla.
 *
 * Por qué una clase aparte de Migracion: esa cambia si cambia el
 * FORMATO o las reglas de los datos; esta cambia si cambia la
 * pantalla o el flujo de subida/descarga — dos razones de cambio
 * distintas, mismo criterio que LibroImportacion vs. Libro.
 */
class MigracionManagement
{
    use Singleton;

    const PAGE_SLUG = 'egc-sgf-migracion';

    const CAPABILITY = 'manage_options';

    const ACTION_EXPORTAR = 'egc_sgf_migracion_exportar';

    const ACTION_IMPORTAR = 'egc_sgf_migracion_importar';

    const NONCE_NAME = '_egc_nonce';

    /**
     * El resultado de la última acción viaja a la pantalla en un
     * transient por usuario (se lee y se borra de una vez, así que se
     * muestra una sola vez) — mismo mecanismo que LibroImportacion.
     */
    const TRANSIENT_RESULTADO = 'egc_sgf_migracion_';

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::ACTION_EXPORTAR, [$this, 'handle_exportar']);
        add_action('admin_post_' . self::ACTION_IMPORTAR, [$this, 'handle_importar']);
    }

    public function register_menu()
    {
        add_management_page(
            __('Migración SGF', 'egc'),
            __('Migración SGF', 'egc'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Nada de HTML acá: arma el estado y delega el marcado a la vista.
     * El callback de add_management_page() ya garantiza la capacidad,
     * pero se revalida igual: es la puerta de la pantalla.
     */
    public function render_page()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('No tenés permiso para ver esta pantalla.', 'egc'), '', ['response' => 403]);
        }

        $estado = $this->view_state();

        include EGC_DIR . '/modules/sgf/migracion/views/admin/migracion.php';
    }

    /**
     * @return array{
     *   usuarios: array<int,array>,
     *   resultado: ?array,
     *   error: ?string,
     *   form_action: string,
     *   exportar: array{accion:string, nonce_action:string, nonce_name:string},
     *   importar: array{accion:string, nonce_action:string, nonce_name:string},
     *   tamano_maximo: string,
     * }
     */
    private function view_state()
    {
        $guardado = get_transient(self::TRANSIENT_RESULTADO . get_current_user_id());
        delete_transient(self::TRANSIENT_RESULTADO . get_current_user_id());

        $resultado = (is_array($guardado) && isset($guardado['importacion'])) ? $this->con_totales($guardado['importacion']) : null;
        $error     = (is_array($guardado) && isset($guardado['error'])) ? (string) $guardado['error'] : null;

        return [
            'usuarios'      => Migracion::get_instance()->usuarios_con_datos(),
            'resultado'     => $resultado,
            'error'         => $error,
            'form_action'   => admin_url('admin-post.php'),
            'exportar'      => [
                'accion'       => self::ACTION_EXPORTAR,
                'nonce_action' => self::ACTION_EXPORTAR,
                'nonce_name'   => self::NONCE_NAME,
            ],
            'importar'      => [
                'accion'       => self::ACTION_IMPORTAR,
                'nonce_action' => self::ACTION_IMPORTAR,
                'nonce_name'   => self::NONCE_NAME,
            ],
            'tamano_maximo' => size_format(wp_max_upload_size()),
        ];
    }

    /**
     * Suma los contadores de todos los usuarios importados, para que
     * la vista pinte una fila de totales sin sumar nada ella.
     */
    private function con_totales(array $importacion)
    {
        $totales = [];

        foreach ($importacion['usuarios'] as $fila) {
            foreach (['categorias', 'billeteras', 'movimientos', 'presupuestos'] as $grupo) {
                foreach ($fila[$grupo] as $contador => $valor) {
                    $totales[$grupo][$contador] = ($totales[$grupo][$contador] ?? 0) + $valor;
                }
            }
        }

        $importacion['totales']   = $totales;
        $importacion['omitidos']  = count(array_filter($importacion['usuarios'], function ($fila) {
            return !$fila['importado'];
        }));

        return $importacion;
    }

    /**
     * Descarga el JSON. Va por admin-post.php (no por la propia página)
     * porque tiene que responder con el archivo ANTES de que
     * wp-admin imprima una sola línea de HTML.
     */
    public function handle_exportar()
    {
        $this->autorizar(self::ACTION_EXPORTAR);

        $alcance = isset($_POST['alcance']) ? sanitize_key(wp_unslash($_POST['alcance'])) : '';
        $nombre  = 'todos';

        if ($alcance === 'uno') {
            $user_id = isset($_POST['usuario_id']) ? absint($_POST['usuario_id']) : 0;
            $usuario = $user_id ? get_userdata($user_id) : false;

            if (!$usuario) {
                $this->volver_con_error(__('Elegí el usuario a exportar.', 'egc'));
            }

            $user_ids = [$user_id];
            $nombre   = sanitize_title($usuario->user_login);
        } elseif ($alcance === 'todos') {
            $user_ids = array_column(Migracion::get_instance()->usuarios_con_datos(), 'id');
        } else {
            $this->volver_con_error(__('Elegí si exportar un usuario o todos.', 'egc'));
        }

        $json = Migracion::get_instance()->exportar_json($user_ids);

        if (is_wp_error($json)) {
            $this->volver_con_error($json->get_error_message());
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="sgf-migracion-' . $nombre . '-' . gmdate('Ymd-His') . '.json"');
        header('Content-Length: ' . strlen($json));

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON ya codificado por wp_json_encode().
        exit;
    }

    public function handle_importar()
    {
        $this->autorizar(self::ACTION_IMPORTAR);

        $archivo = isset($_FILES['archivo']) ? $_FILES['archivo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if (
            !is_array($archivo)
            || (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || empty($archivo['tmp_name'])
            || !is_uploaded_file($archivo['tmp_name'])
        ) {
            $this->volver_con_error(__('No se recibió el archivo (o superó el tamaño máximo de subida). Elegí el .json exportado.', 'egc'));
        }

        // El tipo MIME de un .json no está en la lista de WordPress y
        // el del navegador no es confiable: se mira la extensión, y el
        // contenido lo valida de verdad Migracion::importar_json().
        if (strtolower(pathinfo(sanitize_file_name($archivo['name']), PATHINFO_EXTENSION)) !== 'json') {
            $this->volver_con_error(__('El archivo tiene que ser un .json exportado desde esta misma pantalla.', 'egc'));
        }

        $contenido = file_get_contents($archivo['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ($contenido === false) {
            $this->volver_con_error(__('No se pudo leer el archivo subido.', 'egc'));
        }

        $resultado = Migracion::get_instance()->importar_json($contenido);

        if (is_wp_error($resultado)) {
            $this->volver_con_error($resultado->get_error_message());
        }

        set_transient(self::TRANSIENT_RESULTADO . get_current_user_id(), ['importacion' => $resultado], 10 * MINUTE_IN_SECONDS);
        $this->volver();
    }

    /**
     * Autenticación + capacidad + nonce, en ese orden, para los dos
     * handlers. `check_admin_referer()` ya hace wp_die() si el nonce no
     * es válido.
     */
    private function autorizar($accion)
    {
        if (!is_user_logged_in() || !current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('No tenés permiso para hacer esto.', 'egc'), '', ['response' => 403]);
        }

        check_admin_referer($accion, self::NONCE_NAME);
    }

    private function volver_con_error($mensaje)
    {
        set_transient(self::TRANSIENT_RESULTADO . get_current_user_id(), ['error' => $mensaje], MINUTE_IN_SECONDS);
        $this->volver();
    }

    private function volver()
    {
        wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE_SLUG));
        exit;
    }
}
