<?php

/**
 * Developed by: Web art studios
 * Project: API middleware Guatefacturas
 * Year: 2026
 * All rights reserved. Proprietary software.
 */

declare(strict_types=1);

/**
 * Punto de arranque único (bootstrap) de la aplicación.
 *
 * Responsabilidades, en este orden:
 * 1. Registrar el autoloader PSR-4 para el namespace `App\`.
 * 2. Cargar la configuración y resolver el entorno activo.
 * 3. Aplicar la política de errores según el entorno.
 * 4. Registrar el manejador global de excepciones.
 * 5. Instanciar y cablear todas las dependencias (contenedor simple).
 *
 * No emite ninguna salida: devuelve un array con las instancias ya construidas.
 *
 * @package App
 *
 * @author  fel-dentall
 * @license MIT
 */

/**
 * Registra el autoloader PSR-4 del proyecto.
 *
 * Sin Composer: se traduce `App\Core\Uuid` a `app/Core/Uuid.php`. Es el
 * mecanismo que permite cargar las 17 clases del proyecto sin un autoloader
 * externo y sin `require` manuales dispersos.
 *
 * @param string $appRoot Ruta absoluta de la carpeta `app/`.
 *
 * @return void
 */
$registerAutoloader = static function (string $appRoot): void {
    spl_autoload_register(static function (string $class) use ($appRoot): void {
        $prefix = 'App\\';
        $length = strlen($prefix);

        if (strncmp($class, $prefix, $length) !== 0) {
            return;
        }

        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, $length));
        $file         = $appRoot . DIRECTORY_SEPARATOR . $relativePath . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
};

$registerAutoloader(__DIR__);

/** @var array<string, mixed> $rawConfig */
$rawConfig = require dirname(__DIR__) . '/config/config.php';

$config = new App\Core\AppConfig($rawConfig);

// La política de errores se aplica ANTES de registrar los manejadores: cualquier
// error previo a este punto ya se emite con la configuración correcta.
$displayErrors = $config->shouldDisplayErrors();
$errorLevel    = $displayErrors
    ? E_ALL
    : (E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

error_reporting($errorLevel);
ini_set('display_errors', $displayErrors ? '1' : '0');
ini_set('display_startup_errors', $displayErrors ? '1' : '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
date_default_timezone_set($config->getTimezone());

$database         = new App\Core\Database($config);
$exceptionHandler = new App\Core\ExceptionHandler($config, $database);
$exceptionHandler->register();

App\Core\Response::configureCors($config->getCorsConfig()['allowed_origins']);

$request   = App\Core\Request::capture();
$auth      = new App\Core\Auth($config);
$repository = new App\Repositories\ItemRepository($database);
$service   = new App\Services\ItemService($repository);
$controller = new App\Controllers\ItemController($service, $request, $config);
$router     = new App\Core\Router();

// Dependencias de la facturación electrónica. El cliente SOAP se construye
// siempre, pero no abre ninguna conexión hasta la primera llamada: una
// configuración incompleta no impide que la API sirva el resto de rutas.
$gfConfig      = new App\Integrations\Guatefacturas\GuatefacturasConfig($config);
$gfClient      = new App\Integrations\Guatefacturas\GuatefacturasClient($gfConfig);
$gfRepository  = new App\Repositories\GfOrderRepository($database);
$gfService     = new App\Services\FacturacionService($gfRepository, $gfClient, $config);
$gfRetryPolicy = App\Services\RetryPolicy::fromRules($config->getBillingConfig());
$gfRetry       = new App\Services\ReintentoService($gfRepository, $gfClient, $gfRetryPolicy);
$gfAnnulment   = new App\Services\AnulacionService($gfRepository, $gfClient, $config);
$gfController  = new App\Controllers\FacturacionController($gfService, $gfRetry, $gfAnnulment, $request);

/**
 * =============================================================================
 * PUNTO ÚNICO DE REGISTRO DE RUTAS
 * =============================================================================
 *
 * TODA ruta nueva debe declararse aquí, nunca en `public/index.php` ni en un
 * router propio. Motivos:
 *
 * - Es el único lugar donde el autoloader, la configuración de errores y el
 *   manejador de excepciones ya están aplicados.
 * - `Router::registerController()` exige que la instancia esté construida con
 *   sus dependencias; construirlas fuera del contenedor rompe la trazabilidad.
 * - Mantener el orden de declaración permite que la resolución sea determinista:
 *   gana la primera ruta que casa con el verbo y el patrón.
 *
 * Verbos disponibles: `get()`, `post()`, `put()`, `patch()`, `delete()` y
 * `any()` (rutas transversales, que se evalúan en un segundo paso).
 *
 * Recomendación: agrupar por recurso, y declarar la ruta genérica `any()`
 * justo después de las rutas del recurso, nunca al principio.
 * =============================================================================
 */

$router->registerController(App\Controllers\ItemController::class, $controller);
$router->registerController(App\Controllers\FacturacionController::class, $gfController);

// Rutas del recurso `items`. El marcador `{uuid}` se compila a un patrón que
// exige el formato canónico UUID v4 (8-4-4-4-12).
$router->get('/api/items', [App\Controllers\ItemController::class, 'index']);
$router->post('/api/items', [App\Controllers\ItemController::class, 'create']);
$router->get('/api/items/{uuid}', [App\Controllers\ItemController::class, 'show']);
$router->put('/api/items/{uuid}', [App\Controllers\ItemController::class, 'update']);
$router->delete('/api/items/{uuid}', [App\Controllers\ItemController::class, 'delete']);

// Ruta genérica: `/api/items/xxx` no casa con el patrón UUID, así que este es el
// único punto donde puede resolverse. Delega el diagnóstico en el servicio,
// que distingue entre UUID mal formado (400) y UUID inexistente (404).
$router->any('/api/items/{param}', static function (App\Core\Request $request): void {
    $uuid = (string) $request->param('param', '');

    $errors = App\Core\Validator::uuid($uuid, 'uuid');

    if ($errors !== []) {
        App\Core\Response::error($errors[0], 400, ['uuid' => $errors]);
        return;
    }

    App\Core\Response::error('No existe un item con el UUID indicado.', 404);
});

// Rutas de facturación electrónica. El recurso se llama `facturacion/ordenes` y
// no `gf_orders` porque el nombre interno de la tabla no es un contrato de API:
// quien integra es el POS, que conoce el concepto de orden, no la tabla.
$router->post('/api/facturacion/ordenes', [App\Controllers\FacturacionController::class, 'store']);
$router->post('/api/facturacion/ordenes/{uuid}/reintento', [App\Controllers\FacturacionController::class, 'retry']);
$router->post('/api/facturacion/ordenes/{uuid}/anular', [App\Controllers\FacturacionController::class, 'annul']);
$router->get('/api/facturacion/ordenes/{uuid}', [App\Controllers\FacturacionController::class, 'show']);

return [
    'config'           => $config,
    'request'          => $request,
    'auth'             => $auth,
    'database'         => $database,
    'exceptionHandler' => $exceptionHandler,
    'repository'       => $repository,
    'service'          => $service,
    'controller'       => $controller,
    'router'           => $router,
    'gfConfig'         => $gfConfig,
    'gfClient'         => $gfClient,
    'gfRepository'     => $gfRepository,
    'facturacion'      => $gfService,
    'gfController'     => $gfController,
    // Cada controlador nuevo se añade a esta misma clave del contenedor, para
    // que los tests de integración puedan resolverlo por nombre de clase.
];