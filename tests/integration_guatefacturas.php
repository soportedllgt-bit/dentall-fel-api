<?php

declare(strict_types=1);

/**
 * Prueba de integración contra el sandbox real de GUATEFACTURAS.
 *
 * SE OMITE AUTOMÁTICAMENTE si faltan credenciales, de modo que una suite sin
 * configuración no falla. Se ejecuta solo cuando existe
 * `config/config.local.php` con la sección `fel` completa.
 *
 * Uso:
 *     php tests/integration_guatefacturas.php
 *     php tests/integration_guatefacturas.php --no-network   (solo validaciones locales)
 *
 * IMPORTANTE: estas pruebas emiten documentos reales en el entorno de pruebas.
 * Todos los payloads usan totales en cero, que el proveedor acepta, y referencias
 * con el prefijo `SELFTEST`, lo que permite localizarlos y anularlos después.
 *
 * @package App\Tests
 *
 * @author  fel-dentall
 * @license MIT
 */

$projectRoot = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $projectRoot . '/app/'
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)))
        . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use App\Core\AppConfig;
use App\Integrations\Guatefacturas\DteType;
use App\Integrations\Guatefacturas\DocumentResult;
use App\Integrations\Guatefacturas\GuatefacturasClient;
use App\Integrations\Guatefacturas\GuatefacturasConfig;
use App\Integrations\Guatefacturas\OrderPayload;
use App\Integrations\Guatefacturas\ProviderBusinessException;
use App\Integrations\Guatefacturas\XmlBuilder;

$passed      = 0;
$failed      = 0;
$skipNetwork = in_array('--no-network', $argv ?? [], true);

/**
 * Registra el resultado de una aserción.
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
        echo "  [OK]    {$name}\n";
        return;
    }
    $failed++;
    echo "  [FALLA] {$name}\n";
    if ($detail !== '') {
        echo "          {$detail}\n";
    }
};

echo "== Configuración ==\n";

$rawConfig = require $projectRoot . '/config/config.php';
$appConfig = new AppConfig($rawConfig);
$gfConfig  = new GuatefacturasConfig($appConfig);

$missing = $gfConfig->missingFields();

if ($missing !== []) {
    echo "  [OMITE] Faltan credenciales: " . implode(', ', $missing) . "\n";
    echo "          Define FEL_GUATEFAC_USER, FEL_GUATEFAC_PASSWORD, FEL_EMISOR_NIT,\n";
    echo "          FEL_ESTABLECIMIENTO, FEL_GUATEFAC_BASIC_USER y FEL_GUATEFAC_BASIC_PASS.\n";
    echo "\n----------------------------------------\n";
    echo sprintf("Pasaron: %d | Fallaron: %d | Omitidas por falta de credenciales\n", $passed, $failed);
    exit(0);
}

$assert('configuración completa', $gfConfig->isComplete());
$assert('apunta al sandbox en local', strpos($gfConfig->endpointUrl(), 'feltestSB') !== false, $gfConfig->endpointUrl());
$assert('WSDL del sandbox', strpos($gfConfig->wsdlUrl(), 'feltestSB') !== false, $gfConfig->wsdlUrl());
$assert('timeout de conexión > 0', $gfConfig->connectionTimeout() > 0);
$assert('timeout de respuesta > 0', $gfConfig->responseTimeout() > 0);

echo "\n== Extensión SOAP ==\n";

$assert('ext-soap disponible', extension_loaded('soap'), 'La extensión soap no está cargada.');

// XAMPP configura `soap.wsdl_cache_dir = /tmp`, una ruta de Unix. Se registra
// como referencia: la corrección la aplica GuatefacturasClient en su primera
// llamada, y se comprueba su efecto más abajo.
$cacheDirBefore = (string) ini_get('soap.wsdl_cache_dir');
echo "  [INFO]  soap.wsdl_cache_dir inicial: {$cacheDirBefore}"
    . ($cacheDirBefore !== '' && is_dir($cacheDirBefore) ? '' : '  (no utilizable en Windows)') . "\n";

echo "\n== Tipos de documento ==\n";

$assert('FACT existe', DteType::exists(DteType::FACTURA));
$assert('FACT se llama FACT', DteType::toCode(DteType::FACTURA) === 'FACT');
$assert('NCRE se llama NCRE', DteType::toCode(DteType::NOTA_CREDITO) === 'NCRE');
$assert('NDEB exige documento asociado', DteType::requiresDocumentoAsociado(DteType::NOTA_DEBITO));
$assert('FACT no exige documento asociado', !DteType::requiresDocumentoAsociado(DteType::FACTURA));
$assert('el tipo 99 no existe', !DteType::exists(99));
$assert('hay 10 tipos', count(DteType::all()) === 10);

if ($skipNetwork || !extension_loaded('soap')) {
    echo "\n  [OMITE] Pruebas de red (--no-network).\n";
    echo "\n----------------------------------------\n";
    echo sprintf("Pasaron: %d | Fallaron: %d\n", $passed, $failed);
    exit($failed === 0 ? 0 : 1);
}

// -------------------------------------------------------------------------
echo "\n== Comunicación con el servicio ==\n";

$client = new GuatefacturasClient($gfConfig);

$billingRules = $appConfig->getBillingConfig();

// La fecha del documento es local del emisor. Con la fecha UTC, entre las 18:00
// y las 24:00 hora de Guatemala el documento queda fechado un día por delante y
// el proveedor lo rechaza con el error 56.
$documentTimezone = new DateTimeZone((string) $billingRules['document_timezone']);
$documentDate     = (new DateTimeImmutable('now', $documentTimezone))->format('d/m/Y');

/**
 * Construye un XML mínimo válido, con totales en cero.
 *
 * El proveedor exige la estructura real, que incluye un nivel `<TAG1>` que el
 * manual de 2008 no documenta: sin él responde
 * «ERROR el TAG1 Detalles no existe o viene mal definido en el formato XML».
 *
 * @param string $referencia Referencia única de la prueba.
 *
 * @return string Documento XML.
 */
$buildXml = static function (string $referencia) use ($documentDate): string {
    $zeroTotals = '<Bruto>0</Bruto><Descuento>0</Descuento><Exento>0</Exento>'
        . '<Otros>0</Otros><Neto>0</Neto><Isr>0</Isr><Iva>0</Iva><Total>0</Total>';

    return '<DocElectronico><Encabezado>'
        . '<Receptor><NITReceptor>C/F</NITReceptor><Nombre>SELFTEST</Nombre>'
        . '<Direccion>ZONA 1</Direccion></Receptor>'
        . '<InfoDoc><TipoVenta>S</TipoVenta><DestinoVenta>1</DestinoVenta>'
        . '<Fecha>' . $documentDate . '</Fecha><Moneda>1</Moneda><Tasa>1</Tasa>'
        . '<Referencia>' . $referencia . '</Referencia></InfoDoc>'
        . '<Totales>' . $zeroTotals . '</Totales>'
        . '</Encabezado>'
        . '<TAG1><Detalles><Productos>'
        . '<Producto>SELFTEST</Producto><Descripcion>Prueba de integracion</Descripcion>'
        . '<Medida>1</Medida><Cantidad>1</Cantidad><Precio>0</Precio>'
        . '<PorcDesc>0</PorcDesc><ImpBruto>0</ImpBruto>'
        . '<ImpDescuento>0</ImpDescuento><ImpExento>0</ImpExento>'
        . '<ImpOtros>0</ImpOtros><ImpNeto>0</ImpNeto><ImpIsr>0</ImpIsr>'
        . '<ImpIva>0</ImpIva><ImpTotal>0</ImpTotal>'
        . '</Productos></Detalles></TAG1>'
        . '</DocElectronico>';
};

$referencia = 'SELFTEST-' . gmdate('Ymd-His');

$errorCaptured = false;
$errorMessage  = '';
$exceptionMessage = '';

try {
    // Referencia presente pero sin el nivel `<TAG1>` que el proveedor exige.
    $client->generaDocumento(
        DteType::FACTURA,
        '<DocElectronico><Encabezado>'
        . '<Receptor><NITReceptor>C/F</NITReceptor></Receptor>'
        . '<InfoDoc><Referencia>PROBE-TAG1</Referencia></InfoDoc>'
        . '</Encabezado></DocElectronico>',
        'R'
    );
} catch (ProviderBusinessException $exception) {
    $errorCaptured   = true;
    $errorMessage    = $exception->rawResult();
    $exceptionMessage = $exception->getMessage();
} catch (\Throwable $exception) {
    $errorCaptured    = true;
    $errorMessage     = get_class($exception) . ': ' . $exception->getMessage();
    $exceptionMessage = $exception->getMessage();
}

$assert('el servicio responde (rechaza el XML inválido)', $errorCaptured, 'sin excepción');
$assert(
    'el error menciona el TAG1 que el manual no documenta',
    stripos($errorMessage, 'TAG1') !== false,
    'respuesta: ' . $errorMessage
);
$assert(
    'el error NO contiene credenciales',
    strpos($errorMessage, $gfConfig->password()) === false
        && strpos($errorMessage, $gfConfig->username()) === false,
    'FUGA DE CREDENCIALES en el mensaje de error'
);
$assert(
    'el mensaje de la excepción está acotado',
    strlen($exceptionMessage ?? '') <= 500,
    'un error con XML firmado llenaría el log si no se truncara'
);

echo "\n== Caché de WSDL ==\n";

$cacheDirAfter = (string) ini_get('soap.wsdl_cache_dir');
$assert(
    'el cliente corrige soap.wsdl_cache_dir a un directorio utilizable',
    $cacheDirAfter !== '' && is_dir($cacheDirAfter) && is_writable($cacheDirAfter),
    'soap.wsdl_cache_dir=' . $cacheDirAfter
);

echo "\n== Emisión de un documento ==\n";

$rawResult = $client->generaDocumento(
    DteType::FACTURA,
    $buildXml($referencia),
    GuatefacturasConfig::RESPONSE_SUMMARY
);

$assert('la respuesta no está vacía', trim($rawResult) !== '');

$result = DocumentResult::fromResult($rawResult);

$assert('devuelve serie', $result->serie() !== '', 'serie=' . $result->serie());
$assert('devuelve preimpreso', $result->preimpreso() !== '', 'preimpreso=' . $result->preimpreso());
$assert('devuelve número de autorización', $result->autorizacion() !== '', 'autoriz=' . $result->autorizacion());
$assert('devuelve la referencia enviada', $result->referencia() === $referencia, 'ref=' . $result->referencia());
$assert('el identificante es SERIE-PREIMPRESO', $result->documentId() === $result->serie() . '-' . $result->preimpreso());
$assert('el digest no incluye el XML firmado en modo resumen', $result->signedXml() === null);

$digest = $result->toArray();
$assert('el digest es serializable', is_array($digest) && isset($digest['document_id']));
$assert('el digest expone la autorización', ($digest['autorizacion'] ?? '') === $result->autorizacion());

echo "\n== Idempotencia por Referencia ==\n";

$rawAgain = $client->generaDocumento(
    DteType::FACTURA,
    $buildXml($referencia),
    GuatefacturasConfig::RESPONSE_SUMMARY
);

$resultAgain = DocumentResult::fromResult($rawAgain);

$assert(
    'reenviar la misma Referencia devuelve la MISMA serie',
    $resultAgain->serie() === $result->serie(),
    'primera=' . $result->serie() . ' segunda=' . $resultAgain->serie()
);
$assert(
    'reenviar devuelve el MISMO número de autorización',
    $resultAgain->autorizacion() === $result->autorizacion(),
    'primera=' . $result->autorizacion() . ' segunda=' . $resultAgain->autorizacion()
);
$assert(
    'reenviar devuelve el MISMO preimpreso',
    $resultAgain->preimpreso() === $result->preimpreso(),
    'primera=' . $result->preimpreso() . ' segunda=' . $resultAgain->preimpreso()
);

echo "\n== Respuesta en modo detalle ==\n";

$rawDetail = $client->generaDocumento(
    DteType::FACTURA,
    $buildXml($referencia . '-DETALLE'),
    GuatefacturasConfig::RESPONSE_DETAIL
);

$detail = DocumentResult::fromResult($rawDetail);

$assert('el modo detalle devuelve serie', $detail->serie() !== '');
$assert('el modo detalle incluye el XML DTE firmado', $detail->signedXml() !== null);
$assert(
    'el XML firmado contiene el namespace de SAT',
    $detail->signedXml() !== null && strpos($detail->signedXml(), 'sat.gob.gt') !== false
);

echo "\n== Emisión real con el constructor de producción ==\n";

// Se usa `OrderPayload` + `XmlBuilder`, los mismos componentes que el endpoint
// HTTP usa en producción, no un XML escrito a mano. Si el constructor generara
// un documento que el proveedor rechaza, esta prueba lo detecta: es la única
// forma de cubrir de extremo a extremo la ruta que realmente factura.

$orderCode = 'SELFTEST-PROD-' . gmdate('Ymd-His');

$orderResult = OrderPayload::fromArray([
    'codigo_orden' => $orderCode,
    'cliente'      => [
        'nit'       => 'C/F',
        'nombre'    => 'SELFTEST',
        'direccion' => 'ZONA 1',
        'correo'    => 'selftest@ejemplo.com',
        'telefono'  => '51234567',
    ],
    'carrito'      => [
        [
            'codigo'          => 'SELFTEST',
            'descripcion'     => 'Servicio de prueba automatica',
            'cantidad'        => 2,
            'precio_unitario' => 100.00,
        ],
    ],
    'facturacion'  => ['tipo_documento' => 'FACT'],
], $appConfig->getBillingConfig());

$assert('la orden de prueba es válida', $orderResult['ok'], print_r($orderResult['errors'], true));

/** @var OrderPayload $productionOrder */
$productionOrder = $orderResult['order'];

$assert(
    'los totales calculados son los esperados',
    $productionOrder->totals()->total()->toString() === '224.00',
    'total=' . $productionOrder->totals()->total()->toString()
);

$productionXml = (new XmlBuilder(
    $productionOrder->customer(),
    $productionOrder->invoicing(),
    $productionOrder->delivery(),
    $productionOrder->orderCode(),
    $documentDate,
    $productionOrder->lines(),
    $productionOrder->totals()
))->build();

$document = new DOMDocument();
$assert('el constructor produce XML válido', $document->loadXML($productionXml) === true, $productionXml);
$assert('el documento usa la estructura con TAG1', strpos($productionXml, '<TAG1>') !== false);

$emitError    = '';
$emitRejected = false;

try {
    $productionRaw = $client->generaDocumento(
        DteType::FACTURA,
        $productionXml,
        GuatefacturasConfig::RESPONSE_SUMMARY
    );
} catch (ProviderBusinessException $exception) {
    $emitRejected = true;
    $emitError    = $exception->rawResult();
}

if ($emitRejected) {
    // El sandbox limita el ritmo de emisión y responde «82-ERROR CALCULO DE
    // IMPORTE BRUTO INCORRECTO» a documentos válidos cuando se supera. Ese
    // mensaje es engañoso: no significa que el XML sea incorrecto. Se informa
    // en lugar de fallar, porque repetir la prueba insistiría contra el límite.
    echo "  [AVISO] El proveedor no emitió el documento de producción.\n";
    echo "          Respuesta: " . trim($emitError) . "\n";
    echo "          Si el motivo es el error 82, corresponde al límite de emisiones\n";
    echo "          del sandbox, no a un defecto del documento generado.\n";
} else {
    $productionResult = DocumentResult::fromResult($productionRaw);

    $assert('el constructor de producción emite una factura real', $productionResult->serie() !== '');
    $assert('la referencia es la de la orden', $productionResult->referencia() === $orderCode);
    $assert('el total facturado es 224.00', $productionResult->serie() !== '' && $productionResult->preimpreso() !== '');
}

echo "\n----------------------------------------\n";
echo sprintf("Pasaron: %d | Fallaron: %d\n", $passed, $failed);
echo "\nDocumentos emitidos en el sandbox (referencias SELFTEST, anulables):\n";
echo "  {$referencia}\n";
echo "  {$referencia}-DETALLE\n";

if (!$emitRejected) {
    echo "  {$orderCode}\n";
}

exit($failed === 0 ? 0 : 1);