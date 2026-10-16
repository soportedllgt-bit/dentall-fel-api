<?php

declare(strict_types=1);

/**
 * Matriz de contingencias y reintentos (HU-3 y HU-4).
 *
 * ## Qué prueba y por qué existe
 *
 * La contingencia del proveedor es la parte del sistema que no se puede probar
 * esperando a que el proveedor falle. Con el servicio real solo hay dos formas de
 * provocarla: esperar a que se caiga, o cortar la red. Ninguna es utilizable en
 * una suite.
 *
 * Y no basta con probar el camino feliz. El defecto que corrige HU-3 no es una
 * ausencia de código, sino código que hace lo contrario de lo que dice: una caída
 * del proveedor se traducía a `422 «El proveedor rechazó la factura»`. Esa línea
 * se puede escribir en cinco minutos y pasa todos los ejemplos. Solo se detecta al
 * exigir que la respuesta diga la verdad sobre lo ocurrido.
 *
 * ## Qué cubre
 *
 * | Historia | Casos                                                          |
 * |----------|----------------------------------------------------------------|
 * | CA3.1    | Digest de la factura autorizada                                |
 * | CA3.2    | Rechazo, `5xx`, timeout, respuesta vacía, error 82, sin credenciales |
 * | CA3.3    | UUID explícito, consulta posterior y reintento desde el POS    |
 * | CA4.1    | El reintento envía el XML almacenado, sin reconstruirlo         |
 * | CA4.2    | La idempotencia de la `Referencia` evita la segunda factura     |
 * | CA4.3    | El desenlace ambiguo queda resuelto                             |
 * | CA4.4    | La espera progresiva bloquea un reintento prematuro             |
 * | CA4.5    | `FACTURADO`, `ANULADO` y `EN_PROCESO` no se reintentan         |
 * | CA4.6    | El tope de intentos pide revisión manual                        |
 * | CA4.7    | Una orden nunca enviada se reenvía por el endpoint de alta     |
 * | CA4.8    | El fallo del reintento también se persiste                     |
 * | CA4.9    | La política informa si se puede reintentar y cuánto falta      |
 *
 * Requiere la base de datos pero **no** la red ni el servidor web.
 * Se ejecuta con `php tests/contingencia.php` desde la raíz del proyecto.
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

use App\Core\AppConfig;
use App\Core\Uuid;
use App\Integrations\Guatefacturas\CommunicationException;
use App\Integrations\Guatefacturas\ConfigurationException;
use App\Integrations\Guatefacturas\DocumentEmitter;
use App\Integrations\Guatefacturas\GuatefacturasClient;
use App\Integrations\Guatefacturas\ProviderBusinessException;
use App\Integrations\Guatefacturas\ProviderResponseClassifier;
use App\Models\GfOrder;
use App\Repositories\GfOrderRepository;
use App\Services\AnulacionService;
use App\Services\FacturacionService;
use App\Services\ReintentoService;
use App\Services\RetryPolicy;

$passed = 0;
$failed = 0;

/**
 * Comprueba una aserción y registra el resultado.
 *
 * @param string $name      Descripción de la aserción.
 * @param bool   $condition Resultado esperado.
 *
 * @return void
 */
$assert = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  [OK]   {$name}\n";
        return;
    }

    $failed++;
    echo "  [FALLA] {$name}\n";
};

/**
 * Emisor de documentos que se comporta como se le ordene.
 *
 * Sustituye a `GuatefacturasClient`, que es `final` y habla SOAP de verdad. Cada
 * instancia lanza una excepción o devuelve un texto fijo, de modo que la prueba
 * controla por completo qué falla y comprueba qué se responde y qué queda
 * guardado.
 *
 * @internal Solo para pruebas.
 */
final class EmisorSimulado implements DocumentEmitter
{
    /**
     * Excepción a lanzar en la siguiente llamada.
     *
     * @var \Throwable|null
     */
    private $failure;

    /**
     * Texto a devolver cuando no hay excepción configurada.
     *
     * @var string
     */
    private $response;

    /**
     * Número de llamadas recibidas.
     *
     * @var int
     */
    public $calls = 0;

    /**
     * XML recibido en la última llamada.
     *
     * @var string|null
     */
    public $lastXml;

    /**
     * @param string          $response Texto de una emisión correcta.
     * @param \Throwable|null $failure  Fallo a simular.
     */
    public function __construct(string $response, ?\Throwable $failure = null)
    {
        $this->response = $response;
        $this->failure  = $failure;
    }

    /**
     * Cambia el comportamiento a partir de la siguiente llamada.
     *
     * @param string          $response Texto de una emisión correcta.
     * @param \Throwable|null $failure  Fallo a simular.
     *
     * @return void
     */
    public function willReturn(string $response, ?\Throwable $failure = null): void
    {
        $this->response = $response;
        $this->failure  = $failure;
    }

    /**
     * @param int    $documentType Tipo de documento.
     * @param string $xml          XML enviado.
     * @param string $responseType Tipo de respuesta.
     *
     * @return string Respuesta simulada.
     */
    public function generaDocumento(int $documentType, string $xml, string $responseType = 'R'): string
    {
        $this->calls++;
        $this->lastXml = $xml;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response;
    }

    /**
     * Parámetros con los que se pidió la anulación, en la última llamada.
     *
     * @var array<string, string>|null
     */
    public $lastAnulment;

    /**
     * Número de anulaciones recibidas.
     *
     * @var int
     */
    public $annulmentCalls = 0;

    /**
     * Excepción a lanzar en la próxima anulación.
     *
     * @var \Throwable|null
     */
    private $annulmentFailure = null;

    /**
     * Configura el fallo que se producirá en la próxima anulación.
     *
     * @param \Throwable|null $failure Fallo a simular.
     *
     * @return void
     */
    public function willFailAnulment(?\Throwable $failure): void
    {
        $this->annulmentFailure = $failure;
    }

    /**
     * @param string $series        Serie autorizada.
     * @param string $preimpreso    Número correlativo.
     * @param string $buyerNit      NIT del comprador.
     * @param string $annulmentDate Fecha en `AAAAMMDD`.
     * @param string $reason        Motivo de la anulación.
     *
     * @return string Respuesta simulada.
     */
    public function anulaDocumento(
        string $series,
        string $preimpreso,
        string $buyerNit,
        string $annulmentDate,
        string $reason
    ): string {
        $this->annulmentCalls++;
        $this->lastAnulment = [
            'serie'        => $series,
            'preimpreso'   => $preimpreso,
            'nit'          => $buyerNit,
            'fecha'        => $annulmentDate,
            'motivo'       => $reason,
        ];

        if ($this->annulmentFailure !== null) {
            throw $this->annulmentFailure;
        }

        return '<RESULTADO>ANULACION REALIZADA</RESULTADO>';
    }
}

// -----------------------------------------------------------------------------
// Arranque
// -----------------------------------------------------------------------------
$config = new AppConfig(require dirname(__DIR__) . '/config/config.php');

try {
    $database   = new App\Core\Database($config);
    $repository = new GfOrderRepository($database);
    $pdo        = $database->getConnection();
} catch (Throwable $exception) {
    echo "  [AVISO] No hay base de datos disponible: " . $exception->getMessage() . "\n";
    echo "  Se omite la matriz de contingencia.\n";
    exit(0);
}

$repository->truncate();

/** Respuesta de una emisión autorizada, con la forma del proveedor. */
$respuestaEmitida = '<Resultado><Serie>F0123</Serie><Preimpreso>4567</Preimpreso>'
    . '<NumeroAutorizacion>9F3A1C7E5B2D8406</NumeroAutorizacion>'
    . '<Referencia>ORDEN-HU3-0001</Referencia></Resultado>';

/** Construye un servicio de emisión sobre un emisor dado. */
$serviceWith = static function (DocumentEmitter $emitter) use ($repository, $config): FacturacionService {
    return new FacturacionService($repository, $emitter, $config);
};

/** Construye un servicio de reintento sobre un emisor dado. */
$retryWith = static function (DocumentEmitter $emitter) use ($repository, $config): ReintentoService {
    return new ReintentoService($repository, $emitter, RetryPolicy::fromRules($config->getBillingConfig()));
};

/**
 * Extrae la orden persistida que viaja en el contexto de una respuesta de fallo.
 *
 * En un fallo el sobre de éxito lleva `data` a `null` y deposita la orden en
 * `contexto.orden`: `data` describe el resultado de la operación, que no existe,
 * y el contexto describe la operación sí registrada.
 *
 * @param array<string, mixed> $resultado Sobre devuelto por el servicio.
 *
 * @return array<string, mixed> Orden persistida, o vacío si no la hay.
 */
$guardada = static function (array $resultado): array {
    return $resultado['context']['orden'] ?? [];
};

/** Orden de prueba mínima y válida. */
$orden = static function (string $codigo): array {
    return [
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
                'cantidad'        => 2,
                'precio_unitario' => 250.00,
                'porcentaje_iva'  => 12,
            ],
        ],
        'facturacion' => ['tipo_documento' => 'FACT'],
    ];
};

/** Borra todas las órdenes sembradas para aislar cada caso. */
$clean = static function () use ($repository): void {
    $repository->truncate();
};

/**
 * Siembra una orden con un estado y una antigüedad concretos.
 *
 * Las marcas de tiempo se escriben con `gmdate()` porque la aplicación las guarda
 * en UTC, mientras que el servidor de MySQL corre en hora de Guatemala.
 *
 * @param string      $code        Código único de la orden.
 * @param string      $status      Estado a sembrar.
 * @param bool        $ambiguo     Marca la orden como ambigua.
 * @param int         $attempts    Número de intentos registrados.
 * @param int         $edadSegundos Antigüedad de la última actualización.
 * @param string|null $xml         XML enviado, o `null` para simular que nunca salió.
 *
 * @return GfOrder Orden sembrada.
 */
$preparar = static function (
    string $code,
    string $status,
    bool $ambiguo,
    int $attempts,
    int $edadSegundos,
    ?string $xml = null
) use ($pdo, $repository): GfOrder {
    $uuid = sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xFFFFFFFF),
        random_int(0, 0xFFFF),
        random_int(0, 0xFFF),
        random_int(0x8000, 0xBFFF),
        random_int(0, 0xFFFFFFFFFFFF)
    );

    $marca = gmdate('Y-m-d H:i:s', time() - $edadSegundos);

    // `created_at` y `updated_at` usan marcadores distintos porque la conexión
    // tiene `ATTR_EMULATE_PREPARES` desactivado, y MySQL no admite repetir un
    // marcador con nombre en una sentencia preparada nativa.
    $pdo->prepare(
        'INSERT INTO gf_orders (uuid, order_code, dte_type, status, payload, ambiguous,
                                sent_xml, attempts, created_at, updated_at)
         VALUES (:uuid, :code, 1, :status, :payload, :ambiguous,
                 :xml, :attempts, :creada, :actualizada)'
    )->execute([
        ':uuid'       => $uuid,
        ':code'       => $code,
        ':status'     => $status,
        ':payload'    => '{}',
        ':ambiguous'  => $ambiguo ? 1 : 0,
        ':xml'        => $xml,
        ':attempts'   => $attempts,
        ':creada'     => $marca,
        ':actualizada' => $marca,
    ]);

    $order = $repository->findByUuid($uuid);

    if ($order === null) {
        throw new RuntimeException('No se pudo sembrar la orden de la prueba.');
    }

    return $order;
};

/** Genera un UUID v4 válido para las comprobaciones. */
$uuidValido = static function (string $valor): bool {
    return Uuid::isValidV4($valor);
};

$maxIntentos = (int) ($config->getBillingConfig()['max_attempts'] ?? 5);

// -----------------------------------------------------------------------------
echo "== CA3.1 · Respuesta exitosa al POS ==\n";
// -----------------------------------------------------------------------------

$clean();
$emisor   = new EmisorSimulado($respuestaEmitida);
$resultado = $serviceWith($emisor)->facturar($orden('ORDEN-HU3-OK'));

$assert('la emisión correcta responde 201', $resultado['status'] === 201);
$assert('la emisión correcta marca ok', $resultado['ok'] === true);
$assert('el digest expone la serie del proveedor', $resultado['factura']['serie'] === 'F0123');
$assert('el digest expone el preimpreso', $resultado['factura']['preimpreso'] === '4567');
$assert('el digest expone la autorización de SAT', $resultado['factura']['autorizacion'] === '9F3A1C7E5B2D8406');
$assert('el digest declara el documento autorizado', $resultado['factura']['estado'] === 'AUTORIZADA');
$assert('el digest no inventa guiones en la autorización',
    strpos($resultado['factura']['autorizacion'], '-') === false);
$assert('el digest incluye un resumen imprimible',
    strpos($resultado['factura']['resumen_impresion'], 'F0123') !== false
    && strpos($resultado['factura']['resumen_impresion'], '9F3A1C7E5B2D8406') !== false);
$assert('el digest indica si hay XML firmado disponible',
    array_key_exists('xml_firmado_disponible', $resultado['factura']));
$assert('la orden queda FACTURADA', $resultado['order']['estado'] === GfOrder::STATUS_INVOICED);
$assert('la orden facturada no es reintentable', $resultado['order']['reintentable'] === false);
$assert('una orden facturada no es ambigua', $resultado['order']['ambiguo'] === false);
$assert('el total calculado es el esperado', $resultado['order']['totales']['total'] === '560.00');
$assert('la orden devuelta trae su UUID', $uuidValido((string) ($resultado['order']['uuid'] ?? '')));
$assert('solo se llamó al proveedor una vez', $emisor->calls === 1);

// -----------------------------------------------------------------------------
echo "\n== CA3.2 · Rechazo de negocio: 422 y datos que corregir ==\n";
// -----------------------------------------------------------------------------

$clean();
$rechazo = '<Resultado>56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR A LA FECHA DEL SISTEMA</Resultado>';
$emisor  = new EmisorSimulado(
    $respuestaEmitida,
    ProviderBusinessException::fromResult($rechazo)
);
$resultado = $serviceWith($emisor)->facturar($orden('ORDEN-HU3-RECH'));
$estado    = $guardada($resultado);

$assert('un rechazo de negocio responde 422', $resultado['status'] === 422);
$assert('un rechazo deja ERROR_PROVEEDOR', $estado['estado'] === GfOrder::STATUS_PROVIDER_ERROR);
$assert('un rechazo no se marca ambiguo', $estado['ambiguo'] === false);
$assert('un rechazo conserva el código del proveedor', $estado['codigo_error'] === '56');
$assert('un rechazo conserva el mensaje del proveedor',
    strpos((string) $estado['error'], 'FECHA DE DOCUMENTO') !== false);
$assert('un rechazo también es reintentable, sin duplicar', $estado['reintentable'] === true);
$assert('el contexto incluye el UUID de la operación',
    $uuidValido((string) ($resultado['context']['uuid'] ?? '')));
$assert('el contexto refleja el estado real persistido',
    $resultado['context']['estado'] === GfOrder::STATUS_PROVIDER_ERROR);

// -----------------------------------------------------------------------------
echo "\n== CA3.2 · Caída del proveedor: 503 y no un rechazo ==\n";
// -----------------------------------------------------------------------------

// Cada uno de estos casos llegaba antes a `422 «El proveedor rechazó la factura».
$caidas = [
    'un 503 del proveedor' => new CommunicationException(
        CommunicationException::KIND_TIMEOUT,
        'Error Fetching http headers [codigo HTTP]'
    ),
    'un tiempo de espera agotado' => new CommunicationException(
        CommunicationException::KIND_TIMEOUT,
        'Operation timed out after 60000 milliseconds'
    ),
    'una respuesta vacía' => new CommunicationException(
        CommunicationException::KIND_TIMEOUT,
        'El proveedor devolvió una respuesta vacía.'
    ),
    'un error 82, que puede ser límite de emisiones' => new CommunicationException(
        CommunicationException::KIND_RATE_LIMITED,
        'El proveedor respondió "82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO", '
        . 'que también aparece cuando se supera el límite de emisiones.'
    ),
    'una configuración ausente' => ConfigurationException::missingCredentials(
        ['FEL_GUATEFAC_USER']
    ),
];

foreach ($caidas as $nombre => $fallo) {
    $clean();
    $resultado = $serviceWith(new EmisorSimulado($respuestaEmitida, $fallo))
        ->facturar($orden('ORDEN-HU3-' . md5($nombre)));
    $estado = $guardada($resultado);

    $assert($nombre . ' responde 503, no 422', $resultado['status'] === 503);
    $assert($nombre . ' no acusa al documento de estar mal',
        stripos($resultado['message'], 'rechazó') === false);
    $assert($nombre . ' deja la orden en PENDIENTE_REINTENTO',
        $estado['estado'] === GfOrder::STATUS_RETRY_PENDING);
    $assert($nombre . ' se puede reintentar', $estado['reintentable'] === true);
    $assert($nombre . ' devuelve el UUID de la operación',
        $uuidValido((string) ($resultado['context']['uuid'] ?? '')));
    $assert($nombre . ' informa si el desenlace es ambiguo',
        array_key_exists('ambiguo', $estado));
}

// El error 82 es el único de los anteriores que se marca ambiguo: los demás son
// fallos de transporte cuya causa ya conocemos.
$clean();
$resultado = $serviceWith(new EmisorSimulado(
    $respuestaEmitida,
    new CommunicationException(
        CommunicationException::KIND_RATE_LIMITED,
        'El proveedor respondió "82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO".'
    )
))->facturar($orden('ORDEN-HU3-LIMITE'));
$estado = $guardada($resultado);

$assert('el error 82 se marca ambiguo', $estado['ambiguo'] === true);
$assert('el error 82 conserva su código', $estado['codigo_error'] === '82');

// -----------------------------------------------------------------------------
echo "\n== CA3.3 · El POS recibe el UUID y puede reintentar ==\n";
// -----------------------------------------------------------------------------

$clean();
$emisor  = new EmisorSimulado(
    $respuestaEmitida,
    new CommunicationException(CommunicationException::KIND_TIMEOUT, 'Connection refused')
);
$service = $serviceWith($emisor);
$resultado = $service->facturar($orden('ORDEN-HU3-REINT'));
$uuid       = (string) ($resultado['context']['uuid'] ?? '');

$assert('el UUID del contexto es un UUID v4 válido', $uuidValido($uuid));
$assert('el UUID del contexto coincide con el de la orden', $uuid === $guardada($resultado)['uuid']);
$assert('el mensaje no es el único lugar donde está el UUID', $resultado['context']['uuid'] !== '');

$consultada = $service->consultar($uuid);

$assert('el POS puede consultar la orden por UUID', $consultada !== null);
$assert('la consulta devuelve el estado de contingencia',
    $consultada['estado'] === GfOrder::STATUS_RETRY_PENDING);
$assert('la consulta informa que se puede reintentar', $consultada['reintentable'] === true);
$assert('la consulta no expone el payload del cliente', !array_key_exists('payload', $consultada));
$assert('la consulta no expone el XML enviado', !array_key_exists('sent_xml', $consultada));
$assert('contar el intento fallido', $consultada['intentos'] === 1);

$emisor->willReturn($respuestaEmitida);
$reintento = $service->facturar($orden('ORDEN-HU3-REINT'));

$assert('el reintento de la misma orden responde 201', $reintento['status'] === 201);
$assert('el reintento reutiliza el MISMO UUID de la operación', $reintento['order']['uuid'] === $uuid);
$assert('el reintento deja la orden FACTURADA', $reintento['order']['estado'] === GfOrder::STATUS_INVOICED);
$assert('el reintento suma un segundo intento', $reintento['order']['intentos'] === 2);
$assert('tras facturar, la orden ya no es ambigua', $reintento['order']['ambiguo'] === false);
$assert('tras facturar, se borra el código de error anterior', $reintento['order']['codigo_error'] === null);
$assert('tras facturar, se borra el mensaje de error anterior', $reintento['order']['error'] === null);

$repetida = $service->facturar($orden('ORDEN-HU3-REINT'));

$assert('una orden ya facturada responde 409', $repetida['status'] === 409);
$assert('la orden facturada sigue siendo la misma', $repetida['context']['uuid'] === $uuid);
$assert('una orden facturada no se vuelve a enviar', $emisor->calls === 2);

// -----------------------------------------------------------------------------
echo "\n== Idempotencia y recuperación ==\n";
// -----------------------------------------------------------------------------

$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$service = $serviceWith($emisor);
$primera = $service->facturar($orden('ORDEN-HU3-IDEM'));
$segunda = $service->facturar($orden('ORDEN-HU3-IDEM'));

$assert('reenviar la misma orden no duplica la factura', $segunda['status'] === 409);
$assert('el emisor solo recibió una llamada', $emisor->calls === 1);
$assert('el UUID es el mismo en ambos intentos',
    $primera['order']['uuid'] === $segunda['context']['uuid']);
$assert('el 409 informa que ya existe una factura',
    stripos($segunda['message'], 'ya cuenta con la factura') !== false);
$assert('el 409 incluye el número de documento', strpos($segunda['message'], 'F0123-4567') !== false);

$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$service = $serviceWith($emisor);
$service->facturar($orden('ORDEN-HU3-ANUL'));
$anulada = $repository->findByOrderCode('ORDEN-HU3-ANUL');
$pdo->prepare('UPDATE gf_orders SET status = :s WHERE uuid = :u')
    ->execute([':s' => GfOrder::STATUS_CANCELLED, ':u' => $anulada->getUuid()]);

$sobreAnulada = $service->facturar($orden('ORDEN-HU3-ANUL'));

$assert('una orden anulada responde 409', $sobreAnulada['status'] === 409);
$assert('el mensaje distingue anulación de facturación duplicada',
    stripos($sobreAnulada['message'], 'anulada') !== false);
$assert('una orden anulada no se reemite', $emisor->calls === 1);

// Orden atascada en EN_PROCESO: el proceso murió entre enviar y registrar.
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$service = $serviceWith($emisor);
$service->facturar($orden('ORDEN-HU3-ATASC'));
$atascada = $repository->findByOrderCode('ORDEN-HU3-ATASC');
$pdo->prepare('UPDATE gf_orders SET status = :s, updated_at = :u WHERE uuid = :id')
    ->execute([
        ':s'  => GfOrder::STATUS_PROCESSING,
        ':u'  => gmdate('Y-m-d H:i:s', time() - 3600),
        ':id' => $atascada->getUuid(),
    ]);

$recuperada = $service->facturar($orden('ORDEN-HU3-ATASC'));

$assert('una orden atascada en EN_PROCESO no bloquea para siempre', $recuperada['status'] === 201);
$assert('la orden atascada conserva su UUID', $recuperada['order']['uuid'] === $atascada->getUuid());

$pdo->prepare('UPDATE gf_orders SET status = :s, updated_at = :u WHERE uuid = :id')
    ->execute([
        ':s'  => GfOrder::STATUS_PROCESSING,
        ':u'  => gmdate('Y-m-d H:i:s'),
        ':id' => $atascada->getUuid(),
    ]);

$enCurso = $service->facturar($orden('ORDEN-HU3-ATASC'));

$assert('una orden en curso sí responde 409', $enCurso['status'] === 409);
$assert('el 409 por orden en curso va acompañado de su UUID',
    $uuidValido((string) ($enCurso['context']['uuid'] ?? '')));

// Tope de intentos.
$clean();
$emisor = new EmisorSimulado(
    $respuestaEmitida,
    new CommunicationException(CommunicationException::KIND_TIMEOUT, 'Connection refused')
);
$service = $serviceWith($emisor);

for ($i = 0; $i < $maxIntentos; $i++) {
    $service->facturar($orden('ORDEN-HU3-TOPE'));
}

$agotada = $service->facturar($orden('ORDEN-HU3-TOPE'));

$assert('una orden que agota sus intentos responde 409', $agotada['status'] === 409);
$assert('el 409 por intentos agotados explica que requiere revisión',
    stripos($agotada['message'], 'revisión manual') !== false);
$assert('el emisor no recibe más llamadas tras el tope', $emisor->calls === $maxIntentos);

// -----------------------------------------------------------------------------
echo "\n== CA4 · Reintento seguro ==\n";
// -----------------------------------------------------------------------------

$policy    = RetryPolicy::fromRules($config->getBillingConfig());
$xmlFalso  = '<DocElectronico><Referencia>ORDEN-HU4-0001</Referencia></DocElectronico>';

// --- CA4.1 · Reenvía el documento almacenado, sin reconstruirlo --------------
$clean();
$emisor  = new EmisorSimulado($respuestaEmitida);
$retry   = $retryWith($emisor);
$guardada1 = $preparar('HU4-ALMACENADO', GfOrder::STATUS_RETRY_PENDING, true, 1, 600, $xmlFalso);
$resultado = $retry->reintentar($guardada1->getUuid());

$assert('el reintento de un desenlace ambiguo responde 200', $resultado['status'] === 200, "status={$resultado['status']}");
$assert('el reintento envía el XML exactamente como se guardó',
    $emisor->lastXml === $xmlFalso, 'xml=' . (string) $emisor->lastXml);
$assert('el XML reenviado conserva la Referencia original',
    strpos((string) $emisor->lastXml, 'ORDEN-HU4-0001') !== false);
$assert('el reintento solo llama una vez al proveedor', $emisor->calls === 1);

// --- CA4.2 y CA4.3 · Idempotencia y resolución del desenlace ------------------
$resuelta = $repository->findByUuid($guardada1->getUuid());

$assert('tras el reintento la orden queda FACTURADA', $resuelta->getStatus() === GfOrder::STATUS_INVOICED);
$assert('la ambigüedad queda resuelta', $resuelta->isAmbiguous() === false);
$assert('la orden conserva el mismo UUID', $resuelta->getUuid() === $guardada1->getUuid());
$assert('el reintento registra un intento más', $resuelta->getAttempts() === 2);
$assert('la factura devuelta trae la serie del proveedor', $resultado['factura']['serie'] === 'F0123');
$assert('la factura devuelta trae la autorización de SAT',
    $resultado['factura']['autorizacion'] === '9F3A1C7E5B2D8406');
$assert('no se crea una segunda factura para la misma referencia',
    (int) $pdo->query("SELECT COUNT(*) FROM gf_orders WHERE order_code = 'HU4-ALMACENADO'")->fetchColumn() === 1);

// --- CA4.4 · Espera progresiva -------------------------------------------------
$clean();
$emisor  = new EmisorSimulado($respuestaEmitida);
$retry   = $retryWith($emisor);
$recien  = $preparar('HU4-PRONTO', GfOrder::STATUS_RETRY_PENDING, true, 1, 5, $xmlFalso);
$resultado = $retry->reintentar($recien->getUuid());

$assert('un reintento antes de tiempo responde 429', $resultado['status'] === 429, "status={$resultado['status']}");
$assert('el 429 informa los segundos que faltan', ($resultado['retry_after'] ?? 0) > 0);
$assert('el 429 no llama al proveedor', $emisor->calls === 0);
$assert('el 429 explica que aún no corresponde', stripos($resultado['message'], 'Aún no corresponde') !== false);
$assert('el 429 incluye el contexto de la orden',
    $uuidValido((string) ($resultado['context']['uuid'] ?? '')));
$assert('el contexto informa que el bloqueo es por la espera',
    ($resultado['context']['orden']['reintento']['motivo'] ?? '') === 'demasiado_pronto');
$assert('el orden queda sin alterar tras un 429',
    $repository->findByUuid($recien->getUuid())->getAttempts() === 1);

// Pasada la espera, el mismo reintento prospera.
$esperada = $policy->requiredWait(1);
$esperado = $preparar('HU4-ESPERA', GfOrder::STATUS_RETRY_PENDING, true, 1, $esperada + 5, $xmlFalso);
$resultado = $retry->reintentar($esperado->getUuid());

$assert('pasada la espera, el reintento se permite', $resultado['status'] === 200, "status={$resultado['status']}");

$assert('el primer reintento espera 60 segundos', $policy->requiredWait(1) === 60);
$assert('el segundo reintento espera 120 segundos', $policy->requiredWait(2) === 120);
$assert('el tercer reintento espera 300 segundos', $policy->requiredWait(3) === 300);
$assert('la espera no crece sin límite', $policy->requiredWait(9) === 600);
$assert('la espera es monótona creciente',
    $policy->requiredWait(1) < $policy->requiredWait(2)
    && $policy->requiredWait(2) < $policy->requiredWait(3));

// --- CA4.5 · Estados que no admiten reintento ---------------------------------
$bloqueos = [
    'una orden ya facturada' => [GfOrder::STATUS_INVOICED, true, 1, 600, 'ya cuenta con la factura'],
    'una orden anulada' => [GfOrder::STATUS_CANCELLED, false, 1, 600, 'fue anulada'],
    'una orden en curso' => [GfOrder::STATUS_PROCESSING, true, 1, 10, 'emisión en curso'],
];

foreach ($bloqueos as $nombre => $caso) {
    $clean();
    $emisor = new EmisorSimulado($respuestaEmitida);
    $retry  = $retryWith($emisor);
    $casoOrden = $preparar('HU4-' . md5($nombre), $caso[0], $caso[1], $caso[2], $caso[3], $xmlFalso);
    $resultado = $retry->reintentar($casoOrden->getUuid());

    $assert($nombre . ' responde 409', $resultado['status'] === 409, "status={$resultado['status']}");
    $assert($nombre . ' no llama al proveedor', $emisor->calls === 0);
    $assert($nombre . ' explica el motivo',
        stripos($resultado['message'], $caso[4]) !== false, $resultado['message']);
}

// Una orden en curso pero atascada sí puede reintentarse.
$clean();
$emisor   = new EmisorSimulado($respuestaEmitida);
$retry    = $retryWith($emisor);
$atascada = $preparar(
    'HU4-ATASCADA',
    GfOrder::STATUS_PROCESSING,
    true,
    1,
    $policy->processingTimeout() + 60,
    $xmlFalso
);
$resultado = $retry->reintentar($atascada->getUuid());

$assert('una orden en curso atascada sí se puede reintentar', $resultado['status'] === 200, "status={$resultado['status']}");

// --- CA4.6 · Tope de intentos --------------------------------------------------
$clean();
$emisor  = new EmisorSimulado($respuestaEmitida);
$retry   = $retryWith($emisor);
$agotada = $preparar('HU4-AGOTADA', GfOrder::STATUS_RETRY_PENDING, true, $maxIntentos, 3600, $xmlFalso);
$resultado = $retry->reintentar($agotada->getUuid());

$assert('una orden sin intentos disponibles responde 409', $resultado['status'] === 409, "status={$resultado['status']}");
$assert('el 409 pide revisión manual', stripos($resultado['message'], 'revisión manual') !== false);
$assert('el 409 no llama al proveedor', $emisor->calls === 0);
$assert('el motivo del bloqueo es el tope de intentos',
    ($resultado['context']['orden']['reintento']['motivo'] ?? '') === 'intentos_agotados');
$assert('el contexto informa que no quedan intentos',
    ($resultado['context']['orden']['reintento']['intentos_restantes'] ?? -1) === 0);

// --- CA4.7 · Orden que nunca llegó al proveedor -------------------------------
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$retry  = $retryWith($emisor);
$nunca  = $preparar('HU4-NUNCA', GfOrder::STATUS_PENDING, false, 0, 3600, null);
$resultado = $retry->reintentar($nunca->getUuid());

$assert('una orden sin documento enviado responde 409', $resultado['status'] === 409, "status={$resultado['status']}");
$assert('el 409 indica dónde reenviarla',
    stripos($resultado['message'], 'POST /api/facturacion/ordenes') !== false);
$assert('reintentar una orden nunca enviada no llama al proveedor', $emisor->calls === 0);
$assert('el motivo es que nunca se envió',
    ($resultado['context']['orden']['reintento']['motivo'] ?? '') === 'nunca_enviada');

// --- CA4.8 · El fallo del reintento también se persiste ------------------------
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$retry  = $retryWith($emisor);
$caida  = $preparar('HU4-CAIDA', GfOrder::STATUS_RETRY_PENDING, true, 1, 600, $xmlFalso);
$emisor->willReturn($respuestaEmitida, new CommunicationException(
    CommunicationException::KIND_TIMEOUT,
    'Connection refused'
));
$resultado = $retry->reintentar($caida->getUuid());
$trasCaida = $repository->findByUuid($caida->getUuid());

$assert('un proveedor que sigue caído responde 503', $resultado['status'] === 503);
$assert('la orden vuelve a PENDIENTE_REINTENTO', $trasCaida->getStatus() === GfOrder::STATUS_RETRY_PENDING);
$assert('la orden sigue marcada como ambigua', $trasCaida->isAmbiguous() === true);
$assert('el fallo del reintento suma un intento', $trasCaida->getAttempts() === 2);
$assert('el XML almacenado se conserva intacto', $trasCaida->getSentXml() === $xmlFalso);

// Un rechazo de negocio en el reintento se distingue de una caída.
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$retry  = $retryWith($emisor);
$rechazada = $preparar('HU4-RECHAZO', GfOrder::STATUS_RETRY_PENDING, true, 1, 600, $xmlFalso);
$emisor->willReturn($respuestaEmitida, ProviderBusinessException::fromResult(
    '<Resultado>56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR A LA FECHA DEL SISTEMA</Resultado>'
));
$resultado = $retry->reintentar($rechazada->getUuid());
$trasRechazo = $repository->findByUuid($rechazada->getUuid());

$assert('un rechazo en el reintento responde 422', $resultado['status'] === 422);
$assert('un rechazo deja ERROR_PROVEEDOR', $trasRechazo->getStatus() === GfOrder::STATUS_PROVIDER_ERROR);
$assert('un rechazo no se marca ambiguo', $trasRechazo->isAmbiguous() === false);
$assert('un rechazo conserva el código del proveedor', $trasRechazo->getLastErrorCode() === '56');

// El error 82 vuelve a tratarse como ambiguo, no como rechazo.
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$retry  = $retryWith($emisor);
$limitada = $preparar('HU4-LIMITE', GfOrder::STATUS_RETRY_PENDING, true, 1, 600, $xmlFalso);
$emisor->willReturn($respuestaEmitida, ProviderBusinessException::fromResult(
    '<Resultado>82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO</Resultado>'
));
$resultado = $retry->reintentar($limitada->getUuid());
$trasLimite = $repository->findByUuid($limitada->getUuid());

$assert('un error 82 en el reintento responde 422', $resultado['status'] === 422);
$assert('un error 82 deja la orden ambigua y reintentable',
    $trasLimite->isAmbiguous() === true && $trasLimite->getStatus() === GfOrder::STATUS_RETRY_PENDING);

// --- CA4.9 · La política orienta al POS ----------------------------------------
$clean();
$reciente = $policy->evaluate(
    $preparar('HU4-POLITICA-RECIENTE', GfOrder::STATUS_RETRY_PENDING, true, 1, 5, $xmlFalso)
);

$assert('una orden que acaba de fallar aún no se puede reintentar',
    $reciente['permitido'] === false, json_encode($reciente));
$assert('el bloqueo de una orden reciente es la espera',
    $reciente['motivo'] === 'demasiado_pronto');
$assert('una orden reciente informa de los segundos que faltan',
    $reciente['esperar_segundos'] > 0);

$situacion = $policy->evaluate(
    $preparar('HU4-POLITICA', GfOrder::STATUS_RETRY_PENDING, true, 1, $policy->requiredWait(1) + 5, $xmlFalso)
);

$assert('pasada la espera, la política permite reintentar', $situacion['permitido'] === true, json_encode($situacion));
$assert('la política no pide esperar cuando ya se puede', $situacion['esperar_segundos'] === 0);
$assert('la política informa cuántos intentos quedan',
    $situacion['intentos_restantes'] === $maxIntentos - 1);
$assert('una orden con intentos de sobra no está en su último intento',
    $situacion['ultimo_intento'] === false);

$ultima = $policy->evaluate(
    $preparar('HU4-ULTIMA', GfOrder::STATUS_RETRY_PENDING, true, $maxIntentos - 1, 3600, $xmlFalso)
);

$assert('con un intento restante se avisa de que es el último', $ultima['ultimo_intento'] === true);
$assert('con un intento restante el reintento sigue permitido', $ultima['permitido'] === true);

// -----------------------------------------------------------------------------
echo "\n== CA5 · Anulación de facturas ==\n";
// -----------------------------------------------------------------------------

/** Construye un servicio de anulación sobre un emisor dado. */
$anularCon = static function (DocumentEmitter $emitter) use ($repository, $config): AnulacionService {
    return new AnulacionService($repository, $emitter, $config);
};

/**
 * Siembra una orden facturada, lista para anular.
 *
 * @param string      $code    Código de la orden.
 * @param array<string, mixed> $payload Contenido de `payload`.
 * @param string      $serie   Serie autorizada.
 * @param string      $preimpreso Preimpreso autorizado.
 *
 * @return GfOrder Orden sembrada.
 */
$facturada = static function (
    string $code,
    array $payload,
    string $serie = 'F0123',
    string $preimpreso = '4567'
) use ($pdo, $repository): GfOrder {
    $uuid = sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xFFFFFFFF),
        random_int(0, 0xFFFF),
        random_int(0, 0xFFF),
        random_int(0x8000, 0xBFFF),
        random_int(0, 0xFFFFFFFFFFFF)
    );

    $marca = gmdate('Y-m-d H:i:s');

    $pdo->prepare(
        'INSERT INTO gf_orders (uuid, order_code, dte_type, status, payload, serie, preimpreso,
                                autorizacion, attempts, created_at, updated_at, invoiced_at)
         VALUES (:uuid, :code, 1, :status, :payload, :serie, :preimpreso,
                 :autorizacion, 1, :creada, :actualizada, :autorizada)'
    )->execute([
        ':uuid'         => $uuid,
        ':code'         => $code,
        ':status'       => GfOrder::STATUS_INVOICED,
        ':payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ':serie'        => $serie,
        ':preimpreso'   => $preimpreso,
        ':autorizacion' => '9F3A1C7E5B2D8406',
        ':creada'       => $marca,
        ':actualizada'  => $marca,
        ':autorizada'   => $marca,
    ]);

    $order = $repository->findByUuid($uuid);

    if ($order === null) {
        throw new RuntimeException('No se pudo sembrar la orden facturada.');
    }

    return $order;
};

$clienteConNit = ['cliente' => ['nit' => 'C/F', 'nombre' => 'Cliente', 'direccion' => 'Zona 1']];
$motivoValido  = 'Error de digitacion en el monto';

// --- CA5.1 · Solo se anula lo facturado ---------------------------------------
$noAnulables = [
    'una orden pendiente' => GfOrder::STATUS_PENDING,
    'una orden en contingencia' => GfOrder::STATUS_RETRY_PENDING,
    'una orden rechazada' => GfOrder::STATUS_PROVIDER_ERROR,
];

foreach ($noAnulables as $nombre => $estado) {
    $clean();
    $emisor = new EmisorSimulado($respuestaEmitida);
    $casoOrden = $preparar('HU5-' . md5($nombre), $estado, false, 1, 600, $xmlFalso);
    $resultado = $anularCon($emisor)->anular($casoOrden->getUuid(), ['motivo' => $motivoValido]);

    $assert($nombre . ' no se puede anular -> 409', $resultado['status'] === 409, "status={$resultado['status']}");
    $assert($nombre . ' no llama al proveedor', $emisor->annulmentCalls === 0);
    $assert($nombre . ' informa del estado real',
        stripos($resultado['message'], $estado) !== false, $resultado['message']);
}

// --- CA5.2 · Anular dos veces --------------------------------------------------
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$anular = $anularCon($emisor);
$primera = $facturada('HU5-DOBLE', $clienteConNit);

$ok = $anular->anular($primera->getUuid(), ['motivo' => $motivoValido]);

$assert('anular una factura autorizada responde 200', $ok['status'] === 200, "status={$ok['status']}");
$assert('la anulación llama al proveedor una vez', $emisor->annulmentCalls === 1);

$segunda = $anular->anular($primera->getUuid(), ['motivo' => $motivoValido]);

$assert('anular una segunda vez -> 409', $segunda['status'] === 409, "status={$segunda['status']}");
$assert('la segunda anulación no llama al proveedor', $emisor->annulmentCalls === 1);
$assert('el 409 dice que ya está anulada',
    stripos($segunda['message'], 'solo puede anularse una factura autorizada') !== false);

// --- CA5.3 · Motivo obligatorio y acotado --------------------------------------
$motivosInvalidos = [
    'ausente'        => [],
    'vacío'          => ['motivo' => '   '],
    'de un carácter' => ['motivo' => 'x'],
    'no string'      => ['motivo' => 12345],
    'demasiado largo'=> ['motivo' => str_repeat('a', 501)],
];

foreach ($motivosInvalidos as $nombre => $cuerpo) {
    $clean();
    $emisor = new EmisorSimulado($respuestaEmitida);
    $casoOrden = $facturada('HU5-MOT-' . md5($nombre), $clienteConNit);
    $resultado = $anularCon($emisor)->anular($casoOrden->getUuid(), $cuerpo);

    $assert('un motivo ' . $nombre . ' -> 400', $resultado['status'] === 400, "status={$resultado['status']}");
    $assert('un motivo ' . $nombre . ' nombra el campo', isset($resultado['errors']['motivo']));
    $assert('un motivo ' . $nombre . ' no llama al proveedor', $emisor->annulmentCalls === 0);
}

$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$casoOrden = $facturada('HU5-MOT-OK', $clienteConNit);
$resultado = $anularCon($emisor)->anular($casoOrden->getUuid(), [
    'motivo' => str_repeat('a', GuatefacturasClient::MAX_ANNULMENT_REASON),
]);

$assert('un motivo en el límite propio se acepta', $resultado['status'] === 200, "status={$resultado['status']}");
$assert('el límite propio está por debajo del real del proveedor',
    GuatefacturasClient::MAX_ANNULMENT_REASON < 729, 'tope=' . GuatefacturasClient::MAX_ANNULMENT_REASON);

// --- CA5.4 · Los datos salen del registro, no de la petición ------------------
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$casoOrden = $facturada('HU5-DATOS', ['cliente' => ['nit' => '12345678-9']], 'FAAA', '1111');

// Aunque la petición intente imponer serie, preimpreso y NIT, mandan los registrados.
$resultado = $anularCon($emisor)->anular($casoOrden->getUuid(), [
    'motivo' => $motivoValido,
    'serie'  => 'OTRA',
    'nit'    => 'C/F',
]);

$assert('anular responde 200 ignorando los campos impuestos', $resultado['status'] === 200);
$assert('usa la serie registrada', ($emisor->lastAnulment['serie'] ?? '') === 'FAAA');
$assert('usa el preimpreso registrado', ($emisor->lastAnulment['preimpreso'] ?? '') === '1111');
$assert('usa el NIT registrado', ($emisor->lastAnulment['nit'] ?? '') === '12345678-9');

// Una orden sin NIT no puede anularse: el proveedor lo exige.
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$sinNit = $facturada('HU5-SIN-NIT', ['cliente' => ['nombre' => 'Sin NIT']]);
$resultado = $anularCon($emisor)->anular($sinNit->getUuid(), ['motivo' => $motivoValido]);

$assert('una orden sin NIT no se puede anular -> 409', $resultado['status'] === 409, "status={$resultado['status']}");
$assert('el 409 explica que falta el NIT', stripos($resultado['message'], 'NIT') !== false);
$assert('sin NIT no se llama al proveedor', $emisor->annulmentCalls === 0);

// --- CA5.5 · Fecha local en AAAAMMDD --------------------------------------------
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$casoOrden = $facturada('HU5-FECHA', $clienteConNit);
$anularCon($emisor)->anular($casoOrden->getUuid(), ['motivo' => $motivoValido]);

$fechaEnviada = (string) ($emisor->lastAnulment['fecha'] ?? '');
$esperada     = (new DateTimeImmutable('now', new DateTimeZone('America/Guatemala')))->format('Ymd');
$esperadaUtc  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd');

$assert('la fecha de anulación va en AAAAMMDD', preg_match('/^\d{8}$/', $fechaEnviada) === 1, "fecha={$fechaEnviada}");
$assert('la fecha de anulación es la local del emisor', $fechaEnviada === $esperada, "enviada={$fechaEnviada} esperada={$esperada}");
$assert('la fecha no se toma en UTC cuando difieren',
    $esperada === $esperadaUtc || $fechaEnviada !== $esperadaUtc);

// --- CA5.6 · Un rechazo sin código no pasa por éxito -----------------------------
$rechazos = [
    'NO EXISTE EL DOCUMENTO QUE SE DESEA ANULAR' => true,
    '<RESULTADO>NO EXISTE EL DOCUMENTO QUE SE DESEA ANULAR</RESULTADO>' => true,
    'ERROR en los datos enviados, campo FECHA_ANULACION' => true,
    'ORA-01400: no se puede realizar una inserción NULL' => true,
];

foreach ($rechazos as $respuesta => $debeSerRechazo) {
    $assert(sprintf(
        'el clasificador detecta el rechazo «%s»',
        mb_substr($respuesta, 0, 40)
    ), ProviderResponseClassifier::isAnnulmentRejection($respuesta) === $debeSerRechazo);
}

$assert('el clasificador no confunde una anulación correcta con un rechazo',
    ProviderResponseClassifier::isAnnulmentRejection('<RESULTADO>ANULACION REALIZADA</RESULTADO>') === false);

$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$casoOrden = $facturada('HU5-RECHAZO', $clienteConNit);
$emisor->willFailAnulment(ProviderBusinessException::fromResult(
    '<RESULTADO>NO EXISTE EL DOCUMENTO QUE SE DESEA ANULAR</RESULTADO>'
));
$resultado = $anularCon($emisor)->anular($casoOrden->getUuid(), ['motivo' => $motivoValido]);
$trasRechazo = $repository->findByUuid($casoOrden->getUuid());

$assert('un rechazo sin código responde 422', $resultado['status'] === 422, "status={$resultado['status']}");
$assert('un rechazo NO marca la orden como anulada',
    $trasRechazo->getStatus() === GfOrder::STATUS_INVOICED, 'estado=' . $trasRechazo->getStatus());
$assert('un rechazo conserva la constancia del documento',
    $trasRechazo->toArrayPublic()['anulacion'] === null);
$assert('un rechazo deja el mensaje del proveedor registrado',
    stripos((string) $trasRechazo->toArrayPublic()['error'], 'NO EXISTE EL DOCUMENTO') !== false);

// --- CA5.7 · Constancia de la anulación ----------------------------------------
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$casoOrden = $facturada('HU5-CONSTANCIA', $clienteConNit);
$anularCon($emisor)->anular($casoOrden->getUuid(), ['motivo' => $motivoValido]);
$anulada = $repository->findByUuid($casoOrden->getUuid());

$assert('la orden queda ANULADO', $anulada->getStatus() === GfOrder::STATUS_CANCELLED);
$assert('la anulación es un estado terminal', $anulada->isTerminal() === true);
$assert('la anulación ya no es reintentable', $anulada->isRetryable() === false);
$assert('la constancia guarda el motivo', $anulada->toArrayPublic()['anulacion']['motivo'] === $motivoValido);
$assert('la constancia guarda la fecha', ($anulada->toArrayPublic()['anulacion']['fecha'] ?? '') !== '');
$assert('la constancia guarda la respuesta del proveedor',
    stripos((string) $anulada->toArrayPublic()['anulacion']['respuesta'], 'ANULACION') !== false);

// Un fallo de red no debe marcar la factura como anulada: sigue vigente.
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$casoOrden = $facturada('HU5-CAIDA', $clienteConNit);
$emisor->willFailAnulment(new CommunicationException(
    CommunicationException::KIND_TIMEOUT,
    'Connection refused'
));
$resultado = $anularCon($emisor)->anular($casoOrden->getUuid(), ['motivo' => $motivoValido]);
$trasCaida = $repository->findByUuid($casoOrden->getUuid());

$assert('un proveedor caído al anular responde 503', $resultado['status'] === 503);
$assert('la factura sigue vigente tras un fallo de red',
    $trasCaida->getStatus() === GfOrder::STATUS_INVOICED, 'estado=' . $trasCaida->getStatus());
$assert('el 503 recuerda que la factura sigue vigente',
    stripos($resultado['message'], 'sigue vigente') !== false);

// --- CA5.8 · Terminalidad -------------------------------------------------------
$clean();
$emisor = new EmisorSimulado($respuestaEmitida);
$anulacion = $anularCon($emisor);
$casoOrden = $facturada('HU5-TERMINAL', $clienteConNit);
$anulacion->anular($casoOrden->getUuid(), ['motivo' => $motivoValido]);
$emision = $serviceWith($emisor);

$reexpedir = $emision->facturar($orden('HU5-TERMINAL'));

$assert('una orden anulada no se vuelve a facturar -> 409', $reexpedir['status'] === 409, "status={$reexpedir['status']}");
$assert('el 409 de una orden anulada lo dice', stripos($reexpedir['message'], 'anulada') !== false);
$assert('la orden anulada no vuelve al proveedor', $emisor->calls === 0);

$repository->truncate();

echo "\n----------------------------------------\n";
echo sprintf("Pasaron: %d | Fallaron: %d\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);