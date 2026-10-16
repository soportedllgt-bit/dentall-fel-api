<?php

/**
 * Developed by: Web art studios
 * Project: API middleware Guatefacturas
 * Year: 2026
 * All rights reserved. Proprietary software.
 */

declare(strict_types=1);

/**
 * Front Controller de la API RESTful.
 *
 * Es el único archivo accesible desde el navegador. Todas las peticiones que no
 * correspondan a un archivo o directorio existente se reescriben aquí mediante
 * `public/.htaccess`.
 *
 * Flujo de la petición:
 * 1. Carga el contenedor de dependencias desde `app/bootstrap.php`.
 * 2. Responde `204 No Content` a las peticiones `OPTIONS` (preflight CORS).
 * 3. Exige HTTP Basic Auth en toda ruta con prefijo `/api/`.
 * 4. Despacha la petición al router.
 *
 * @package App
 *
 * @author  fel-dentall
 * @license MIT
 */

/** @var array<string, mixed> $container */
$container = require dirname(__DIR__) . '/app/bootstrap.php';

/** @var App\Core\Request $request */
$request = $container['request'];

/** @var App\Core\Auth $auth */
$auth = $container['auth'];

/** @var App\Core\Router $router */
$router = $container['router'];

// 1. Preflight CORS: responde 204 sin cuerpo y sin exigir autenticación,
//    tal como exige la especificación de CORS.
if ($request->method() === 'OPTIONS') {
    App\Core\Response::noContent();
    exit;
}

// 2. Autenticación obligatoria de todo el espacio de nombres `/api/`.
if (strpos($request->path(), '/api/') === 0) {
    if (!$auth->requireAuth($request)) {
        exit;
    }
}

// 3. Despacho de la petición al router.
$router->dispatch($request);