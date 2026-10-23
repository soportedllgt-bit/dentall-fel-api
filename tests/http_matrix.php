<?php

declare(strict_types=1);

/**
 * Prueba funcional de la API contra Apache.
 *
 * ## Qué cubre
 *
 * | Sección                | Contenido                                              |
 * |------------------------|--------------------------------------------------------|
 * | Autenticación          | Ausencia, error y acierto de las credenciales.          |
 * | Encabezados            | `Content-Type`, JSON malformado, CORS, `OPTIONS`.      |
 * | CRUD de items          | Alta, lectura, listado, paginación, actualización,      |
 * |                        | borrado y validación de campos.                         |
 * | Facturación            | Contrato de la orden y códigos de respuesta.            |
 * | Idempotencia           | Una orden no produce dos facturas.                      |
 * | Contingencia (HU-3)    | Estados de fallo y el contexto que recibe el POS.       |
 *
 * ## Por qué no se llama al proveedor
 *
 * La única forma de provocar un rechazo del proveedor es llamar al sandbox, y el
 * sandbox **rechaza por límite de emisiones los documentos válidos que llegan
 * encadenados**. Una prueba que dependiera de él fallaría de forma intermitente y
 * consumiría emisiones reales en cada ejecución.
 *
 * Las órdenes que necesitan un desenlace concreto se siembran directamente en la
 * base de datos, y la emisión en sí se ejercita en `tests/contingencia.php` con un
 * emidor simulado, también sin red.
 *
 * La suite deja la base de datos como la encontró: todo lo que crea se borra al
 * terminar, incluso si una aserción falla.
 *
 * Requiere Apache y MySQL en marcha. Se ejecuta con
 * `php tests/http_matrix.php` desde la raíz del proyecto.
 *
 * @package App\Tests
 *
 * @author  fel-dentall
 * @license MIT
 */

$appRoot = dirname(__DIR__) . '/app';

spl_autoload_register(static function (string $class) use ($appRoot): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = $appRoot . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)))
        . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

$passed = 0;
$failed = 0;

/**
 * Comprueba una aserción y registra el resultado.
 *
 * @param string $name      Descripción de la aserción.
 * @param bool   $condition Resultado esperado.
 * @param string $detail    Detalle a mostrar si falla.
 *
 * @return void
 */
$assert = static function (string $name, bool $condition, string $detail = '') use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  [OK]   {$name}\n";
        return;
    }

    $failed++;
    echo "  [FALLA] {$name}\n";

    if ($detail !== '') {
        echo "         {$detail}\n";
    }
};

// -----------------------------------------------------------------------------
// Configuración de la prueba
// -----------------------------------------------------------------------------
$baseUrl    = rtrim((string) (getenv('API_BASE') ?: 'http://127.0.0.1/fel-dentall'), '/');
$config     = new App\Core\AppConfig(require dirname(__DIR__) . '/config/config.php');
$authConfig = $config->getAuthConfig();
$authHeader = 'Authorization: Basic ' . base64_encode(
    $authConfig['username'] . ':' . $authConfig['password']
);

try {
    $dbConfig = $config->getDbConfig();
    $pdo      = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbConfig['host'], $dbConfig['port'], $dbConfig['database']),
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $exception) {
    echo "  [AVISO] No hay base de datos disponible: " . $exception->getMessage() . "\n";
    echo "  Se omite la prueba funcional.\n";
    exit(0);
}

/**
 * Ejecuta una petición HTTP contra la API.
 *
 * @param string             $method  Verbo HTTP.
 * @param string             $url     Ruta completa.
 * @param string|null        $body    Cuerpo de la petición.
 * @param array<int, string> $headers Cabeceras adicionales.
 *
 * @return array{status: int, body: string} Respuesta recibida.
 */
$request = static function (
    string $method,
    string $url,
    ?string $body = null,
    array $headers = []
) use ($baseUrl): array {
    $handle = curl_init($baseUrl . $url);

    $headerLines = [];
    foreach ($headers as $header) {
        $headerLines[] = $header;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headerLines,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($handle);

    if ($response === false) {
        $error = curl_error($handle);
        curl_close($handle);

        return ['status' => 0, 'body' => json_encode([
            'success' => false,
            'message' => 'Error de red: ' . $error,
            'data'    => null,
            'errors'  => null,
        ], JSON_UNESCAPED_UNICODE)];
    }

    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return ['status' => $status, 'body' => (string) $response];
};

/**
 * Decodifica el cuerpo de una respuesta.
 *
 * @param string $body Cuerpo de la respuesta.
 *
 * @return array<string, mixed> Contenido decodificado, o vacío si no es JSON.
 */
$decode = static function (string $body): array {
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
};

/**
 * Decodifica el cuerpo y garantiza que es un objeto.
 *
 * @param string $body Cuerpo de la respuesta.
 *
 * @return array<string, mixed> Contenido decodificado.
 */
$decodeBody = static function (string $body) use ($decode): array {
    return $decode($body);
};

/** Body JSON con las cabeceras de autenticación y tipo de contenido. */
$json = static function (array $data): string {
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

/**
 * Siembra una orden directamente en la base de datos.
 *
 * Las marcas de tiempo se escriben con `UTC_TIMESTAMP()` y no con `NOW()` porque
 * la aplicación las guarda siempre en UTC, mientras que el servidor de MySQL corre
 * en hora de Guatemala, seis horas por detrás. Sembrar con `NOW()` produciría una
 * orden que parece más antigua de lo que es y dispararía la recuperación de
 * órdenes atascadas.
 *
 * @param string      $code       Código único de la orden.
 * @param string      $status     Estado a sembrar.
 * @param bool        $ambiguo    Marca la orden como ambigua.
 * @param string|null $error      Mensaje de error a guardar.
 * @param string|null $codigoError Código de error del proveedor.
 * @param int         $attempts   Número de intentos registrados.
 *
 * @return string UUID de la orden sembrada.
 */
$seedOrder = static function (
    string $code,
    string $status,
    bool $ambiguo = false,
    ?string $error = null,
    ?string $codigoError = null,
    int $attempts = 1
) use ($pdo): string {
    $uuid = sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xFFFFFFFF),
        random_int(0, 0xFFFF),
        random_int(0, 0xFFF),
        random_int(0x8000, 0xBFFF),
        random_int(0, 0xFFFFFFFFFFFF)
    );

    $pdo->prepare(
        'INSERT INTO gf_orders (uuid, order_code, dte_type, status, payload, ambiguous,
                                error_message, last_error_code, attempts, created_at, updated_at, invoiced_at)
         VALUES (:uuid, :code, 1, :status, :payload, :ambiguous,
                 :error_message, :last_error_code, :attempts, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :invoiced)'
    )->execute([
        ':uuid'            => $uuid,
        ':code'            => $code,
        ':status'          => $status,
        ':payload'         => json_encode(['codigo_orden' => $code, 'sembrado' => true], JSON_UNESCAPED_UNICODE),
        ':ambiguous'       => $ambiguo ? 1 : 0,
        ':error_message'   => $error,
        ':last_error_code' => $codigoError,
        ':attempts'        => $attempts,
        ':invoiced'        => $status === 'FACTURADO' ? gmdate('Y-m-d H:i:s') : null,
    ]);

    return $uuid;
};

/** Crea un item válido y devuelve su cuerpo decodificado. */
$crearItem = static function (string $title, ?string $description = null) use ($request, $authHeader, $json, $decodeBody): array {
    $response = $request('POST', '/api/items', $json([
        'title'       => $title,
        'description' => $description,
    ]), [$authHeader, 'Content-Type: application/json']);

    return $decodeBody($response['body']);
};

/** Cuenta los items existentes antes de empezar, para comprobar la limpieza. */
$inicialTotal = (int) ($decode($request('GET', '/api/items', null, [$authHeader])['body'])['data']['total'] ?? -1);

if ($inicialTotal < 0) {
    echo "  [AVISO] La API no responde. ¿Está Apache en marcha?\n";
    exit(1);
}

// -----------------------------------------------------------------------------
echo "== Autenticación ==\n";
// -----------------------------------------------------------------------------

$response = $request('POST', '/api/items', '{}');
$assert('sin credenciales -> 401', $response['status'] === 401, "status={$response['status']}");

$badAuth = 'Authorization: Basic ' . base64_encode('admin:clave-equivocada');
$response = $request('GET', '/api/items', null, [$badAuth]);
$assert('credenciales incorrectas -> 401', $response['status'] === 401, "status={$response['status']}");

$response = $request('GET', '/api/items', null, [$authHeader]);
$assert('credenciales correctas -> 200', $response['status'] === 200, "status={$response['status']}");

// -----------------------------------------------------------------------------
echo "\n== Encabezados y formato ==\n";
// -----------------------------------------------------------------------------

// Con credenciales: sin `Content-Type` JSON la API rechaza el cuerpo. Sin
// credenciales respondería `401` antes, porque la autenticación va primero.
$response = $request('POST', '/api/items', $json(['title' => 'Sin tipo de contenido']), [$authHeader]);
$assert('con credenciales pero sin Content-Type JSON -> 400', $response['status'] === 400, "status={$response['status']}");

$response = $request('POST', '/api/items', $json(['title' => 'Con tipo de contenido']), [
    $authHeader,
    'Content-Type: application/json',
]);
$assert('con credenciales y Content-Type JSON -> 201', $response['status'] === 201, "status={$response['status']}");
$creadoTemporal = $decodeBody($response['body']);
$request('DELETE', '/api/items/' . (string) ($creadoTemporal['data']['uuid'] ?? ''), null, [$authHeader]);

$response = $request(
    'POST',
    '/api/items',
    '{title: roto}',
    [$authHeader, 'Content-Type: application/json']
);
$assert('JSON malformado en items -> 400', $response['status'] === 400, "status={$response['status']}");

$response = $request('OPTIONS', '/api/items', null, [$authHeader]);
$assert('preflight OPTIONS -> 204', $response['status'] === 204, "status={$response['status']}");

$response = $request(
    'GET',
    '/api/items',
    null,
    [$authHeader, 'Origin: http://localhost:5173']
);
$assert('un origen permitido no rompe la respuesta', $response['status'] === 200);

// -----------------------------------------------------------------------------
echo "\n== CRUD de items ==\n";
// -----------------------------------------------------------------------------

$titulo    = 'Prueba funcional ' . bin2hex(random_bytes(3));
$creado    = $crearItem($titulo, 'Descripción de la prueba');
$uuidItem  = (string) ($creado['data']['uuid'] ?? '');

$assert('crear item -> 201', ($creado['success'] ?? false) === true, json_encode($creado));
$assert('el item creado trae un UUID v4', App\Core\Uuid::isValidV4($uuidItem), "uuid={$uuidItem}");
$assert('el item creado conserva el título', ($creado['data']['title'] ?? '') === $titulo);
$assert('el item creado no expone el identificador interno', !array_key_exists('id', (array) ($creado['data'] ?? [])));
$assert('el item creado no expone el payload crudo', !array_key_exists('payload', (array) ($creado['data'] ?? [])));

$response = $request('GET', "/api/items/{$uuidItem}", null, [$authHeader]);
$leido    = $decodeBody($response['body']);
$assert('leer item -> 200', $response['status'] === 200, "status={$response['status']}");
$assert('el item leído conserva su UUID', ($leido['data']['uuid'] ?? '') === $uuidItem);

$uuidInexistente = sprintf(
    '%08x-%04x-4%03x-%04x-%012x',
    random_int(0, 0xFFFFFFFF),
    random_int(0, 0xFFFF),
    random_int(0, 0xFFF),
    random_int(0x8000, 0xBFFF),
    random_int(0, 0xFFFFFFFFFFFF)
);
$response = $request('GET', "/api/items/{$uuidInexistente}", null, [$authHeader]);
$assert('leer un UUID inexistente -> 404', $response['status'] === 404, "status={$response['status']}");

// Un UUID con formato válido pero inexistente es `404`; uno mal formado se
// rechaza antes con `400`, porque la API valida el formato de la ruta.
$response = $request('GET', '/api/items/no-es-un-uuid', null, [$authHeader]);
$malformado = $decodeBody($response['body']);
$assert('leer un UUID malformado -> 400', $response['status'] === 400, "status={$response['status']}");
$assert('el 400 nombra el campo uuid', isset($malformado['errors']['uuid']), 'body=' . $response['body']);

$response = $request('PUT', "/api/items/{$uuidItem}", $json(['description' => 'Descripción editada']), [
    $authHeader,
    'Content-Type: application/json',
]);
$actualizado = $decodeBody($response['body']);
$assert('actualizar item -> 200', $response['status'] === 200, "status={$response['status']}");
$assert('la actualización conserva el título', ($actualizado['data']['title'] ?? '') === $titulo);
$assert('la actualización aplica la descripción', ($actualizado['data']['description'] ?? '') === 'Descripción editada');

$response = $request('PUT', '/api/items/no-es-un-uuid', $json(['title' => 'X']), [
    $authHeader,
    'Content-Type: application/json',
]);
$assert('actualizar un UUID malformado -> 400', $response['status'] === 400, "status={$response['status']}");

// --- Validación de campos -----------------------------------------------------
$casosValidacion = [
    'sin título' => [[], 'title'],
    'título vacío' => [['title' => ''], 'title'],
    'tipo de título incorrecto' => [['title' => 12345], 'title'],
    'título demasiado largo' => [['title' => str_repeat('a', 256)], 'title'],
    'descripción no string' => [['title' => 'X', 'description' => ['array']], 'description'],
];

foreach ($casosValidacion as $nombre => $caso) {
    $response = $request('POST', '/api/items', $json($caso[0]), [$authHeader, 'Content-Type: application/json']);
    $body     = $decodeBody($response['body']);

    $assert($nombre . ' -> 400', $response['status'] === 400, "status={$response['status']}");
    $assert($nombre . ' nombra el campo culpable', isset($body['errors'][$caso[1]]));
}

$response = $request('POST', '/api/items', $json(['title' => 'X', 'campoRaro' => 1]), [
    $authHeader,
    'Content-Type: application/json',
]);
$body = $decodeBody($response['body']);
// El recurso `items` rechaza los campos desconocidos con una lista plana de
// nombres, mientras que el contrato de facturación los agrupa por campo como el
// resto de sus validaciones. Se fija aquí el comportamiento real de cada uno para
// que un cambio futuro sea deliberado.
$assert('un campo desconocido en items -> 400', $response['status'] === 400, "status={$response['status']}");
$assert(
    'items informa el campo desconocido como lista de nombres',
    in_array('campoRaro', (array) ($body['errors'] ?? []), true),
    'body=' . $response['body']
);

// Los errores se acumulan: se informe de todos a la vez, no uno por petición.
$response = $request('POST', '/api/items', $json([]), [$authHeader, 'Content-Type: application/json']);
$body     = $decodeBody($response['body']);
$assert('la respuesta de validación tiene forma success/message/data/errors',
    array_keys($body) === ['success', 'message', 'data', 'errors'], json_encode(array_keys($body)));

// --- Listado y paginación -----------------------------------------------------
$listado = $decodeBody($request('GET', '/api/items?limit=2', null, [$authHeader])['body']);
$assert('listar items -> 200', isset($listado['data']['items']));
$assert('el listado respeta el límite pedido', count((array) ($listado['data']['items'] ?? [])) <= 2);
$assert('el listado informa del total', isset($listado['data']['total']));
$assert('el listado informa del límite aplicado', ($listado['data']['limit'] ?? 0) === 2);

$acotado = $decodeBody($request('GET', '/api/items?limit=9999', null, [$authHeader])['body']);
$assert('un límite excesivo se acota al máximo', ($acotado['data']['limit'] ?? 0) <= 50);

$desplazado = $decodeBody($request('GET', '/api/items?limit=2&offset=1', null, [$authHeader])['body']);
$assert('el desplazamiento se aplica', count((array) ($desplazado['data']['items'] ?? [])) <= 2);

// --- Borrado ------------------------------------------------------------------
$response = $request('DELETE', "/api/items/{$uuidItem}", null, [$authHeader]);
$assert('eliminar item -> 200', $response['status'] === 200, "status={$response['status']}");

$response = $request('DELETE', "/api/items/{$uuidItem}", null, [$authHeader]);
$assert('eliminar dos veces -> 404', $response['status'] === 404, "status={$response['status']}");

// -----------------------------------------------------------------------------
echo "\n== Facturación: contrato de la orden ==\n";
// -----------------------------------------------------------------------------

$response = $request('POST', '/api/facturacion/ordenes', '{}');
$assert('facturar sin credenciales -> 401', $response['status'] === 401, "status={$response['status']}");

$factOrders = static function (string $codigo, array $extras = []) use ($request, $authHeader, $json): array {
    $orden = array_merge([
        'codigo_orden' => $codigo,
        'cliente'      => [
            'nit'       => 'C/F',
            'nombre'    => 'Cliente de Prueba',
            'direccion' => 'Zona 1, calle 2, casa 3',
        ],
        'carrito' => [
            [
                'codigo'          => 'SRV-001',
                'descripcion'     => 'Consulta odontológica',
                'cantidad'        => 1,
                'precio_unitario' => 100.00,
            ],
        ],
        'facturacion' => ['tipo_documento' => 'FACT'],
    ], $extras);

    return $request('POST', '/api/facturacion/ordenes', $json($orden), [$authHeader, 'Content-Type: application/json']);
};

$response = $request('POST', '/api/facturacion/ordenes', 'no es json', [$authHeader, 'Content-Type: application/json']);
$assert('JSON malformado en facturación -> 400', $response['status'] === 400, "status={$response['status']}");

$casosOrden = [
    'carrito vacío' => ['carrito' => []],
    'sin cliente' => ['cliente' => null],
    'cliente sin NIT' => ['cliente' => ['nombre' => 'X', 'direccion' => 'Y']],
    'sin código de orden' => ['codigo_orden' => ''],
    'sin facturación' => ['facturacion' => null],
];

foreach ($casosOrden as $nombre => $caso) {
    $codigo = 'HTTP-VAL-' . bin2hex(random_bytes(3));
    $response = $factOrders($codigo, $caso);
    $assert($nombre . ' -> 400', $response['status'] === 400, "status={$response['status']} body=" . substr($response['body'], 0, 160));
}

$response = $factOrders('HTTP-VALID-' . bin2hex(random_bytes(3)), ['campoRaro' => 1]);
$body     = $decodeBody($response['body']);
$assert('campo no permitido en la orden -> 400', $response['status'] === 400, "status={$response['status']}");
$assert('el error nombra el campo no permitido', isset($body['errors']['campoRaro']), 'body=' . $response['body']);

$response = $factOrders('HTTP-RESP-' . bin2hex(random_bytes(3)), ['tipo_respuesta' => 'X']);
$body     = $decodeBody($response['body']);
$assert('tipo_respuesta inválido -> 400', $response['status'] === 400, "status={$response['status']} body=" . $response['body']);
$assert('el error nombra tipo_respuesta', isset($body['errors']['tipo_respuesta']), 'body=' . $response['body']);

// Una orden incompleta informa de todos los campos que faltan de una vez, para
// que el POS no tenga que descubrir los errores de uno en uno.
$incompleta = $decodeBody($factOrders('HTTP-INCOMP-' . bin2hex(random_bytes(3)), [
    'cliente' => null,
    'carrito' => [],
])['body']);
$assert('una orden incompleta informa de todos los campos a la vez',
    isset($incompleta['errors']['cliente']) && isset($incompleta['errors']['carrito']),
    'body=' . $json($incompleta));

$response = $request('GET', '/api/facturacion/ordenes/' . $uuidInexistente, null, [$authHeader]);
$assert('consultar un UUID inexistente -> 404', $response['status'] === 404, "status={$response['status']}");

$response = $request('GET', '/api/facturacion/ordenes/no-es-un-uuid', null, [$authHeader]);
$assert('consultar un UUID malformado -> 404', $response['status'] === 404, "status={$response['status']}");

// -----------------------------------------------------------------------------
echo "\n== Idempotencia ==\n";
// -----------------------------------------------------------------------------
// Las órdenes se siembran porque emitir de verdad exigiría llamar al proveedor.

$codigoFacturado = 'HTTP-IDEM-FACT-' . bin2hex(random_bytes(4));
$uuidFacturado   = $seedOrder($codigoFacturado, 'FACTURADO');
$codigoEnProceso  = 'HTTP-IDEM-PROC-' . bin2hex(random_bytes(4));
$uuidEnProceso    = $seedOrder($codigoEnProceso, 'EN_PROCESO');

$response = $factOrders($codigoFacturado);
$assert(
    'una orden ya FACTURADA no se vuelve a facturar -> 409',
    $response['status'] === 409,
    "status={$response['status']} body={$response['body']}"
);

$conflict = $decodeBody($response['body']);
$assert(
    'el 409 explica que ya existe la factura',
    stripos((string) ($conflict['message'] ?? ''), 'ya cuenta con la factura') !== false,
    (string) ($conflict['message'] ?? '')
);

$response = $factOrders($codigoEnProceso);
$assert(
    'una orden EN_PROCESO no se vuelve a procesar -> 409',
    $response['status'] === 409,
    "status={$response['status']} body=" . substr($response['body'], 0, 160)
);
$assert(
    'el 409 distingue el proceso en curso de la factura existente',
    stripos((string) ($decodeBody($response['body'])['message'] ?? ''), 'siendo procesada') !== false,
    (string) ($decodeBody($response['body'])['message'] ?? '')
);

$response = $request('GET', "/api/facturacion/ordenes/{$uuidFacturado}", null, [$authHeader]);
$assert('consultar una orden sembrada -> 200', $response['status'] === 200, "status={$response['status']}");
$assert('la orden sembrada aparece como FACTURADA', ($decodeBody($response['body'])['data']['estado'] ?? '') === 'FACTURADO');
$assert('la consulta no expone la copia interna del pedido', !array_key_exists('payload', (array) ($decodeBody($response['body'])['data'] ?? [])));
$assert('la consulta no expone el identificador interno', !array_key_exists('id', (array) ($decodeBody($response['body'])['data'] ?? [])));

$response = $request('GET', "/api/facturacion/ordenes/{$uuidEnProceso}", null, [$authHeader]);
$assert('consultar una orden en proceso -> 200 con EN_PROCESO', ($decodeBody($response['body'])['data']['estado'] ?? '') === 'EN_PROCESO');

// -----------------------------------------------------------------------------
echo "\n== Contingencia del proveedor (HU-3) ==\n";
// -----------------------------------------------------------------------------

// El POS consulta una orden que quedó a medias y necesita saber qué hacer con
// ella. Estos campos no existían antes de HU-3 y sin ellos el POS solo podía
// deducirlo del texto del mensaje.
$codigoContingencia = 'HTTP-CONTING-' . bin2hex(random_bytes(4));
$uuidContingencia   = $seedOrder(
    $codigoContingencia,
    'PENDIENTE_REINTENTO',
    true,
    'El proveedor no está disponible.',
    '82',
    2
);

$response  = $request('GET', "/api/facturacion/ordenes/{$uuidContingencia}", null, [$authHeader]);
$ordenBody = $decodeBody($response['body'])['data'] ?? [];

$assert('una orden en contingencia se consulta -> 200', $response['status'] === 200, "status={$response['status']}");
$assert('la consulta expone el estado de contingencia', ($ordenBody['estado'] ?? '') === 'PENDIENTE_REINTENTO');
$assert('la consulta indica que se puede reintentar', ($ordenBody['reintentable'] ?? null) === true);
$assert('la consulta indica que el desenlace es ambiguo', ($ordenBody['ambiguo'] ?? null) === true);
$assert('la consulta expone el código de error del proveedor', ($ordenBody['codigo_error'] ?? '') === '82');
$assert('la consulta conserva el número de intentos', ($ordenBody['intentos'] ?? 0) === 2);

// Todo fallo registrado lleva el contexto con su UUID, para que el POS pueda
// localizar la operación aunque lo que necesite sea corregir los datos.
$conflict = $decodeBody($request('GET', "/api/facturacion/ordenes/{$uuidEnProceso}", null, [$authHeader])['body']);
$assert('la consulta no inventa un contexto de fallo', !array_key_exists('contexto', (array) $conflict));

$response  = $factOrders($codigoFacturado);
$conflict  = $decodeBody($response['body']);
$assert('el 409 de orden facturada incluye el UUID de la operación',
    ($conflict['contexto']['uuid'] ?? '') === $uuidFacturado, "body=" . substr($response['body'], 0, 200));
$assert('el contexto indica el estado real de la orden',
    ($conflict['contexto']['estado'] ?? '') === 'FACTURADO');
$assert('el contexto indica que una orden facturada no se reintenta',
    ($conflict['contexto']['reintentable'] ?? null) === false);
$assert('el contexto incluye la orden completa',
    ($conflict['contexto']['orden']['codigo_orden'] ?? '') === $codigoFacturado);

// -----------------------------------------------------------------------------
echo "\n== Reintento seguro (HU-4) ==\n";
// -----------------------------------------------------------------------------
// Todos estos casos se resuelven **sin llamar al proveedor**: la política los
// bloquea antes de emitir, que es justamente lo que debe comprobarse.

$response = $request('POST', "/api/facturacion/ordenes/{$uuidInexistente}/reintento", null, [$authHeader]);
$assert('reintentar un UUID inexistente -> 404', $response['status'] === 404, "status={$response['status']}");

// CA4.7: nunca llegó al proveedor, no hay documento que reenviar.
$codigoNunca = 'HTTP-NUNCA-' . bin2hex(random_bytes(4));
$uuidNunca   = $seedOrder($codigoNunca, 'PENDIENTE');
$response    = $request('POST', "/api/facturacion/ordenes/{$uuidNunca}/reintento", null, [$authHeader]);
$nunca       = $decodeBody($response['body']);

$assert('reintentar una orden nunca enviada -> 409', $response['status'] === 409, "status={$response['status']} body=" . substr($response['body'], 0, 160));
$assert('el 409 indica que se reenvíe por el endpoint de alta',
    stripos((string) ($nunca['message'] ?? ''), 'POST /api/facturacion/ordenes') !== false);
$assert('el 409 expone el motivo del bloqueo',
    ($nunca['contexto']['orden']['reintento']['motivo'] ?? '') === 'nunca_enviada');

// CA4.4: la espera progresiva bloquea un reintento prematuro y dice cuánto falta.
$codigoPronto = 'HTTP-PRONTO-' . bin2hex(random_bytes(4));
$uuidPronto   = $seedOrder($codigoPronto, 'PENDIENTE_REINTENTO', true, 'El proveedor no está disponible.', '82', 1);
$response     = $request('POST', "/api/facturacion/ordenes/{$uuidPronto}/reintento", null, [$authHeader]);
$pronto       = $decodeBody($response['body']);

$assert('un reintento antes de la espera -> 429', $response['status'] === 429, "status={$response['status']} body=" . substr($response['body'], 0, 160));
$assert('el 429 dice cuántos segundos faltan',
    (int) ($pronto['contexto']['orden']['reintento']['esperar_segundos'] ?? 0) > 0);
$assert('el 429 expone el UUID de la orden',
    ($pronto['contexto']['uuid'] ?? '') === $uuidPronto);

// CA4.5: una orden ya resuelta no se reintenta.
$response  = $request('POST', "/api/facturacion/ordenes/{$uuidFacturado}/reintento", null, [$authHeader]);
$reintentada = $decodeBody($response['body']);

$assert('reintentar una orden facturada -> 409', $response['status'] === 409, "status={$response['status']}");
$assert('el 409 recuerda que ya existe la factura',
    stripos((string) ($reintentada['message'] ?? ''), 'ya cuenta con la factura') !== false);
$assert('el bloqueo declara el motivo',
    ($reintentada['contexto']['orden']['reintento']['motivo'] ?? '') === 'ya_facturada');

// CA4.9: la consulta orienta al POS sin que tenga que deducir la espera.
$response  = $request('GET', "/api/facturacion/ordenes/{$uuidPronto}", null, [$authHeader]);
$situacion = $decodeBody($response['body'])['data']['reintento'] ?? [];

$assert('la consulta incluye la situación de reintento', is_array($situacion) && $situacion !== [], json_encode($situacion));
$assert('la consulta dice si se puede reintentar',
    array_key_exists('permitido', (array) $situacion));
$assert('la consulta dice cuántos intentos quedan',
    array_key_exists('intentos_restantes', (array) $situacion));

// Un fallo de validación no registra ninguna operación, así que no debe
// inventar un contexto.
$invalido = $decodeBody($request('POST', '/api/facturacion/ordenes', $json(['codigo_orden' => 'X']), [
    $authHeader,
    'Content-Type: application/json',
])['body']);
$assert('un fallo de validación no inventa un UUID de operación',
    !array_key_exists('contexto', (array) $invalido), 'body=' . $json($invalido));

// -----------------------------------------------------------------------------
echo "\n== Anulación de facturas (HU-5) ==\n";
// -----------------------------------------------------------------------------
// Estos casos no llegan a llamar al proveedor: se bloquean por estado o por
// validación, que es justamente lo que hay que comprobar por HTTP.

$response = $request('POST', "/api/facturacion/ordenes/{$uuidInexistente}/anular",
    $json(['motivo' => 'Error de digitacion']), [$authHeader, 'Content-Type: application/json']);
$assert('anular un UUID inexistente -> 404', $response['status'] === 404, "status={$response['status']}");

$response = $request('POST', "/api/facturacion/ordenes/{$uuidEnProceso}/anular",
    $json(['motivo' => 'Error de digitacion']), [$authHeader, 'Content-Type: application/json']);
$assert('anular una orden no facturada -> 409', $response['status'] === 409, "status={$response['status']}");
$assert('el 409 de anulación nombra el estado real',
    stripos((string) ($decodeBody($response['body'])['message'] ?? ''), 'EN_PROCESO') !== false);

$response = $request('POST', "/api/facturacion/ordenes/{$uuidFacturado}/anular",
    $json(['motivo' => 'Error de digitacion']), [$authHeader, 'Content-Type: application/json']);
$mensaje = (string) ($decodeBody($response['body'])['message'] ?? '');

// La orden sembrada está FACTURADA pero no conserva serie ni preimpreso, que es lo
// que el proveedor necesita para identificar el documento. Debe rechazarse por eso y
// no intentar una llamada que fallaría.
$assert('una factura sin serie ni preimpreso no se puede anular -> 409', $response['status'] === 409, "status={$response['status']}");
$assert('el 409 explica que faltan serie y preimpreso',
    stripos($mensaje, 'serie') !== false && stripos($mensaje, 'preimpreso') !== false, $mensaje);

// El motivo se valida antes de cualquier otra cosa.
$codigoMotivo = 'HTTP-ANULAR-' . bin2hex(random_bytes(4));
$uuidMotivo   = $seedOrder($codigoMotivo, 'FACTURADO', false, null, null, 1);
$pdo->prepare('UPDATE gf_orders SET payload = :payload, serie = :serie, preimpreso = :preimpreso WHERE uuid = :u')
    ->execute([
        ':payload'    => json_encode(['cliente' => ['nit' => 'C/F']], JSON_UNESCAPED_UNICODE),
        ':serie'       => 'F0123',
        ':preimpreso' => '4567',
        ':u'          => $uuidMotivo,
    ]);

$casosMotivo = [
    'ausente' => [],
    'vacío'   => ['motivo' => '   '],
    'de un carácter' => ['motivo' => 'x'],
];

foreach ($casosMotivo as $nombre => $cuerpo) {
    $response = $request('POST', "/api/facturacion/ordenes/{$uuidMotivo}/anular",
        $json($cuerpo), [$authHeader, 'Content-Type: application/json']);

    $assert('anular con motivo ' . $nombre . ' -> 400', $response['status'] === 400, "status={$response['status']}");
    $assert('anular con motivo ' . $nombre . ' nombra el campo',
        isset($decodeBody($response['body'])['errors']['motivo']));
}

// Sin Content-Type JSON la autenticación va primero: sin credenciales la respuesta
// es 401, y con credenciales pero sin cabecera es 400.
$response = $request('POST', "/api/facturacion/ordenes/{$uuidMotivo}/anular", 'sin tipo');
$assert('anular sin credenciales -> 401', $response['status'] === 401, "status={$response['status']}");

$response = $request('POST', "/api/facturacion/ordenes/{$uuidMotivo}/anular", 'sin tipo', [$authHeader]);
$assert('anular con credenciales pero sin Content-Type JSON -> 400', $response['status'] === 400, "status={$response['status']}");

// -----------------------------------------------------------------------------
echo "\n== Limpieza ==\n";
// -----------------------------------------------------------------------------

$deleteSeed = $pdo->prepare('DELETE FROM gf_orders WHERE uuid IN (:a, :b, :c, :d, :e, :f)');
$deleteSeed->execute([
    ':a' => $uuidFacturado,
    ':b' => $uuidEnProceso,
    ':c' => $uuidContingencia,
    ':d' => $uuidNunca,
    ':e' => $uuidPronto,
    ':f' => $uuidMotivo,
]);
$assert(
    'limpieza de las órdenes sembradas',
    $deleteSeed->rowCount() === 6,
    'filas=' . $deleteSeed->rowCount()
);

$totalFinal = (int) ($decode($request('GET', '/api/items', null, [$authHeader])['body'])['data']['total'] ?? -1);
$assert('la suite no deja filas huérfanas', $totalFinal === $inicialTotal, "inicial={$inicialTotal} final={$totalFinal}");

echo "\n----------------------------------------\n";
echo sprintf("Pasaron: %d | Fallaron: %d\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);