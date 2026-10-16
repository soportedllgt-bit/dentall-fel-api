<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Router mínimo basado en expresiones regulares.
 *
 * Soporta marcadores con nombre del tipo `{uuid}`, que se traducen a grupos de
 * captura con nombre en el patrón final. Las rutas se evalúan en el orden en que
 * fueron registradas: la primera que casa con el verbo y la ruta gana.
 *
 * Resolución de una petición:
 * 1. Coincidencia de verbo y ruta -> se ejecuta el manejador.
 * 2. La ruta casa con otro verbo -> `405 Method Not Allowed` + cabecera `Allow`.
 * 3. Una ruta genérica casa -> se ejecuta (por ejemplo, validar el UUID).
 * 4. Ninguna coincidencia -> `404 Not Found`.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Router
{
    /**
     * Rutas registradas, indexadas por verbo HTTP.
     *
     * @var array<string, array<int, array{pattern: string, regex: string, handler: mixed, params: array<int, string>}>>
     */
    private $routes = [];

    /**
     * Rutas que responden a cualquier verbo, evaluadas en un segundo paso.
     *
     * @var array<int, array{pattern: string, regex: string, handler: mixed, params: array<int, string>}>
     */
    private $fallbacks = [];

    /**
     * Instancias de controladores indexadas por nombre de clase.
     *
     * @var array<string, object>
     */
    private $controllers = [];

    /**
     * Verbos HTTP reconocidos por el router.
     *
     * Cada verbo tiene su método de registro homónimo: {@see Router::get()},
     * {@see Router::post()}, {@see Router::put()}, {@see Router::patch()} y
     * {@see Router::delete()}. Mantener ambas listas sincronizadas es
     * indispensable para que un verbo no pueda aceptarse y luego rechazarse.
     *
     * @var array<int, string>
     */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Registra un controlador ya instanciado para poder resolverlo al despachar.
     *
     * @param string $class      Nombre de la clase del controlador.
     * @param object $controller Instancia del controlador.
     *
     * @return void
     */
    public function registerController(string $class, object $controller): void
    {
        $this->controllers[$class] = $controller;
    }

    /**
     * Registra una ruta para el verbo `GET`.
     *
     * @param string $pattern Patrón con marcadores `{nombre}`.
     * @param mixed  $handler Controlador y método, o una función callable.
     *
     * @return void
     */
    public function get(string $pattern, $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * Registra una ruta para el verbo `POST`.
     *
     * @param string $pattern Patrón con marcadores `{nombre}`.
     * @param mixed  $handler Controlador y método, o una función callable.
     *
     * @return void
     */
    public function post(string $pattern, $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * Registra una ruta para el verbo `PUT`.
     *
     * @param string $pattern Patrón con marcadores `{nombre}`.
     * @param mixed  $handler Controlador y método, o una función callable.
     *
     * @return void
     */
    public function put(string $pattern, $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    /**
     * Registra una ruta para el verbo `DELETE`.
     *
     * @param string $pattern Patrón con marcadores `{nombre}`.
     * @param mixed  $handler Controlador y método, o una función callable.
     *
     * @return void
     */
    public function delete(string $pattern, $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Registra una ruta para el verbo `PATCH`.
     *
     * El verbo forma parte de {@see Router::ALLOWED_METHODS} y del anuncio
     * CORS del preflight, por lo que el router debe ofrecerlo. No todas las
     * rutas lo registran: la cabecera `Allow` de un `405` se construye a
     * partir de las rutas realmente declaradas, de modo que un recurso sin
     * `PATCH` no lo anuncia.
     *
     * @param string $pattern Patrón con marcadores `{nombre}`.
     * @param mixed  $handler Controlador y método, o una función callable.
     *
     * @return void
     */
    public function patch(string $pattern, $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    /**
     * Registra una ruta que responde a cualquier verbo HTTP.
     *
     * Se usa para differentiate entre "ruta inexistente" y "identificador mal
     * formado": `/api/items/xxx` no casa con el patrón UUID y sí con este.
     *
     * @param string $pattern Patrón con marcadores `{nombre}`.
     * @param mixed  $handler Controlador y método, o una función callable.
     *
     * @return void
     */
    public function any(string $pattern, $handler): void
    {
        $compiled = $this->compile($pattern);

        $this->fallbacks[] = [
            'pattern' => $pattern,
            'regex'   => $compiled['regex'],
            'handler' => $handler,
            'params'  => $compiled['params'],
        ];
    }

    /**
     * Resuelve y ejecuta la petición recibida.
     *
     * @param Request $request Petición ya normalizada.
     *
     * @return void
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();

        // Si el verbo no está soportado, se responde 405 antes de buscar rutas.
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            Response::methodNotAllowed(
                sprintf('El verbo %s no está permitido en este recurso.', $method),
                self::ALLOWED_METHODS
            );
            return;
        }

        $allowedMethods = [];

        foreach ($this->routes[$method] ?? [] as $route) {
            $matches = [];

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $request->setRouteParams($this->extractParams($route['params'], $matches));
            $this->invoke($route['handler'], $request);

            return;
        }

        // La ruta puede existir aunque el verbo sea incorrecto: se registra para
        // construir la cabecera `Allow`.
        foreach ($this->routes as $verb => $routesOfVerb) {
            if ($verb === $method) {
                continue;
            }

            foreach ($routesOfVerb as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowedMethods[] = $verb;
                }
            }
        }

        if ($allowedMethods !== []) {
            Response::methodNotAllowed(
                sprintf('El verbo %s no está permitido en esta ruta.', $method),
                $allowedMethods
            );
            return;
        }

        foreach ($this->fallbacks as $route) {
            $matches = [];

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $request->setRouteParams($this->extractParams($route['params'], $matches));
            $this->invoke($route['handler'], $request);

            return;
        }

        Response::error(
            sprintf('No existe el recurso solicitado en la ruta %s.', $path),
            404
        );
    }

    /**
     * Traduce un patrón con marcadores a una expresión regular.
     *
     * @param string $pattern Patrón original, por ejemplo `/api/items/{uuid}`.
     *
     * @return array{regex: string, params: array<int, string>}
     *         Expresión regular y nombres de los parámetros capturados.
     */
    private function compile(string $pattern): array
    {
        $params = [];

        $regex = (string) preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use (&$params): string {
                $params[] = $matches[1];

                // Un UUID v4 canónico: 8-4-4-4-12 dígitos hexadecimales.
                if ($matches[1] === 'uuid') {
                    return '(?P<uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';
                }

                // Segmento genérico: cualquier cadena sin barras.
                return '(?P<' . $matches[1] . '>[^/]+)';
            },
            $pattern
        );

        return [
            'regex'  => '#^' . $regex . '$#u',
            'params' => $params,
        ];
    }

    /**
     * Extrae los parámetros capturados de una coincidencia.
     *
     * @param array<int, string> $params  Nombres de los parámetros del patrón.
     * @param array<string, mixed> $matches Resultado de `preg_match`.
     *
     * @return array<string, string> Mapa de nombre a valor.
     */
    private function extractParams(array $params, array $matches): array
    {
        $extracted = [];

        foreach ($params as $name) {
            if (isset($matches[$name]) && is_string($matches[$name])) {
                $extracted[$name] = $matches[$name];
            }
        }

        return $extracted;
    }

    /**
     * Ejecuta el manejador de una ruta.
     *
     * Admite dos formas de declaración:
     * - `[ItemController::class, 'show']`: se resuelve la instancia desde el
     *   registro de controladores y se invoca el método pasandole los
     *   parámetros de ruta como argumentos posicionales. Así, una acción
     *   `show(string $uuid)` recibe directamente el UUID capturado.
     * - Una función `callable` directa, por ejemplo un closure, que recibe la
     *   petición completa.
     *
     * @param mixed   $handler Manejador de la ruta.
     * @param Request $request Petición en curso.
     *
     * @return void
     *
     * @throws \RuntimeException Si el manejador no es ejecutable o no está registrado.
     */
    private function invoke($handler, Request $request): void
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            [$class, $action] = $handler;

            if (!isset($this->controllers[$class])) {
                throw new \RuntimeException(sprintf(
                    'El controlador "%s" no está registrado en el router.',
                    $class
                ));
            }

            $controller = $this->controllers[$class];

            if (!method_exists($controller, $action)) {
                throw new \RuntimeException(sprintf(
                    'El método "%s" no existe en el controlador "%s".',
                    $action,
                    $class
                ));
            }

            // Los parámetros de ruta se pasan como argumentos posicionales para
            // que las acciones puedan declarar su firma con tipos estrictos.
            $controller->{$action}(...array_values($request->routeParams()));

            return;
        }

        if (is_callable($handler)) {
            $handler($request);

            return;
        }

        throw new \RuntimeException('El manejador de la ruta no es ejecutable.');
    }

    /**
     * Añade una ruta al registro del verbo indicado.
     *
     * @param string $method  Verbo HTTP en mayúsculas.
     * @param string $pattern Patrón con marcadores `{nombre}`.
     * @param mixed  $handler Controlador y método, o función callable.
     *
     * @return void
     */
    private function add(string $method, string $pattern, $handler): void
    {
        $compiled = $this->compile($pattern);

        $this->routes[$method][] = [
            'pattern' => $pattern,
            'regex'   => $compiled['regex'],
            'handler' => $handler,
            'params'  => $compiled['params'],
        ];
    }
}