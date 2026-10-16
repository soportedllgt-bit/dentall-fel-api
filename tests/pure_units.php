<?php

declare(strict_types=1);

/**
 * Prueba aislada de los componentes puros (Uuid, Sanitizer, Validator, Request).
 *
 * No necesita base de datos ni servidor web: se ejecuta con
 * `php tests/pure_units.php` desde la raíz del proyecto.
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
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Sanitizer;
use App\Core\Uuid;
use App\Core\Validator;
use App\Integrations\Guatefacturas\CommunicationException;
use App\Integrations\Guatefacturas\ConfigurationException;
use App\Integrations\Guatefacturas\Decimal;
use App\Integrations\Guatefacturas\DteType;
use App\Integrations\Guatefacturas\DocumentResult;
use App\Integrations\Guatefacturas\GuatefacturasConfig;
use App\Integrations\Guatefacturas\GuatefacturasException;
use App\Integrations\Guatefacturas\ImportesProveedor;
use App\Integrations\Guatefacturas\OrderLine;
use App\Integrations\Guatefacturas\OrderPayload;
use App\Integrations\Guatefacturas\ProviderBusinessException;
use App\Integrations\Guatefacturas\ProviderResponseClassifier;
use App\Integrations\Guatefacturas\Totals;
use App\Integrations\Guatefacturas\XmlBuilder;

$passed = 0;
$failed = 0;

/**
 * Comprueba una aserción y registra el resultado.
 *
 * @param string $name     Descripción de la aserción.
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

echo "== Uuid ==\n";

$generated = Uuid::v4();
$assert('v4() genera 36 caracteres', strlen($generated) === 36);
$assert('v4() genera un formato válido', Uuid::isValidV4($generated));
$assert('v4() fija la versión en 4', $generated[14] === '4');
$assert('v4() fija la variante RFC 4122', in_array($generated[19], ['8', '9', 'a', 'b'], true));
$assert('v4() genera valores distintos', Uuid::v4() !== $generated);
$assert('isValidV4() acepta mayúsculas', Uuid::isValidV4(strtoupper($generated)));
$assert('isValidV4() rechaza longitud incorrecta', !Uuid::isValidV4(substr($generated, 0, 35)));
$assert('isValidV4() rechaza el UUID nil', !Uuid::isValidV4('00000000-0000-0000-0000-000000000000'));
$assert('isValidV4() rechaza versión 1', !Uuid::isValidV4('6ba7b810-9dad-11d1-80b4-00c04fd430c8'));
$assert('isValidV4() rechaza variante inválida', !Uuid::isValidV4('6ba7b810-9dad-41d1-00b4-00c04fd430c8'));
$assert('isValidV4() rechaza basura de 36 chars', !Uuid::isValidV4(str_repeat('z', 36)));
$assert('isValidV4() rechaza texto inyectado', !Uuid::isValidV4("' OR 1=1 -- "));
$assert('normalize() pasa a minúsculas', Uuid::normalize(strtoupper($generated)) === strtolower($generated));

echo "\n== Sanitizer ==\n";

$assert('singleLine() recorta espacios', Sanitizer::singleLine('   hola   ') === 'hola');
$assert('singleLine() colapsa espacios', Sanitizer::singleLine('a    b') === 'a b');
$assert('singleLine() colapsa saltos de línea', Sanitizer::singleLine("a\n\nb") === 'a b');
$assert('multiLine() conserva saltos de línea', Sanitizer::multiLine("a\nb") === "a\nb");
$assert('multiLine() normaliza CRLF', Sanitizer::multiLine("a\r\nb") === "a\nb");
$assert('multiLine() colapsa 3+ saltos', Sanitizer::multiLine("a\n\n\n\nb") === "a\n\nb");
$assert('multiLine() colapsa espacios horizontales', Sanitizer::multiLine("a  \t  b") === 'a b');
$assert('description() convierte vacío en null', Sanitizer::description('   ') === null);
$assert('description() conserva texto', Sanitizer::description(' hola ') === 'hola');
$assert('description(null) devuelve null', Sanitizer::description(null) === null);

$utf8Rejected = false;
try {
    Sanitizer::singleLine("abc\xC3\x28def");
} catch (InvalidArgumentException $exception) {
    $utf8Rejected = true;
}
$assert('singleLine() rechaza UTF-8 inválido', $utf8Rejected);

$arrayRejected = false;
try {
    Sanitizer::title(['no', 'es', 'texto']);
} catch (InvalidArgumentException $exception) {
    $arrayRejected = true;
}
$assert('title() rechaza arrays', $arrayRejected);

echo "\n== Validator ==\n";

$rules = [
    'title' => ['required' => true, 'type' => 'string', 'max' => 10],
    'note'  => ['required' => false, 'type' => 'string', 'max' => 20, 'nullable' => true],
];

$assert('acepta datos válidos', Validator::validate(['title' => 'ok'], $rules) === []);
$assert('detecta campo obligatorio ausente', isset(Validator::validate([], $rules)['title']));
$assert('acumula varios errores', count(Validator::validate([], $rules)) === 1);
$assert('rechaza tipo incorrecto', isset(Validator::validate(['title' => 123], $rules)['title']));
$assert('rechaza longitud excesiva', isset(Validator::validate(['title' => '01234567890'], $rules)['title']));
$assert('acepta longitud exacta', Validator::validate(['title' => '0123456789'], $rules) === []);
$assert('acepta null si es nullable', Validator::validate(['title' => 'ok', 'note' => null], $rules) === []);
$assert('cuenta multibyte con mb_strlen', isset(Validator::validate(['title' => 'áéíóúáéíóúá'], $rules)['title']));
$assert('modo parcial ignora ausentes', Validator::validate([], $rules, true) === []);
$assert('modo parcial sigue validando presentes', isset(Validator::validate(['title' => []], $rules, true)['title']));

$uuidErrors = Validator::uuid($generated, 'uuid');
$assert('uuid() acepta UUID v4', $uuidErrors === []);
$assert('uuid() rechaza texto', Validator::uuid('abc', 'uuid') !== []);
$assert('uuid() rechaza array', Validator::uuid([], 'uuid') !== []);

$assert('rejectUnknownFields() detecta campos extra', Validator::rejectUnknownFields(['id' => 1], $rules) === ['id']);
$assert('rejectUnknownFields() acepta campos válidos', Validator::rejectUnknownFields(['title' => 'x'], $rules) === []);

echo "\n== Request (normalización de rutas) ==\n";

// Layout 1: DocumentRoot = raíz de XAMPP, proyecto en www/fel-dentall.
$_SERVER['DOCUMENT_ROOT'] = 'C:\\xampp_lite\\www';
$_SERVER['SCRIPT_NAME']    = '/fel-dentall/public/index.php';

$assert('elimina el prefijo del proyecto', Request::normalizePath('/fel-dentall/api/items') === '/api/items');
$assert('elimina el segmento /public', Request::normalizePath('/fel-dentall/public/api/items') === '/api/items');
$assert('elimina el query string', Request::normalizePath('/fel-dentall/api/items?limit=5') === '/api/items');
$assert('conserva la ruta con uuid', Request::normalizePath('/fel-dentall/api/items/3f2504e0-4f89-41d3-9a0c-0305e82c3301') === '/api/items/3f2504e0-4f89-41d3-9a0c-0305e82c3301');

// Layout 2: DocumentRoot = public/, script en la raíz del sitio.
$_SERVER['DOCUMENT_ROOT'] = 'C:\\xampp_lite\\www\\fel-dentall\\public';
$_SERVER['SCRIPT_NAME']    = '/index.php';

$assert('vhost en public/: ruta intacta', Request::normalizePath('/api/items') === '/api/items');
$assert('vhost en public/: uuid intacto', Request::normalizePath('/api/items/3f2504e0-4f89-41d3-9a0c-0305e82c3301') === '/api/items/3f2504e0-4f89-41d3-9a0c-0305e82c3301');

// Layout 3: DocumentRoot = raíz del proyecto, script en /public/.
$_SERVER['DOCUMENT_ROOT'] = 'C:\\xampp_lite\\www\\fel-dentall';
$_SERVER['SCRIPT_NAME']    = '/public/index.php';

$assert('DocumentRoot en proyecto: ruta intacta', Request::normalizePath('/api/items') === '/api/items');
$assert('DocumentRoot en proyecto: quita /public', Request::normalizePath('/public/api/items') === '/api/items');

$assert('decodifica porcentajes', Request::normalizePath('/api/items%20x') === '/api/items x');
$assert('colapsa barras duplicadas', Request::normalizePath('/api//items') === '/api/items');
$assert('recorta barra final', Request::normalizePath('/api/items/') === '/api/items');
$assert('devuelve raíz vacía', Request::normalizePath('/') === '/');
$assert('devuelve raíz si la URL está vacía', Request::normalizePath('') === '/');

$listRejected = false;
try {
    $request = new Request('POST', '/api/items', [], ['CONTENT_TYPE' => 'application/json'], '[1,2,3]');
    $request->json();
} catch (JsonException $exception) {
    $listRejected = true;
}
$assert('json() rechaza una lista JSON', $listRejected);

$emptyRejected = false;
try {
    $request = new Request('POST', '/api/items', [], ['CONTENT_TYPE' => 'application/json'], '   ');
    $request->json();
} catch (JsonException $exception) {
    $emptyRejected = true;
}
$assert('json() rechaza un cuerpo vacío', $emptyRejected);

$malformedRejected = false;
$malformedMessage  = '';
try {
    $request = new Request('POST', '/api/items', [], ['CONTENT_TYPE' => 'application/json'], '{roto');
    $request->json();
} catch (JsonException $exception) {
    $malformedRejected = true;
    $malformedMessage  = $exception->getMessage();
}
$assert('json() rechaza JSON malformado', $malformedRejected);
$assert(
    'json() informa del error de sintaxis en español',
    strpos($malformedMessage, 'error de sintaxis') !== false,
    $malformedMessage
);
$assert(
    'json() NO duplica el prefijo ni dice «sin error»',
    strpos($malformedMessage, 'sin error') === false
        && substr_count($malformedMessage, 'no es un JSON válido') === 1,
    $malformedMessage
);

// `JSON_THROW_ON_ERROR` reinicia json_last_error() a NONE: si el mensaje se
// construyera a partir de json_last_error() en lugar del código de la excepción,
// un JSON roto informaría «sin error».
$decodedCode = null;
try {
    json_decode('{roto', true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    $decodedCode = $exception->getCode();
}
$assert(
    'json_decode lanza con el código JSON_ERROR_SYNTAX',
    $decodedCode === JSON_ERROR_SYNTAX,
    'code=' . var_export($decodedCode, true)
);
$assert(
    'json_last_error() queda en NONE tras la excepción (por eso no debe usarse)',
    json_last_error() === JSON_ERROR_NONE,
    'json_last_error=' . json_last_error()
);

$scalarRejected = false;
$scalarMessage  = '';
try {
    $request = new Request('POST', '/api/items', [], ['CONTENT_TYPE' => 'application/json'], '"un escalar"');
    $request->json();
} catch (JsonException $exception) {
    $scalarRejected = true;
    $scalarMessage  = $exception->getMessage();
}
$assert('json() rechaza un escalar JSON', $scalarRejected);
$assert(
    'json() distingue escalar de lista',
    strpos($scalarMessage, 'valor simple') !== false,
    $scalarMessage
);

// -----------------------------------------------------------------------------
echo "\n== Router ==\n";

$_SERVER['DOCUMENT_ROOT'] = 'C:\\xampp_lite\\www\\fel-dentall\\public';
$_SERVER['SCRIPT_NAME']    = '/index.php';

$sampleUuid = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

// PATCH: el verbo estaba declarado en ALLOWED_METHODS sin método de registro.
Response::reset();
$captured  = [];
$router    = new Router();
$router->patch('/api/demo/{uuid}', static function (Request $req) use (&$captured): void {
    $captured = $req->routeParams();
});
$router->dispatch(new Request('PATCH', "/api/demo/{$sampleUuid}", [], [], ''));
$assert('Router registra y despacha PATCH', isset($captured['uuid']), json_encode($captured));
$assert('PATCH extrae el uuid de la ruta', ($captured['uuid'] ?? '') === $sampleUuid, json_encode($captured));

// Verbo no registrado en una ruta existente -> 405, no 404.
Response::reset();
$router405 = new Router();
foreach (['get', 'put', 'delete'] as $verb) {
    $router405->{$verb}('/api/demo/{uuid}', static function (Request $req): void {
    });
}
ob_start();
$router405->dispatch(new Request('POST', "/api/demo/{$sampleUuid}", [], [], ''));
$body405 = (string) ob_get_clean();
$assert('verbo no soportado en ruta existente -> 405', strpos($body405, 'no está permitido') !== false, $body405);

// Ruta inexistente -> 404.
Response::reset();
$router404 = new Router();
ob_start();
$router404->dispatch(new Request('GET', '/api/no-existe', [], [], ''));
$body404 = (string) ob_get_clean();
$assert('ruta inexistente -> 404', strpos($body404, 'No existe el recurso') !== false, $body404);

// Verbo HTTP no soportado por el router (p. ej. TRACE) -> 405 antes de buscar rutas.
Response::reset();
ob_start();
$router404->dispatch(new Request('TRACE', "/api/demo/{$sampleUuid}", [], [], ''));
$bodyVerb = (string) ob_get_clean();
$assert('verbo no permitido por el router -> 405', strpos($bodyVerb, 'no está permitido') !== false, $bodyVerb);

// Fallback `any`: UUID mal formado -> 400.
Response::reset();
$routerAny = new Router();
$routerAny->any('/api/demo/{param}', static function (Request $req): void {
    $errors = Validator::uuid((string) $req->param('param', ''), 'uuid');
    Response::error($errors[0] ?? 'uuid inválido', 400, ['uuid' => $errors]);
});
ob_start();
$routerAny->dispatch(new Request('GET', '/api/demo/no-es-un-uuid', [], [], ''));
$body400 = (string) ob_get_clean();
$assert('uuid malformado en fallback -> 400', strpos($body400, 'UUID v4') !== false, $body400);

// El patrón {uuid} exige el formato canónico: 36 chars no hex no deben pasar.
$assert('el patrón {uuid} rechaza basura de 36 chars', !Uuid::isValidV4(str_repeat('z', 36)));

// -----------------------------------------------------------------------------
echo "\n== Integración GUATEFACTURAS (sin red) ==\n";

$assert('DteType: FACT es 1', DteType::FACTURA === 1);
$assert('DteType: NCRE es 10', DteType::NOTA_CREDITO === 10);
$assert('DteType: código textual de FACT', DteType::toCode(DteType::FACTURA) === 'FACT');
$assert('DteType: código textual de FCAP', DteType::toCode(DteType::FACTURA_CAMBIARIA_PEQUENO_CONTRIBUYENTE) === 'FCAP');
$assert('DteType: 10 tipos definidos', count(DteType::all()) === 10);
$assert('DteType: solo notas requieren documento asociado', DteType::requiresDocumentoAsociado(DteType::NOTA_CREDITO) && !DteType::requiresDocumentoAsociado(DteType::FACTURA));

$threwOnUnknown = false;
try {
    DteType::toCode(99);
} catch (InvalidArgumentException $exception) {
    $threwOnUnknown = true;
}
$assert('DteType: tipo desconocido lanza excepción', $threwOnUnknown);

// --- Enmascarado de secretos -------------------------------------------------
$secretA = 'claveSecretaA1';
$secretB = 'claveSecretaB2';
$scrubber = new GuatefacturasException('inicial');
$scrubber->setSecrets([$secretA, $secretB, '1', 'ab']);

$dirty = sprintf(
    'fallo con %s y %s',
    $secretA,
    $secretB
);
$clean = $scrubber->scrub($dirty);

$assert('las credenciales se eliminan del mensaje', strpos($clean, $secretA) === false && strpos($clean, $secretB) === false);
$assert('se indicate un marcador de sustitución', strpos($clean, '[OCULTO]') !== false);
$assert('los valores triviales no se enmascaran', strpos($scrubber->scrub('establecimiento 1'), '1') !== false);
$assert('el contexto no sensible se conserva', strpos($clean, 'fallo con') !== false);

$providerException = new ProviderBusinessException(str_repeat('A', 5000));
$assert('el mensaje del error está acotado', strlen($providerException->getMessage()) < 600, 'longitud=' . strlen($providerException->getMessage()));
$assert('el texto íntegro se conserva aparte', strlen($providerException->rawResult()) === 5000);
$assert('se extrae el código 82 del proveedor', ProviderBusinessException::extractCode('82-ERROR CALCULO MAL') === 82);
$assert('un mensaje sin código devuelve null', ProviderBusinessException::extractCode('ERROR sin código') === null);
$assert('ERRORES también es un error', ProviderBusinessException::extractCode('ERRORES:ORA-01403') === null);

// --- Digest de respuestas reales ---------------------------------------------
$summaryXml = '<Resultado>'
    . '<Serie>BADD0CD3</Serie><Preimpreso>4067575726</Preimpreso>'
    . '<Nombre>DOCUMENTO DE PRUEBAS</Nombre><Direccion>ZONA 1</Direccion>'
    . '<Telefono></Telefono>'
    . '<NumeroAutorizacion>BADD0CD3-F272-47AE-B3F3-66627DE68250</NumeroAutorizacion>'
    . '<Referencia>POS-0001</Referencia>'
    . '</Resultado>';

$digest = DocumentResult::fromResult($summaryXml);
$assert('digest: serie extraída', $digest->serie() === 'BADD0CD3');
$assert('digest: preimpreso extraído', $digest->preimpreso() === '4067575726');
$assert('digest: autorización extraída', $digest->autorizacion() === 'BADD0CD3-F272-47AE-B3F3-66627DE68250');
$assert('digest: referencia extraída', $digest->referencia() === 'POS-0001');
$assert('digest: identificador combinado', $digest->documentId() === 'BADD0CD3-4067575726');
$assert('digest: sin XML firmado en modo resumen', $digest->signedXml() === null);

$notADocumentRejected = false;
try {
    DocumentResult::fromResult('<Resultado><ERROR>algo</ERROR></Resultado>');
} catch (ProviderBusinessException $exception) {
    $notADocumentRejected = true;
}
$assert('digest: respuesta sin serie ni autorización se rechaza', $notADocumentRejected);

$notXmlRejected = false;
try {
    DocumentResult::fromResult('esto no es xml');
} catch (ProviderBusinessException $exception) {
    $notXmlRejected = true;
}
$assert('digest: texto no XML se rechaza', $notXmlRejected);

// --- Configuración -----------------------------------------------------------
$configWithoutCredentials = new GuatefacturasConfig(
    new AppConfig(['fel' => ['username' => '', 'password' => '', 'nit' => '', 'establishment' => '',
        'basic_username' => '', 'basic_password' => '', 'wsdl_url' => '', 'endpoint_url' => '']])
);

$assert('config: incompleta sin credenciales', !$configWithoutCredentials->isComplete());
$assert('config: enumera las 6 variables que faltan', count($configWithoutCredentials->missingFields()) === 6);

$missingExceptionThrown = false;
try {
    $configWithoutCredentials->assertComplete();
} catch (ConfigurationException $exception) {
    $missingExceptionThrown = true;
}
$assert('config: assertComplete lanza si falta algo', $missingExceptionThrown);

$sandboxConfig = new GuatefacturasConfig(new AppConfig(['fel' => []]));
$assert('config: por defecto usa el sandbox', strpos($sandboxConfig->endpointUrl(), 'feltestSB') !== false, $sandboxConfig->endpointUrl());
$assert('config: los timeouts nunca son cero', $sandboxConfig->connectionTimeout() > 0 && $sandboxConfig->responseTimeout() > 0);

// --- Clasificación de fallos de comunicación ---------------------------------
$ambiguous = new CommunicationException(CommunicationException::KIND_TIMEOUT, 'sin respuesta');
$lost       = new CommunicationException(CommunicationException::KIND_CONNECTION, 'conexión rechazada');

$assert('comunicación: un timeout es resultado incierto', $ambiguous->isAmbiguous());
$assert('comunicación: perder la conexión no es incierto', !$lost->isAmbiguous());

// -----------------------------------------------------------------------------
echo "\n== Aritmética decimal exacta ==\n";

// El caso que justifica la clase: en coma flotante 0.29 * 100 no es 29.
$assert('decimal: 0.29 son 29 centavos, no 28', Decimal::parse('0.29', 2, 'p')->cents() === 29);
$assert('decimal: el float 0.29 también', Decimal::parse(0.29, 2, 'p')->cents() === 29);
$assert('decimal: 0.1 + 0.2 = 0.30 exacto', Decimal::fromCents(10)->plus(Decimal::fromCents(20))->cents() === 30);
$assert(
    'decimal: veinte líneas de 0.10 suman 2.00, no 1.9999',
    Decimal::sum(array_fill(0, 20, Decimal::fromCents(10)))->cents() === 200
);
$assert('decimal: el formato conserva dos decimales', Decimal::fromCents(15000)->toString() === '150.00');
$assert('decimal: entero sencillo', Decimal::parse(150, 2, 'p')->toString() === '150.00');
$assert('decimal: admite punto final ("10.")', Decimal::parse('10.', 2, 'p')->toString() === '10.00');

$assert('decimal: 150.00 al 12% son 18.00', Decimal::fromCents(15000)->percentOf(Decimal::parse('12', 2, 'p'))->cents() === 1800);
$assert('decimal: 100.01 al 7.25% son 7.25', Decimal::fromCents(10001)->percentOf(Decimal::parse('7.25', 2, 'p'))->cents() === 725);
$assert('decimal: 1.00 entre 4.00 son 0.25', Decimal::fromCents(100)->divide(Decimal::fromCents(400))->cents() === 25);
$assert('decimal: el redondeo es simétrico con signo', Decimal::fromCents(-5)->divide(Decimal::fromCents(2))->cents() === -250);
$assert('decimal: cantidad(3) x precio(2) a escala 2', Decimal::parse('1.5', 3, 'q')->multiply(Decimal::fromCents(3333))->cents() === 5000);
$assert('decimal: 150.00 de 200.00 es 75%', Decimal::fromCents(15000)->percentageOf(Decimal::fromCents(20000))->toString() === '75.0000');

$rejectedExcessPrecision = false;
try {
    Decimal::parse('1.234', 2, 'precio');
} catch (InvalidArgumentException $exception) {
    $rejectedExcessPrecision = true;
}
$assert('decimal: rechaza más decimales que la escala en vez de redondear', $rejectedExcessPrecision);

foreach (['', 'abc', '1,5', '1.5e3', true, null] as $invalid) {
    $rejected = false;

    try {
        Decimal::parse($invalid, 2, 'precio');
    } catch (InvalidArgumentException $exception) {
        $rejected = true;
    }

    $assert(sprintf('decimal: rechaza %s', var_export($invalid, true)), $rejected);
}

// -----------------------------------------------------------------------------
echo "\n== Importes de línea ==\n";

$lineaSimple = new OrderLine(
    'SRV-1',
    'Consulta',
    Decimal::parse('2', 3, 'cantidad'),
    Decimal::fromCents(10000),
    Decimal::zero(4),
    Decimal::zero(4),
    Decimal::parse('12', 2, 'iva'),
    false
);

$assert('línea: bruto = 2 x 100.00', $lineaSimple->gross()->cents() === 20000);
$assert('línea: neto = bruto sin descuentos', $lineaSimple->net()->cents() === 20000);
$assert('línea: IVA = 12% del neto', $lineaSimple->tax()->cents() === 2400);
$assert('línea: total = neto + IVA', $lineaSimple->total()->cents() === 22400);

$lineaConDescuento = new OrderLine(
    'SRV-2',
    'Limpieza',
    Decimal::parse('1', 3, 'cantidad'),
    Decimal::fromCents(5050),
    Decimal::parse('10', 4, 'descuento'),
    Decimal::zero(4),
    Decimal::parse('12', 2, 'iva'),
    false
);

$assert('línea: descuento del 10% sobre 50.50', $lineaConDescuento->discountAmount()->cents() === 505);
$assert('línea: neto tras el descuento', $lineaConDescuento->net()->cents() === 4545);
$assert('línea: IVA sobre el neto descontado', $lineaConDescuento->tax()->cents() === 545);

$lineaExenta = new OrderLine(
    'SRV-3',
    'Servicio exento',
    Decimal::parse('1', 3, 'cantidad'),
    Decimal::fromCents(3000),
    Decimal::zero(4),
    Decimal::zero(4),
    Decimal::parse('12', 2, 'iva'),
    true
);

$assert('línea exenta: el importe exento es el neto', $lineaExenta->exemptAmount()->cents() === 3000);
$assert('línea exenta: no genera IVA', $lineaExenta->tax()->isZero());
$assert('línea exenta: el total es el neto', $lineaExenta->total()->cents() === 3000);

$lineaConIsr = new OrderLine(
    'SRV-4',
    'Servicio retenido',
    Decimal::parse('1', 3, 'cantidad'),
    Decimal::fromCents(100000),
    Decimal::zero(4),
    Decimal::parse('10', 4, 'isr'),
    Decimal::parse('12', 2, 'iva'),
    false
);

$assert('línea: retención del 10% sobre 1000.00', $lineaConIsr->otherAmount()->cents() === 10000);
$assert('línea: el neto descuenta la retención', $lineaConIsr->net()->cents() === 90000);
$assert('línea: el IVA se calcula sobre el neto retenido', $lineaConIsr->tax()->cents() === 10800);

$negativeRejected = false;

try {
    new OrderLine(
        'X',
        'Precio negativo',
        Decimal::parse('1', 3, 'cantidad'),
        Decimal::parse('-5', 2, 'precio', true),
        Decimal::zero(4),
        Decimal::zero(4),
        Decimal::parse('12', 2, 'iva'),
        false
    );
} catch (InvalidArgumentException $exception) {
    $negativeRejected = true;
}
$assert('línea: un precio negativo se rechaza', $negativeRejected);

// -----------------------------------------------------------------------------
echo "\n== Totales del documento ==\n";

$totales = new Totals([$lineaSimple, $lineaConDescuento, $lineaExenta, $lineaConIsr]);

$assert('totales: bruto = 200.00+50.50+30.00+1000.00', $totales->gross()->cents() === 128050);
$assert('totales: descuento acumulado', $totales->discount()->cents() === 505);
$assert('totales: otros acumulados', $totales->others()->cents() === 10000);
$assert('totales: exento acumulado', $totales->exempt()->cents() === 3000);
$assert('totales: neto = bruto - descuento - otros', $totales->net()->cents() === 117545);
$assert('totales: IVA acumulado', $totales->tax()->cents() === 13745);
$assert('totales: total = neto + IVA', $totales->total()->cents() === 131290);
$assert('totales: cuenta las líneas', $totales->lineCount() === 4);
$assert('totales: Isr se informa en cero', $totales->toArray()['isr'] === '0.00');

// -----------------------------------------------------------------------------
echo "\n== Constructor de XML ==\n";

$xml = (new XmlBuilder(
    [
        'nit'       => 'C/F',
        'nombre'    => 'Clínica & Cia <S.R.L.>',
        'direccion' => 'Zona 1',
        'correo'    => 'a@b.com',
        'telefono'  => '51234567',
    ],
    [
        'tipo_venta'    => 'S',
        'destino_venta' => '1',
        'moneda'        => '1',
        'tasa'          => '1.00',
        'observaciones' => '',
    ],
    ['DireccionEntrega' => 'Zona 5', 'Observaciones' => ''],
    'POS-0001',
    '14/10/2026',
    [$lineaSimple, $lineaExenta],
    new Totals([$lineaSimple, $lineaExenta])
))->build();

$parsed = new DOMDocument();
$assert('XML: es un documento bien formado', $parsed->loadXML($xml) === true, $xml);
$assert('XML: raíz DocElectronico', $parsed->documentElement->nodeName === 'DocElectronico');
$assert('XML: escapa el ampersand del nombre', strpos($xml, 'Clínica &amp; Cia &lt;S.R.L.&gt;') !== false);
$assert('XML: conserva el acento en UTF-8', strpos($parsed->documentElement->textContent, 'Clínica') !== false);

// Las dos reglas estructurales que el manual no documenta.
$assert('XML: el encabezado va dentro de TAG1 (el manual lo omite)', strpos($xml, '<TAG1>') !== false && strpos($xml, '<Detalles>') !== false);
$assert(
    'XML: cada línea es su propio <Productos>',
    substr_count($xml, '<Productos>') === 2 && substr_count($xml, '</Productos>') === 2,
    'Productos=' . substr_count($xml, '<Productos>')
);
$assert(
    'XML: un solo <Productos> con dos líneas NO es la estructura válida',
    strpos($xml, '</Productos><Producto>') === false
);

$xpath = new DOMXPath($parsed);
$assert('XML: la cantidad conserva tres decimales', $xpath->query('//Cantidad')->item(0)->textContent === '2.000');
$assert(
    'XML: el precio va con IVA incluido, como exige la guía',
    $xpath->query('//Precio')->item(0)->textContent === '112.00',
    'Precio=' . $xpath->query('//Precio')->item(0)->textContent
);
$assert('XML: la referencia viaja al documento', $xpath->query('//Referencia')->item(0)->textContent === 'POS-0001');
$assert(
    'XML: los totales viajan con dos decimales',
    $xpath->query('//Bruto')->item(0)->textContent === '254.00',
    'Bruto=' . $xpath->query('//Bruto')->item(0)->textContent
);
$assert('XML: los datos de entrega van en DatosAdicionales', strpos($xml, '<DireccionEntrega>Zona 5</DireccionEntrega>') !== false);

$xmlSinEntrega = (new XmlBuilder(
    ['nit' => 'C/F', 'nombre' => 'X', 'direccion' => 'Y', 'correo' => '', 'telefono' => ''],
    ['tipo_venta' => 'S', 'destino_venta' => '1', 'moneda' => '1', 'tasa' => '1.00', 'observaciones' => ''],
    ['DireccionEntrega' => '', 'Observaciones' => '   '],
    'POS-0002',
    '14/10/2026',
    [$lineaSimple],
    new Totals([$lineaSimple])
))->build();

$assert('XML: omite DatosAdicionales si no hay nada que enviar', strpos($xmlSinEntrega, 'DatosAdicionales') === false);

$emptyCartRejected = false;

try {
    new XmlBuilder(
        ['nit' => 'C/F', 'nombre' => 'X', 'direccion' => 'Y', 'correo' => '', 'telefono' => ''],
        ['tipo_venta' => 'S', 'destino_venta' => '1', 'moneda' => '1', 'tasa' => '1.00', 'observaciones' => ''],
        [],
        'POS-0003',
        '14/10/2026',
        [],
        new Totals([])
    );
} catch (InvalidArgumentException $exception) {
    $emptyCartRejected = true;
}
$assert('XML: un documento sin líneas se rechaza antes de gastar una llamada', $emptyCartRejected);

// -----------------------------------------------------------------------------
echo "\n== Validación de la orden del POS ==\n";

$billingRules = (new AppConfig(require dirname(__DIR__) . '/config/config.php'))->getBillingConfig();
$assert('reglas: IVA por defecto de 12', $billingRules['default_tax_percent'] === '12');
$assert('reglas: el documento se fecha en Guatemala', $billingRules['document_timezone'] === 'America/Guatemala');
$assert(
    'reglas: una zona horaria inválida se descarta',
    (new AppConfig(['billing' => ['document_timezone' => 'Marte/Olympus']]))->getBillingConfig()['document_timezone'] === 'America/Guatemala'
);

// -----------------------------------------------------------------------------
echo "\n== Entornos de ejecución ==\n";

// Un servidor de desarrollo con dominio propio es público, así que no puede
// comportarse como `local` (que filtra el detalle técnico) ni como `produccion`
// (que apunta al servicio real de facturación). `desarrollo` es el tercero.

$configReal = require dirname(__DIR__) . '/config/config.php';

/**
 * Ejecuta una función con `APP_ENV` fijado y restaura el valor previo.
 *
 * `getenv()` no tiene forma de "desfijar" una variable en PHP, de modo que se
 * restaura el valor original o, si no había, se declara vacía.
 *
 * @param string|null $valor  Valor a fijar, o `null` para simular que no existe.
 * @param callable    $accion Comprobación a ejecutar con la variable fijada.
 *
 * @return mixed
 */
$conEntorno = static function (?string $valor, callable $accion) {
    $previo = getenv('APP_ENV');

    if ($valor === null) {
        putenv('APP_ENV');
    } else {
        putenv('APP_ENV=' . $valor);
    }

    try {
        return $accion();
    } finally {
        if ($previo === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $previo);
        }
    }
};

$assert(
    'entorno: sin APP_ENV se asume local',
    $conEntorno(null, static function (): string {
        return Env::appEnv();
    }) === 'local'
);
$assert(
    'entorno: APP_ENV=desarrollo se reconoce',
    $conEntorno('desarrollo', static function (): string {
        return Env::appEnv();
    }) === 'desarrollo'
);
$assert(
    'entorno: un APP_ENV mal escrito degrada a local y no a produccion',
    $conEntorno('producción', static function (): string {
        return Env::appEnv();
    }) === 'local'
);

$assert(
    'entorno: desarrollo se detecta como tal',
    $conEntorno('desarrollo', static function () use ($configReal): bool {
        return (new AppConfig($configReal))->isDevelopment();
    }) === true
);
$assert(
    'entorno: desarrollo no se confunde con produccion',
    $conEntorno('desarrollo', static function () use ($configReal): bool {
        return (new AppConfig($configReal))->isProduction();
    }) === false
);

// El motivo de existir de `desarrollo`: en un servidor público, un error 500 no
// puede devolver el tipo de excepción, la ruta del archivo y la traza.
$assert(
    'entorno: desarrollo no muestra el detalle de los errores',
    $conEntorno('desarrollo', static function () use ($configReal): bool {
        return (new AppConfig($configReal))->shouldDisplayErrors();
    }) === false
);
$assert(
    'entorno: produccion tampoco los muestra',
    $conEntorno('produccion', static function () use ($configReal): bool {
        return (new AppConfig($configReal))->shouldDisplayErrors();
    }) === false
);
$assert(
    'entorno: local sí los muestra, que es lo que se quiere en un escritorio',
    $conEntorno('local', static function () use ($configReal): bool {
        return (new AppConfig($configReal))->shouldDisplayErrors();
    }) === true
);

$configDesarrollo = $conEntorno('desarrollo', static function () {
    return require dirname(__DIR__) . '/config/config.php';
});

$assert(
    'entorno: desarrollo apunta al sandbox',
    strpos((string) $configDesarrollo['fel']['endpoint_url'], 'feltestSB') !== false,
    (string) $configDesarrollo['fel']['endpoint_url']
);
$assert(
    'entorno: desarrollo nunca apunta al servicio real de facturación',
    strpos((string) $configDesarrollo['fel']['endpoint_url'], '/fel/') === false
);
$assert(
    'entorno: produccion sí apunta al servicio real',
    strpos((string) $conEntorno('produccion', static function () {
        return require dirname(__DIR__) . '/config/config.php';
    })['fel']['endpoint_url'], '/fel/') !== false
);
$assert(
    'entorno: desarrollo no hereda las credenciales de arranque de local',
    $conEntorno('desarrollo', static function () use ($configDesarrollo): bool {
        $config = new AppConfig($configDesarrollo);

        return $config->getAuthConfig()['username'] === '' && $config->getDbConfig()['username'] === '';
    }) === true
);
$assert(
    'entorno: desarrollo arranca sin lista blanca de CORS, nunca con comodín',
    $configDesarrollo['cors']['allowed_origins'] === []
);

// -----------------------------------------------------------------------------
echo "\n== Capa de sobreescritura local ==\n";

// La fusión por sección es lo que permite desplegar en un hosting compartido,
// donde `SetEnv` no siempre llega al proceso de PHP. Se ejercita el archivo
// real, pero aislado: se copia a un directorio temporal junto con un
// `config.local.php` de mentira, porque el archivo busca la capa local en su
// propio `__DIR__`. Así no se toca la configuración real de la máquina.

$tmpDir = sys_get_temp_dir() . '/fel-config-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
copy(dirname(__DIR__) . '/config/config.php', $tmpDir . '/config.php');

$configEn = static function (string $entorno, string $cuerpoLocal) use ($tmpDir): array {
    $previo = getenv('APP_ENV');
    putenv('APP_ENV=' . $entorno);
    file_put_contents($tmpDir . '/config.local.php', $cuerpoLocal);

    try {
        return require $tmpDir . '/config.php';
    } finally {
        if ($previo === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $previo);
        }
    }
};

// La plantilla tal como se distribuye: todas las cadenas vacías.
$plantilla = <<<'PHP'
<?php
return [
    'db' => ['host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''],
    'auth' => ['username' => '', 'password' => '', 'realm' => ''],
    'fel' => [
        'username' => '', 'password' => '', 'nit' => '', 'establishment' => '',
        'basic_username' => '', 'basic_password' => '', 'wsdl_url' => '', 'endpoint_url' => '',
    ],
];
PHP;

// Este es el caso peligroso: la plantilla en blanco no debe degradar la
// producción al sandbox. Si una cadena vacía contara como sobreescritura, el
// `wsdl_url` real quedaría en '' y `GuatefacturasConfig` caería a su valor por
// defecto, que es el sandbox. La aplicación emitiría documentos de prueba
// creyendo que emite de verdad.
$conPlantilla = $configEn('produccion', $plantilla);
$assert(
    'capa local: la plantilla en blanco no degrada produccion al sandbox',
    strpos((string) $conPlantilla['fel']['endpoint_url'], '/fel/') !== false,
    (string) $conPlantilla['fel']['endpoint_url']
);
$assert(
    'capa local: la plantilla en blanco no borra las credenciales de auth del entorno',
    (new AppConfig($conPlantilla))->getAuthConfig()['username'] === ''
);

$conDatos = $configEn('desarrollo', <<<'PHP'
<?php
return [
    'db'     => ['host' => 'localhost', 'database' => 'api_fel', 'username' => 'app', 'password' => 'secreto'],
    'auth'   => ['username' => 'apiuser', 'password' => 'apisecreto'],
    'fel'    => ['username' => 'feltest', 'password' => 'feltest', 'nit' => 'C/F'],
    'cors'   => ['allowed_origins' => ['https://pos.dentallgt.com']],
];
PHP);

$assert(
    'capa local: db se sobreescribe desde el archivo local',
    $conDatos['db']['database'] === 'api_fel' && $conDatos['db']['host'] === 'localhost'
);
$assert(
    'capa local: los campos no declarados conservan su valor por defecto',
    $conDatos['db']['charset'] === 'utf8mb4'
);
$assert(
    'capa local: auth se sobreescribe desde el archivo local',
    $conDatos['auth']['username'] === 'apiuser'
);
$assert(
    'capa local: se fusiona auth, no solo fel',
    $conDatos['fel']['username'] === 'feltest' && $conDatos['auth']['password'] === 'apisecreto'
);
$assert(
    'capa local: desarrollo sigue apuntando al sandbox con la capa local presente',
    strpos((string) $conDatos['fel']['endpoint_url'], 'feltestSB') !== false
);
$assert(
    'capa local: un array vacío sí sobreescribe',
    $configEn('desarrollo', "<?php return ['cors' => ['allowed_origins' => []]];")['cors']['allowed_origins'] === []
);

unlink($tmpDir . '/config.local.php');
unlink($tmpDir . '/config.php');
rmdir($tmpDir);

// -----------------------------------------------------------------------------
echo "\n== Fecha del documento ==\n";

// La fecha del documento es local del emisor. Con `gmdate()` se documentaba el
// día siguiente entre las 18:00 y las 24:00 hora de Guatemala, y el proveedor
// rechazaba la factura con el error 56.
$timezone = new DateTimeZone((string) $billingRules['document_timezone']);

$assert(
    'la zona por defecto es una zona real',
    in_array($timezone->getName(), DateTimeZone::listIdentifiers(), true),
    $timezone->getName()
);

$utcNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$localNow  = new DateTimeImmutable('now', $timezone);

// `Z` da el desfase en segundos con signo respecto a UTC: en Guatemala es
// negativo, de modo que la resta debe hacerse en ese orden.
$offsetMin = (int) $localNow->format('Z') - (int) $utcNow->format('Z');

$assert(
    'la fecha local nunca puede ir por delante de la fecha UTC',
    $localNow->format('Ymd') <= $utcNow->format('Ymd'),
    'UTC=' . $utcNow->format('Y-m-d H:i') . ' local=' . $localNow->format('Y-m-d H:i')
);

$assert('Guatemala está seis horas por detrás de UTC', $offsetMin === -21600, 'offset=' . $offsetMin);

// En la franja crítica (18:00-24:00 hora local) la fecha UTC sí es la siguiente.
$critical = new DateTimeImmutable('2026-10-13 21:30:00', $timezone);
$assert(
    'a las 21:30 hora de Guatemala la fecha UTC ya es del día siguiente',
    $critical->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y') !== $critical->format('d/m/Y'),
    'local=' . $critical->format('d/m/Y') . ' utc=' . $critical->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y')
);
$assert(
    'por eso la fecha del documento no puede tomarse de UTC',
    $critical->format('d/m/Y') === '13/10/2026',
    'documento=' . $critical->format('d/m/Y')
);

$ordenValida = [
    'codigo_orden' => 'POS-0001',
    'cliente'      => [
        'nit'       => 'C/F',
        'nombre'    => 'Juan Pérez',
        'direccion' => 'Zona 1',
        'correo'    => 'juan@ejemplo.com',
        'telefono'  => '51234567',
    ],
    'carrito'      => [
        ['codigo' => 'SRV-1', 'descripcion' => 'Consulta', 'cantidad' => 1, 'precio_unitario' => 100.00],
    ],
    'facturacion'  => ['tipo_documento' => 'FACT'],
];

$parsedOrder = OrderPayload::fromArray($ordenValida, $billingRules);
$assert('orden: la orden de ejemplo es válida', $parsedOrder['ok'], print_r($parsedOrder['errors'], true));
$assert('orden: el código se conserva', $parsedOrder['order']->orderCode() === 'POS-0001');
$assert('orden: FACT se resuelve al código 1', $parsedOrder['order']->dteType() === 1);
$assert('orden: aplica el IVA por defecto', $parsedOrder['order']->totals()->tax()->cents() === 1200);
$assert('orden: el total es 112.00', $parsedOrder['order']->totals()->total()->toString() === '112.00');
$assert('orden: sin entrega, no hay bloque de entrega', $parsedOrder['order']->delivery()['DireccionEntrega'] === '');

$assert('orden: acepta el tipo numérico', OrderPayload::fromArray(
    array_replace_recursive($ordenValida, ['facturacion' => ['tipo_documento' => 1]]),
    $billingRules
)['ok']);

$assert('orden: acepta "fact" en minúsculas', OrderPayload::fromArray(
    array_replace_recursive($ordenValida, ['facturacion' => ['tipo_documento' => 'fact']]),
    $billingRules
)['ok']);

$bad = OrderPayload::fromArray(
    [
        'codigo_orden' => '',
        'cliente'      => ['nit' => ''],
        'carrito'      => [['codigo' => '', 'descripcion' => '', 'cantidad' => 0, 'precio_unitario' => 'abc']],
        'facturacion'  => ['tipo_documento' => 'XXXX'],
        'campoExtra'   => 1,
    ],
    $billingRules
);

$assert('orden: una orden inválida se rechaza', !$bad['ok']);

$expectedErrorFields = [
    'codigo_orden', 'cliente.nit', 'cliente.nombre', 'cliente.direccion',
    'facturacion.tipo_documento', 'carrito.0.codigo', 'carrito.0.descripcion',
    'carrito.0.cantidad', 'carrito.0.precio_unitario', 'campoExtra',
];

foreach ($expectedErrorFields as $field) {
    $assert(sprintf('orden: se informa el error de "%s"', $field), isset($bad['errors'][$field]));
}

$assert(
    'orden: acumula todos los errores de una pasada',
    count($bad['errors']) >= count($expectedErrorFields),
    'recibidos=' . count($bad['errors'])
);

// `array_replace_recursive` fusiona en vez de reemplazar cuando el valor nuevo
// es un array vacío, de modo que el carrito vacío se asigna de forma directa.
$ordenSinCarrito = $ordenValida;
$ordenSinCarrito['carrito'] = [];

$assert('orden: rechaza un carrito vacío', !OrderPayload::fromArray($ordenSinCarrito, $billingRules)['ok']);

$ordenTipoInexistente = $ordenValida;
$ordenTipoInexistente['facturacion'] = ['tipo_documento' => 'NOPE'];

$assert('orden: rechaza un tipo de documento inexistente', !OrderPayload::fromArray($ordenTipoInexistente, $billingRules)['ok']);

$ordenImprecisa = $ordenValida;
$ordenImprecisa['carrito'] = [
    ['codigo' => 'X', 'descripcion' => 'Y', 'cantidad' => 1, 'precio_unitario' => '10.005'],
];

$assert('orden: rechaza un precio con demasiada precisión', !OrderPayload::fromArray($ordenImprecisa, $billingRules)['ok']);

$ordenConCampoExtra = $ordenValida;
$ordenConCampoExtra['cliente'] = ['apellido' => 'Pérez'];

$assert('orden: rechaza un campo no permitido en cliente', !OrderPayload::fromArray($ordenConCampoExtra, $billingRules)['ok']);

$ordenExenta = $ordenValida;
$ordenExenta['carrito'] = [
    ['codigo' => 'X', 'descripcion' => 'Y', 'cantidad' => 1, 'precio_unitario' => 100.00, 'exento' => true],
];

$exenta = OrderPayload::fromArray($ordenExenta, $billingRules);

$assert('orden: la exención se respeta', $exenta['order']->totals()->tax()->isZero());
$assert('orden: una línea exenta conserva el importe en el total', $exenta['order']->totals()->total()->cents() === 10000);

echo "\n== Clasificador de respuestas del proveedor ==\n";

// Textos literales observados contra el sandbox real.
$emitido = '<Resultado><Serie>F1</Serie><Preimpreso>1</Preimpreso>'
    . '<NumeroAutorizacion>ABC</NumeroAutorizacion></Resultado>';

$assert('clasificador: una emisión autorizada se reconoce como emitida',
    ProviderResponseClassifier::classify($emitido) === ProviderResponseClassifier::OUTCOME_ISSUED);

$assert('clasificador: una emisión no es ambigua',
    ProviderResponseClassifier::isAmbiguous(ProviderResponseClassifier::classify($emitido)) === false);

$assert('clasificador: el envoltorio <Resultado> no oculta un rechazo',
    ProviderResponseClassifier::classify(
        '<Resultado>56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR A LA FECHA DEL SISTEMA</Resultado>'
    ) === ProviderResponseClassifier::OUTCOME_REJECTED);

$assert('clasificador: un rechazo con la palabra ERROR es rechazo',
    ProviderResponseClassifier::classify(
        'ERROR el TAG1 ImpIva no existe o viene mal definido en el formato XML'
    ) === ProviderResponseClassifier::OUTCOME_REJECTED);

$assert('clasificador: el error 10 de cuadre de totales es rechazo',
    ProviderResponseClassifier::classify(
        '<Resultado>10-ERROR DE CALCULO IMPORTE TOTAL NO CUADRA CON NETO + IVA</Resultado>'
    ) === ProviderResponseClassifier::OUTCOME_REJECTED);

$assert('clasificador: una falla interna del motor es rechazo',
    ProviderResponseClassifier::classify(
        'ERRORES:ORA-01403: No se ha encontrado ningún dato'
    ) === ProviderResponseClassifier::OUTCOME_REJECTED);

// El hallazgo que obliga a clasificar el 82 aparte: el mismo mensaje aparece
// cuando el importe está mal y cuando se excede el límite de emisiones.
$porLimite = ProviderResponseClassifier::classify(
    '<Resultado>82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO</Resultado>'
);

$assert('clasificador: el error 82 no se toma como rechazo definitivo',
    $porLimite === ProviderResponseClassifier::OUTCOME_RATE_LIMITED);
$assert('clasificador: el error 82 se marca como ambiguo',
    ProviderResponseClassifier::isAmbiguous($porLimite));
$assert('clasificador: el error 82 admite reintento',
    ProviderResponseClassifier::isRetryable($porLimite));

$assert('clasificador: una respuesta vacía es ambigua',
    ProviderResponseClassifier::classify('<Resultado></Resultado>') === ProviderResponseClassifier::OUTCOME_EMPTY);
$assert('clasificador: una cadena vacía es ambigua',
    ProviderResponseClassifier::isAmbiguous(ProviderResponseClassifier::classify('   ')));
$assert('clasificador: un rechazo es reintentable pero no ambiguo',
    ProviderResponseClassifier::isRetryable(ProviderResponseClassifier::OUTCOME_REJECTED)
    && ProviderResponseClassifier::isAmbiguous(ProviderResponseClassifier::OUTCOME_REJECTED) === false);

$assert('código: extrae el número de un rechazo',
    ProviderResponseClassifier::errorCode('56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR') === '56');
$assert('código: extrae el número del error 82',
    ProviderResponseClassifier::errorCode('82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO') === '82');
$assert('código: extrae el ORA de una falla del motor',
    ProviderResponseClassifier::errorCode('ERRORES:ORA-01400: valor nulo') === 'ORA-01400');
$assert('código: un rechazo sin numeración se etiqueta como ERROR',
    ProviderResponseClassifier::errorCode('ERROR el TAG1 ImpIva no existe') === 'ERROR');
$assert('código: una emisión autorizada no tiene código de error',
    ProviderResponseClassifier::errorCode($emitido) === null);
$assert('código: una respuesta vacía no tiene código de error',
    ProviderResponseClassifier::errorCode('<Resultado></Resultado>') === null);

// Fallos de transporte: la razón por la que un SoapFault no puede tratarse
// como rechazo de negocio.
$transportes = [
    'Error Fetching http headers',
    'HTTP Error 503 Service Unavailable',
    'Operation timed out after 60000 milliseconds',
    'Connection refused',
    'Could not connect to host',
    'php_network_getaddresses: getaddrinfo failed',
    'SSL: certificate verify failed',
    'Empty reply from server',
    '502 Bad Gateway',
];

foreach ($transportes as $fallo) {
    $assert(sprintf(
        'transporte: «%s» se reconoce como caída del servicio',
        $fallo
    ), ProviderResponseClassifier::isTransportMessage($fallo));
}

// Y lo contrario, que es lo que evita falsos positivos.
$negocios = [
    'ERROR el TAG1 ImpIva no existe o viene mal definido en el formato XML',
    '56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR A LA FECHA DEL SISTEMA',
    '82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO',
];

foreach ($negocios as $fallo) {
    $assert(sprintf(
        'negocio: «%s» NO se confunde con una caída',
        substr($fallo, 0, 34)
    ), ProviderResponseClassifier::isTransportMessage($fallo) === false);
}

echo "\n== Importes en la representación del proveedor ==\n";

// La guía operativa documenta que `Precio` es el unitario CON IVA y que
// `ImpBruto` es precio por cantidad. Su ejemplo (precio 10, cantidad 101) da
// Bruto 1010 y Neto 901.79, que es 1010 / 1.12.
$manual = ImportesProveedor::line(new OrderLine(
    'BOLSAS',
    'Bolsas blancas',
    Decimal::parse('101', 3, 'cantidad'),
    Decimal::fromCents(1000),
    Decimal::zero(4),
    Decimal::zero(4),
    Decimal::parse('12', 2, 'iva'),
    false
));

$assert('proveedor: el precio lleva el IVA incluido', $manual['precio']->cents() === 1120, $manual['precio']->toString());
$assert('proveedor: Bruto = precio x cantidad', $manual['bruto']->cents() === 113120, $manual['bruto']->toString());
$assert('proveedor: Neto = Bruto / 1.12', $manual['neto']->cents() === 101000, $manual['neto']->toString());
$assert('proveedor: Iva = Neto x 12 %', $manual['iva']->cents() === 12120, $manual['iva']->toString());
$assert('proveedor: Total = Neto + Iva', $manual['total']->cents() === 113120, $manual['total']->toString());

// El neto de negocio no cambia: es lo que hace que la conversión sea reversible.
$simple = ImportesProveedor::line(new OrderLine(
    'SRV-1',
    'Consulta',
    Decimal::parse('2', 3, 'cantidad'),
    Decimal::fromCents(10000),
    Decimal::zero(4),
    Decimal::zero(4),
    Decimal::parse('12', 2, 'iva'),
    false
));

$assert('proveedor: el neto coincide con el de negocio', $simple['neto']->cents() === 20000, $simple['neto']->toString());
$assert('proveedor: el IVA coincide con el de negocio', $simple['iva']->cents() === 2400, $simple['iva']->toString());
$assert('proveedor: el total coincide con el de negocio', $simple['total']->cents() === 22400, $simple['total']->toString());

// Con descuento, el descuento se calcula sobre la base con IVA para que el neto
// resultante siga cuadrando con el de negocio.
$conDescuento = ImportesProveedor::line(new OrderLine(
    'SRV-2',
    'Limpieza',
    Decimal::parse('2', 3, 'cantidad'),
    Decimal::fromCents(10000),
    Decimal::parse('10', 4, 'descuento'),
    Decimal::zero(4),
    Decimal::parse('12', 2, 'iva'),
    false
));

$assert('proveedor: Bruto con descuento', $conDescuento['bruto']->cents() === 22400, $conDescuento['bruto']->toString());
$assert('proveedor: descuento sobre la base con IVA', $conDescuento['descuento']->cents() === 2240, $conDescuento['descuento']->toString());
$assert('proveedor: Neto con descuento', $conDescuento['neto']->cents() === 18000, $conDescuento['neto']->toString());
$assert('proveedor: Total con descuento', $conDescuento['total']->cents() === 20160, $conDescuento['total']->toString());

// Una línea exenta no lleva IVA en el precio.
$exento = ImportesProveedor::line(new OrderLine(
    'SRV-3',
    'Servicio exento',
    Decimal::parse('1', 3, 'cantidad'),
    Decimal::fromCents(3000),
    Decimal::zero(4),
    Decimal::zero(4),
    Decimal::parse('12', 2, 'iva'),
    true
));

$assert('proveedor: una línea exenta no infla el precio', $exento['precio']->cents() === 3000, $exento['precio']->toString());
$assert('proveedor: el importe exento viaja en Exento', $exento['exento']->cents() === 3000, $exento['exento']->toString());
$assert('proveedor: una línea exenta no genera IVA', $exento['iva']->isZero());
$assert('proveedor: el neto de una línea exenta es cero', $exento['neto']->isZero());

// Los totales de cabecera son la suma de las líneas.
$totalesProveedor = ImportesProveedor::totals([$lineaSimple, $lineaExenta]);

$assert('proveedor: Bruto de cabecera', $totalesProveedor['bruto']->cents() === 25400, $totalesProveedor['bruto']->toString());
$assert('proveedor: Neto de cabecera', $totalesProveedor['neto']->cents() === 20000, $totalesProveedor['neto']->toString());
$assert('proveedor: Iva de cabecera', $totalesProveedor['iva']->cents() === 2400, $totalesProveedor['iva']->toString());
$assert('proveedor: Total de cabecera', $totalesProveedor['total']->cents() === 25400, $totalesProveedor['total']->toString());

echo "\n== Tercer formato de error del proveedor ==\n";

// El proveedor tiene tres formatos. El tercero apareció al enviar una factura
// exenta y llegaba envuelto dos veces: sin pelar todas las capas y sin reconocer
// la etiqueta, el rechazo pasaba por emisión correcta.
$anidado = '<Resultado><Resultado><ERROR-1094>[Frase:]El Tipo de DTE contiene FACT</Resultado></Resultado>';

$assert('clasificador: el error anidado en dos envolturas se detecta',
    ProviderResponseClassifier::classify($anidado) === ProviderResponseClassifier::OUTCOME_REJECTED);
$assert('código: extrae el número de la etiqueta <ERROR-NNNN>',
    ProviderResponseClassifier::errorCode($anidado) === '1094');
$assert('anulador: el error anidado también es rechazo',
    ProviderResponseClassifier::isAnnulmentRejection($anidado));

echo "\n----------------------------------------\n";
echo sprintf("Pasaron: %d | Fallaron: %d\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);