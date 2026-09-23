<?php

namespace EGC\Modules\Sgf\Libro;

use EGC\Core\LoginPage;
use EGC\Core\Pages;
use EGC\Core\Singleton;
use EGC\Core\UserScope;
use EGC\Modules\Sgf\Billetera\Billetera;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Capa Puente — carga masiva de movimientos de Libro desde un archivo
 * CSV.
 *
 * No .xlsx: WordPress no trae ningún parser de Excel nativo (es un
 * zip con XML adentro), y un CSV lo lee PHP nativo con fgetcsv() sin
 * agregar ninguna dependencia — se sigue abriendo, editando y
 * guardando desde Excel exactamente igual, solo cambia la extensión.
 *
 * Clase APARTE de LibroManagement, a propósito: parsear un archivo y
 * mapear columnas es una razón de cambio distinta (formato de
 * entrada) a las que ya tiene esa clase (CRUD individual, listado
 * filtrado, recategorización masiva) — separarla evita que
 * LibroManagement siga creciendo sin límite, y deja el mismo molde
 * listo para cuando el futuro módulo de Banco necesite lo mismo. No
 * se extrae todavía ninguna base común entre las dos (parseo de CSV,
 * manejo de rechazos por fila): eso sería generalizar sobre un solo
 * caso de uso. Se extrae recién cuando exista el segundo módulo que
 * de verdad lo necesite — ver SRP APLICADO.
 *
 * El archivo trae ID Billetera, Fecha, Descripción, Debe, Haber y
 * Referencia — sin columna de Categoría a propósito (Edwin lo
 * confirmó): la categorización queda para después, con "Mantenimiento
 * de movimientos" (ver LibroManagement::view_state_mantenimiento()) —
 * esta pantalla nunca toca la taxonomía.
 *
 * La billetera se identifica por ID, no por nombre (Edwin lo pidió
 * así): un nombre puede repetirse o escribirse distinto al cargarlo a
 * mano, un ID no. Para que el usuario pueda efectivamente conseguir
 * ese dato, se agregó junto al nombre de cada billetera en
 * billetera/views/archive.php y billetera/views/single.php
 * ÚNICAMENTE — no aparece en ningún otro lugar (dropdown, formulario
 * de movimiento, etc.), porque en ningún otro lugar hace falta.
 *
 * Billetera ajena, según quién importa (confirmado): quien administra
 * el recurso de billeteras (UserScope::manages(Billetera::POST_TYPE) —
 * sgf_editor, Administrador General o el superusuario, siempre por
 * capacidad, nunca por nombre de rol — "un usuario", en este módulo,
 * siempre incluye al superusuario y al Administrador General) puede
 * importar a CUALQUIER billetera EXISTENTE, sea de quien sea; quien
 * solo autoría el recurso (UserScope::authors(Billetera::POST_TYPE),
 * típicamente sgf_autor) queda limitado a las suyas — una billetera
 * ajena es, para esa carga, igual que si no existiera: mismo error
 * 'billetera_invalida' de siempre. Un ID es un dato que se puede
 * escribir a mano o copiar de cualquier lado, así que nunca se confía
 * en que "si lo escribieron, es válido" — ver billeteras_permitidas()
 * e insertar_fila().
 *
 * Consecuencia directa de lo anterior: el movimiento importado queda
 * a nombre de la DUEÑA real de la billetera (su post_author), nunca
 * de quien está importando — mismo criterio que ya usa
 * LibroManagement::handle_save() para el alta individual (post_author
 * de un movimiento de Libro es siempre el dueño de la billetera, no
 * quien ejecuta la acción). Si un administrador importara a una
 * billetera ajena y el movimiento quedara a su propio nombre, se
 * rompería esa convención en el resto del módulo (filtros de
 * Mantenimiento, el propio criterio de duplicados, etc.) — ver
 * insertar_fila().
 *
 * Inserción PARCIAL (confirmado): una fila con error se rechaza y se
 * reporta con su motivo ("Fila N: ..."), pero no aborta el archivo
 * completo — las demás filas válidas sí se insertan.
 *
 * Duplicados (confirmado): una fila se rechaza igual que cualquier
 * otro error de validación si ya existe, en la MISMA billetera, un
 * movimiento con exactamente la misma Fecha, Descripción, Debe y
 * Haber — ver existe_movimiento_duplicado(). Nunca se inserta "por
 * las dudas": el mismo archivo subido dos veces, o una fila que ya se
 * había cargado a mano, no duplica nada.
 *
 * `_debe`/`_haber` viajan como columnas propias del archivo — el
 * propio docblock original de Libro::register_post_meta() ya
 * anticipaba este momento ("nadie los carga directo, salvo la futura
 * carga masiva"). Acá se invierte la derivación que hace el
 * formulario individual: en vez de partir de `_monto` con signo para
 * calcular `_debe`/`_haber` (ver LibroManagement::handle_save()), acá
 * se parte de Debe y Haber (ambos positivos en el archivo) para
 * calcular `_monto = Haber − Debe`.
 *
 * Fecha: se exige `AAAA-MM-DD` exacto (regex + checkdate() nativo),
 * nunca `strtotime()` adivinando un formato — un Excel exportado en
 * distinta configuración regional puede escribir "03/04/2026" como 3
 * de abril o como 4 de marzo, así que la única forma de no
 * equivocarse es no aceptar esa ambigüedad para empezar. Ver
 * insertar_fila().
 */
class LibroImportacion
{
    use Singleton;

    const SLUG = 'libro-importar';

    const ACTION_IMPORTAR = 'egc_libro_importar';

    const NONCE_NAME = '_egc_nonce';

    const TRANSIENT_RESULTADO = 'egc_libro_importar_resultado_';

    /**
     * Encabezados esperados del CSV: clave interna => nombre tal como
     * se compara (ya normalizado, ver normalizar_texto()). El archivo
     * puede traer las columnas en cualquier orden — se matchea por
     * NOMBRE de encabezado, leído de la primera fila, nunca por
     * posición fija.
     */
    const COLUMNAS = [
        'billetera_id' => 'id billetera',
        'fecha'        => 'fecha',
        'descripcion'  => 'descripcion',
        'debe'         => 'debe',
        'haber'        => 'haber',
        'referencia'   => 'referencia',
    ];

    /**
     * Códigos de error, EN EL ORDEN en que se listan agrupados — Edwin
     * pidió que las filas con error se agrupen por tipo de error, no
     * que queden sueltas en el orden en que aparecieron en el
     * archivo. Es el mismo orden en que insertar_fila() las valida.
     * Los textos fijos de cada uno viven en mensaje_tipo_error() (no
     * acá) para que el escaneo de traducciones de WordPress pueda
     * encontrar cada __() como string literal, no como variable.
     *
     * "billetera_invalida" queda AFUERA a propósito: esa no se lista
     * fila por fila (ver el docblock de la clase sobre "billetera que
     * no existe") — se resume aparte, como una alerta, en
     * procesar_filas().
     */
    const ORDEN_TIPOS_ERROR = [
        'fecha_formato',
        'fecha_invalida',
        'descripcion_vacia',
        'montos_no_numericos',
        'duplicado',
        'error_wp',
    ];

    private $url = null;

    private function __construct()
    {
        add_action('template_redirect', [$this, 'guard_access']);
        add_action('admin_post_' . self::ACTION_IMPORTAR, [$this, 'handle_importar']);

        // "Importar movimientos" no es el archive de Libro (public =>
        // false, ver Libro::register_post_type()), así que
        // UserScope::links_by() no puede armar sola su entrada en el
        // dropdown. Se suma al mismo grupo que ya usan
        // CategoriaManagement y LibroManagement::add_navbar_items() —
        // mismo mecanismo de extensión (egc_dropdown_items_{$post_type}),
        // acá disparado por esta clase.
        add_filter('egc_dropdown_items_' . Libro::POST_TYPE, [$this, 'add_navbar_items'], 10, 2);
    }

    public function add_navbar_items($items, $tier)
    {
        $items[] = [
            'label' => __('Importar movimientos (CSV)', 'egc'),
            'url'   => $this->url(),
        ];

        return $items;
    }

    public function url()
    {
        if ($this->url === null) {
            $id = Pages::get_instance()->find_or_create(__('Importar movimientos', 'egc'), self::SLUG);
            $this->url = $id ? get_permalink($id) : home_url('/');
        }

        return $this->url;
    }

    /**
     * Mismo piso que el resto de las pantallas de Libro — ver
     * LibroManagement::guard_mantenimiento(). Capacidad real sobre
     * Libro, nunca nombre de rol: da igual si quien entra es
     * sgf_autor, sgf_editor o Administrador General.
     */
    public function guard_access()
    {
        if (!is_page(self::SLUG)) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(LoginPage::get_instance()->url());
            exit;
        }

        $tiene_acceso = UserScope::get_instance()->manages(Libro::POST_TYPE)
            || UserScope::get_instance()->authors(Libro::POST_TYPE);

        if (!$tiene_acceso) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    /**
     * El resultado de la ÚLTIMA carga (si la hay) — se lee y se borra
     * del transient en el mismo momento, así que solo se muestra una
     * vez, justo después del redirect de handle_importar(); si el
     * usuario refresca la página después, ya no aparece.
     *
     * @return array{
     *   resultado: ?array{
     *     insertados: int,
     *     grupos: array<int,array{mensaje:string, filas:array<int,int>}>,
     *     billeteras_invalidas: ?array{cantidad:int, ids:array<int,string>},
     *     error_archivo: ?string,
     *   },
     *   form_action: string,
     *   nonce_action: string,
     *   nonce_name: string,
     * }
     */
    public function view_state()
    {
        $user_id   = get_current_user_id();
        $resultado = get_transient(self::TRANSIENT_RESULTADO . $user_id);
        delete_transient(self::TRANSIENT_RESULTADO . $user_id);

        return [
            'resultado'    => $resultado ?: null,
            'form_action'  => admin_url('admin-post.php'),
            'nonce_action' => self::ACTION_IMPORTAR,
            'nonce_name'   => self::NONCE_NAME,
        ];
    }

    public function handle_importar()
    {
        check_admin_referer(self::ACTION_IMPORTAR, self::NONCE_NAME);

        $user_id = get_current_user_id();
        $filas   = $this->leer_archivo($_FILES['archivo'] ?? null);

        if ($filas === null) {
            $this->guardar_resultado($user_id, [
                'insertados'            => 0,
                'grupos'                => [],
                'billeteras_invalidas'  => null,
                'error_archivo'         => __('No se pudo leer el archivo — subí un CSV con las columnas ID Billetera, Fecha, Descripción, Debe, Haber y Referencia.', 'egc'),
            ]);
            $this->back();
        }

        $resultado = $this->procesar_filas($filas, $user_id);

        $this->guardar_resultado($user_id, $resultado);
        $this->back();
    }

    /**
     * Lee el CSV subido y lo devuelve como una lista de filas
     * asociativas (clave interna de COLUMNAS => valor de la celda, ya
     * en UTF-8) — null si el archivo no se pudo leer o le faltan
     * columnas obligatorias, para que handle_importar() rechace todo
     * el archivo de una: sin saber DÓNDE está la columna Fecha no hay
     * nada razonable que procesar fila por fila.
     *
     * No usa wp_handle_upload(): esa función es para persistir un
     * archivo como adjunto de la biblioteca de medios, y este CSV se
     * procesa una sola vez y se descarta — guardarlo ahí ensuciaría la
     * biblioteca con un archivo que nadie vuelve a necesitar. PHP ya
     * garantiza que tmp_name es un archivo subido genuino
     * (is_uploaded_file()), así que alcanza con leerlo directo.
     *
     * Dos problemas prácticos de un CSV exportado desde Excel, no
     * teóricos:
     *
     * 1. Codificación: Excel en Windows, salvo que se elija
     *    explícitamente "CSV UTF-8" al guardar, exporta en
     *    Windows-1252 — una "Descripción" o cualquier tilde en el
     *    contenido quedarían corruptas si se leyeran tal cual. Se
     *    detecta y convierte con funciones mb_* nativas de PHP, sin
     *    ninguna librería.
     * 2. Separador: en configuración regional en español, Excel usa
     *    punto y coma (la coma ya es el separador decimal), no coma —
     *    se detecta contando cuál aparece más en la primera línea, en
     *    vez de asumir uno fijo.
     *
     * El contenido ya saneado se vuelca a un stream en memoria
     * (php://temp) para poder seguir usando fgetcsv(): lee fila por
     * fila respetando comillas y saltos de línea DENTRO de una celda,
     * algo que partir el archivo a mano por "\n" rompería.
     *
     * @return array<int,array<string,string>>|null
     */
    private function leer_archivo($archivo)
    {
        if (
            !is_array($archivo)
            || empty($archivo['tmp_name'])
            || !is_uploaded_file($archivo['tmp_name'])
            || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            return null;
        }

        $contenido = file_get_contents($archivo['tmp_name']);
        if ($contenido === false || trim($contenido) === '') {
            return null;
        }

        // BOM de UTF-8 (lo agrega la opción "CSV UTF-8" de Excel al
        // guardar) — se descarta; si queda pegado al primer
        // encabezado, "ID Billetera" nunca matchea.
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $primera_linea = strtok($contenido, "\n");
        $delimitador   = substr_count($primera_linea, ';') > substr_count($primera_linea, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contenido);
        rewind($stream);

        $encabezados = fgetcsv($stream, 0, $delimitador);
        if (!$encabezados) {
            fclose($stream);
            return null;
        }

        $indice = $this->indice_columnas($encabezados);
        if ($indice === null) {
            fclose($stream);
            return null;
        }

        $filas = [];
        while (($linea = fgetcsv($stream, 0, $delimitador)) !== false) {
            // Línea completamente vacía (común al final de un CSV
            // exportado desde Excel) — se ignora, no es una fila con
            // error.
            if (count($linea) === 1 && trim((string) $linea[0]) === '') {
                continue;
            }

            $fila = [];
            foreach ($indice as $clave => $posicion) {
                $fila[$clave] = isset($linea[$posicion]) ? trim((string) $linea[$posicion]) : '';
            }

            $filas[] = $fila;
        }

        fclose($stream);

        return $filas;
    }

    /**
     * Mapea cada columna interna (ver COLUMNAS) a la posición en la
     * que apareció en la primera fila del archivo — comparando ya
     * normalizado (minúscula, sin tildes, sin espacios de más — ver
     * normalizar_texto()), para no rechazar un archivo solo porque
     * alguien escribió "Descripcion" sin tilde o "BILLETERA " con un
     * espacio de más. null si falta alguna columna obligatoria.
     *
     * @return array<string,int>|null
     */
    private function indice_columnas($encabezados)
    {
        $normalizados = array_map([$this, 'normalizar_texto'], $encabezados);

        $indice = [];
        foreach (self::COLUMNAS as $clave => $nombre) {
            $posicion = array_search($nombre, $normalizados, true);
            if ($posicion === false) {
                return null;
            }
            $indice[$clave] = $posicion;
        }

        return $indice;
    }

    /**
     * minúscula, sin tildes, sin espacios en los extremos —
     * remove_accents() es nativa de WordPress (wp-includes/formatting.php),
     * así que no hace falta escribir ninguna tabla de acentos propia.
     * La usa indice_columnas() para matchear los encabezados del
     * archivo sin depender de que tengan exactamente los mismos
     * acentos y mayúsculas que COLUMNAS (billetera ya no pasa por
     * acá: desde que se identifica por ID en vez de por nombre, ver
     * el docblock de la clase, se compara con absint(), no con texto).
     */
    private function normalizar_texto($texto)
    {
        return trim(remove_accents(mb_strtolower(trim((string) $texto), 'UTF-8')));
    }

    /**
     * Arma el reporte de la carga: cuántas se insertaron, las filas
     * con error AGRUPADAS por tipo de error (Edwin lo pidió así, en
     * vez de una lista suelta en el orden del archivo — ver
     * ORDEN_TIPOS_ERROR), y aparte, si las hay, las filas que traían
     * un ID de billetera que no existe o no es propio.
     *
     * "Billetera inválida" se resume distinto a propósito (Edwin lo
     * pidió explícito): NO se lista fila por fila como el resto de
     * los errores — si alguien sube el archivo equivocado o con la
     * columna corrida, eso puede ser CADA fila del archivo, y listar
     * "Fila 2: ...", "Fila 3: ...", ..., "Fila 500: ..." una por una
     * es ruido, no información. En vez de eso queda una sola alerta
     * con el total y los IDs distintos que no se pudieron resolver.
     *
     * @return array{insertados:int, grupos:array, billeteras_invalidas:?array, error_archivo:?string}
     */
    private function procesar_filas($filas, $user_id)
    {
        $billeteras_permitidas = $this->billeteras_permitidas($user_id);

        // recalcular_saldo_billetera() (ver Libro.php) recorre TODOS
        // los movimientos de la billetera en cada guardado — pensado
        // para un alta a la vez, no para un archivo de cientos de
        // filas de la MISMA billetera, donde recalcularlo en cada una
        // de las N inserciones es trabajo redundante que crece con
        // N². Se desengancha durante el lote y se recalcula UNA sola
        // vez al final por cada billetera realmente tocada, con
        // cualquiera de sus movimientos recién insertados (el método
        // recalcula desde cero sumando todos, así que da igual cuál
        // se le pase — ver su docblock).
        $libro = Libro::get_instance();
        remove_action('save_post_' . Libro::POST_TYPE, [$libro, 'recalcular_saldo_billetera']);

        $insertados               = 0;
        $filas_por_tipo           = array_fill_keys(self::ORDEN_TIPOS_ERROR, []);
        $ids_billetera_invalidos  = [];
        $ultimo_movimiento_por_billetera = [];

        $numero_fila = 1;
        foreach ($filas as $fila) {
            $numero_fila++; // la fila 1 del archivo es el encabezado

            $resultado = $this->insertar_fila($fila, $billeteras_permitidas);

            if ($resultado['ok']) {
                $insertados++;
                $ultimo_movimiento_por_billetera[$resultado['billetera_id']] = $resultado['post_id'];
                continue;
            }

            if ($resultado['codigo'] === 'billetera_invalida') {
                $ids_billetera_invalidos[] = $resultado['billetera_id_original'];
                continue;
            }

            $filas_por_tipo[$resultado['codigo']][] = $numero_fila;
        }

        add_action('save_post_' . Libro::POST_TYPE, [$libro, 'recalcular_saldo_billetera']);

        foreach ($ultimo_movimiento_por_billetera as $movimiento_id) {
            $libro->recalcular_saldo_billetera($movimiento_id);
        }

        $grupos = [];
        foreach (self::ORDEN_TIPOS_ERROR as $codigo) {
            if (empty($filas_por_tipo[$codigo])) {
                continue;
            }

            $grupos[] = [
                'mensaje' => $this->mensaje_tipo_error($codigo),
                'filas'   => $filas_por_tipo[$codigo],
            ];
        }

        $billeteras_invalidas = null;
        if (!empty($ids_billetera_invalidos)) {
            $billeteras_invalidas = [
                'cantidad' => count($ids_billetera_invalidos),
                'ids'      => array_values(array_unique($ids_billetera_invalidos)),
            ];
        }

        return [
            'insertados'           => $insertados,
            'grupos'               => $grupos,
            'billeteras_invalidas' => $billeteras_invalidas,
            'error_archivo'        => null,
        ];
    }

    /**
     * Texto fijo de cada código de ORDEN_TIPOS_ERROR — en un método
     * aparte, con cada __() como string literal, para que el escaneo
     * de traducciones de WordPress los encuentre (no los encontraría
     * si el texto viniera de una constante y se le aplicara __() a la
     * variable, ver el docblock de ORDEN_TIPOS_ERROR).
     */
    private function mensaje_tipo_error($codigo)
    {
        switch ($codigo) {
            case 'fecha_formato':
                return __('La fecha debe tener el formato AAAA-MM-DD.', 'egc');
            case 'fecha_invalida':
                return __('Esa fecha no existe.', 'egc');
            case 'descripcion_vacia':
                return __('La descripción no puede quedar vacía.', 'egc');
            case 'montos_no_numericos':
                return __('Debe y Haber tienen que ser números.', 'egc');
            case 'duplicado':
                return __('Ya existe un movimiento igual (misma fecha, descripción, debe y haber) en esa billetera.', 'egc');
            case 'error_wp':
                return __('No se pudo guardar el movimiento.', 'egc');
            default:
                return '';
        }
    }

    /**
     * Billeteras que $user_id puede usar en esta carga, como mapa
     * billetera_id => dueño_id (el post_author REAL de la billetera,
     * no necesariamente quien importa) — no un simple conjunto "es
     * mía": desde que un administrador puede importar a billeteras
     * ajenas (ver el docblock de la clase), insertar_fila() necesita
     * saber DE QUIÉN es cada una para atribuirle el movimiento a su
     * dueña real, no a quien está importando.
     *
     * Alcance (confirmado): quien administra el recurso de billeteras
     * (UserScope::manages(Billetera::POST_TYPE) — sgf_editor,
     * Administrador General o el superusuario, siempre por capacidad)
     * puede importar a CUALQUIER billetera existente, así que el mapa
     * sale sin restringir por autor; quien solo autoría el recurso
     * (UserScope::authors(Billetera::POST_TYPE)) queda limitado a las
     * suyas, mismo filtro `author` que ya se usaba acá antes de este
     * cambio. Con la billetera identificada por ID en el archivo (ver
     * el docblock de la clase), esto ya no necesita indexar por
     * título: solo confirma que ese ID es una billetera real dentro
     * del alcance permitido, y de paso resuelve su dueño.
     *
     * Se arma una sola vez antes del lote (ver procesar_filas()), no
     * con una consulta por fila.
     *
     * @return array<int,int> billetera_id => dueño_id
     */
    private function billeteras_permitidas($user_id)
    {
        $args = [
            'post_type'      => Billetera::POST_TYPE,
            'post_status'    => ['publish', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'all',
        ];

        if (!UserScope::get_instance()->manages(Billetera::POST_TYPE)) {
            $args['author'] = $user_id;
        }

        $billeteras = get_posts($args);

        $mapa = [];
        foreach ($billeteras as $billetera) {
            $mapa[$billetera->ID] = (int) $billetera->post_author;
        }

        return $mapa;
    }

    /**
     * Si ya existe, dentro de la MISMA billetera, un movimiento con
     * exactamente la misma Fecha, Descripción, Debe y Haber —
     * criterio que confirmó Edwin para no duplicar una carga repetida
     * por accidente (el mismo archivo subido dos veces, o una fila
     * que ya se había cargado a mano o en una importación anterior).
     * `title` en get_posts() compara post_title EXACTO (no es una
     * búsqueda parcial como `s`), así que hace falta que la
     * Descripción coincida letra por letra — coherente con que
     * también se exige que Fecha, Debe y Haber coincidan los cuatro
     * a la vez, no alguno solo.
     *
     * De paso, sin código extra: como cada fila se inserta con
     * wp_insert_post() apenas se valida (ver insertar_fila()), un
     * movimiento recién insertado por ESTE MISMO archivo ya es
     * visible para esta consulta en la fila siguiente — así que dos
     * filas idénticas DENTRO del mismo CSV también se detectan entre
     * sí, no solo contra lo que ya había antes de importar.
     */
    private function existe_movimiento_duplicado($billetera_id, $año, $mes, $dia, $descripcion, $debe, $haber)
    {
        $existentes = get_posts([
            'post_type'      => Libro::POST_TYPE,
            'post_parent'    => $billetera_id,
            'post_status'    => 'publish',
            'title'          => $descripcion,
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'date_query'     => [
                [
                    'year'  => $año,
                    'month' => $mes,
                    'day'   => $dia,
                ],
            ],
            'meta_query' => [
                [
                    'key'     => '_debe',
                    'value'   => $debe,
                    'type'    => 'NUMERIC',
                    'compare' => '=',
                ],
                [
                    'key'     => '_haber',
                    'value'   => $haber,
                    'type'    => 'NUMERIC',
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($existentes);
    }

    /**
     * Valida y guarda UNA fila — nunca lanza: siempre devuelve un
     * sobre con 'ok' => true|false, así procesar_filas() decide qué
     * hacer sin try/catch y sin tener que distinguir "resultado" de
     * "mensaje de error" por el tipo de dato devuelto:
     *
     * - Éxito: ['ok' => true, 'post_id' => …, 'billetera_id' => …]
     *   (para recalcular_saldo_billetera() al final del lote).
     * - Error: ['ok' => false, 'codigo' => …] — el código es uno de
     *   ORDEN_TIPOS_ERROR (procesar_filas() arma el mensaje agrupado
     *   con mensaje_tipo_error()), salvo 'billetera_invalida', que
     *   además trae 'billetera_id_original' (el valor tal como vino
     *   en el archivo, para el resumen de billeteras no encontradas —
     *   ver el docblock de procesar_filas()).
     *
     * Fecha: `AAAA-MM-DD` exacto por regex, después checkdate() nativo
     * de PHP — atrapa fechas con la forma correcta pero imposibles
     * (2026-02-30, 2026-13-01). Nunca strtotime(): a diferencia del
     * campo `fecha` del formulario individual (donde el
     * `<input type="date">` del navegador ya garantiza un string
     * limpio), acá se está leyendo texto libre de un archivo subido,
     * y un formato ambiguo ("03/04/2026") podría interpretarse mal en
     * silencio según la configuración regional con la que se exportó.
     *
     * post_author del movimiento insertado: la DUEÑA real de la
     * billetera (resuelta por billeteras_permitidas()), nunca quien
     * está importando — ver el docblock de la clase sobre por qué.
     *
     * @return array{ok:true, post_id:int, billetera_id:int}|array{ok:false, codigo:string, billetera_id_original?:string}
     */
    private function insertar_fila($fila, $billeteras_permitidas)
    {
        $billetera_id = absint($fila['billetera_id']);

        // Nunca se confía en que, si alguien escribió un ID en la
        // celda, ese ID es válido: tiene que existir en el mapa de
        // billeteras_permitidas(), que ya viene acotado según quién
        // importa (ver su docblock) — cualquier billetera existente
        // para quien administra el recurso, solo las propias para
        // quien únicamente autoría.
        if (!$billetera_id || !isset($billeteras_permitidas[$billetera_id])) {
            return [
                'ok'                    => false,
                'codigo'                => 'billetera_invalida',
                'billetera_id_original' => $fila['billetera_id'],
            ];
        }

        $dueño_id = $billeteras_permitidas[$billetera_id];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fila['fecha'])) {
            return ['ok' => false, 'codigo' => 'fecha_formato'];
        }

        [$año, $mes, $dia] = array_map('intval', explode('-', $fila['fecha']));
        if (!checkdate($mes, $dia, $año)) {
            return ['ok' => false, 'codigo' => 'fecha_invalida'];
        }

        $descripcion = sanitize_text_field($fila['descripcion']);
        if ($descripcion === '') {
            return ['ok' => false, 'codigo' => 'descripcion_vacia'];
        }

        $debe_texto  = str_replace(',', '.', $fila['debe']);
        $haber_texto = str_replace(',', '.', $fila['haber']);

        if (($debe_texto !== '' && !is_numeric($debe_texto)) || ($haber_texto !== '' && !is_numeric($haber_texto))) {
            return ['ok' => false, 'codigo' => 'montos_no_numericos'];
        }

        $debe  = $debe_texto !== '' ? abs(round((float) $debe_texto, 2)) : 0.0;
        $haber = $haber_texto !== '' ? abs(round((float) $haber_texto, 2)) : 0.0;
        $monto = round($haber - $debe, 2);

        if ($this->existe_movimiento_duplicado($billetera_id, $año, $mes, $dia, $descripcion, $debe, $haber)) {
            return ['ok' => false, 'codigo' => 'duplicado'];
        }

        $data = [
            'post_type'   => Libro::POST_TYPE,
            'post_parent' => $billetera_id,
            'post_author' => $dueño_id,
            'post_title'  => $descripcion,
            'post_status' => 'publish',
            'post_date'   => $fila['fecha'] . ' 00:00:00',
            'meta_input'  => [
                '_monto'      => $monto,
                '_haber'      => $haber,
                '_debe'       => $debe,
                '_referencia' => sanitize_text_field($fila['referencia']),
            ],
        ];

        $post_id = wp_insert_post($data, true);

        if (is_wp_error($post_id)) {
            return ['ok' => false, 'codigo' => 'error_wp'];
        }

        return ['ok' => true, 'post_id' => (int) $post_id, 'billetera_id' => $billetera_id];
    }

    private function guardar_resultado($user_id, $resultado)
    {
        set_transient(self::TRANSIENT_RESULTADO . $user_id, $resultado, MINUTE_IN_SECONDS);
    }

    private function back()
    {
        wp_safe_redirect($this->url());
        exit;
    }
}
