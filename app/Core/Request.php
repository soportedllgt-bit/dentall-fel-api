<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

/**
 * Envoltura inmutable de la petición HTTP entrante.
 *
 * La ruta se normaliza automáticamente: se descarta el query string, se elimina
 * la portion de la URL que corresponde al prefijo del proyecto dentro del
 * DocumentRoot y, si procede, el segmento `/public`. Esto permite que las
 * mismas rutas `/api/items` funcionen tanto si el `DocumentRoot` apunta a
 * `public/` como si apunta a la raíz del proyecto.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Request
{
    /**
     * Verbo HTTP de la petición, en mayúsculas.
     *
     * @var string
     */
    private $method;

    /**
     * Ruta normalizada, sin query string y sin prefijo de instalación.
     *
     * @var string
     */
    private $path;

    /**
     * Parámetros de query string.
     *
     * @var array<string, mixed>
     */
    private $query;

    /**
     * Cabeceras de la petición con clave normalizada en mayúsculas y guiones bajos.
     *
     * @var array<string, string>
     */
    private $headers;

    /**
     * Cuerpo bruto de la petición tal como llegó de `php://input`.
     *
     * @var string
     */
    private $rawBody;

    /**
     * Parámetros de ruta extraídos por el router.
     *
     * @var array<string, string>
     */
    private $routeParams = [];

    /**
     * @param string               $method  Verbo HTTP.
     * @param string               $path    Ruta ya normalizada.
     * @param array<string, mixed> $query   Parámetros de query string.
     * @param array<string, string> $headers Cabeceras HTTP.
     * @param string               $rawBody Cuerpo de la petición sin procesar.
     */
    public function __construct(string $method, string $path, array $query, array $headers, string $rawBody)
    {
        $this->method  = strtoupper($method);
        $this->path    = $path;
        $this->query   = $query;
        $this->headers = $headers;
        $this->rawBody = $rawBody;
    }

    /**
     * Construye la petición a partir de las superglobales de PHP.
     *
     * @return self Instancia poblada con los datos de la petición actual.
     */
    public static function capture(): self
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path   = self::normalizePath($uri);
        $query  = $_GET;

        // Algunos clientes solo envían el cuerpo en peticiones con cuerpo.
        $rawBody = in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? (string) file_get_contents('php://input')
            : '';

        return new self($method, $path, $query, self::captureHeaders(), $rawBody);
    }

    /**
     * Devuelve el verbo HTTP de la petición.
     *
     * @return string Verbo en mayúsculas (por ejemplo `GET`).
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Devuelve la ruta normalizada de la petición.
     *
     * @return string Ruta sin query string ni prefijos de instalación.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Devuelve un parámetro de la query string.
     *
     * @param string $key     Nombre del parámetro.
     * @param mixed  $default Valor por defecto si no existe.
     *
     * @return mixed Valor del parámetro o `$default`.
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Devuelve todos los parámetros de la query string.
     *
     * @return array<string, mixed> Parámetros de consulta.
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * Devuelve una cabecera de la petición.
     *
     * @param string $name    Nombre de la cabecera (por ejemplo `Authorization`).
     * @param mixed  $default Valor por defecto si no existe.
     *
     * @return mixed Valor de la cabecera o `$default`.
     */
    public function header(string $name, $default = null)
    {
        return $this->headers[strtoupper(str_replace('-', '_', $name))] ?? $default;
    }

    /**
     * Devuelve el cuerpo de la petición sin decodificar.
     *
     * @return string Cuerpo bruto.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Decodifica el cuerpo de la petición como un objeto JSON asociativo.
     *
     * @return array<string, mixed> Cuerpo decodificado.
     *
     * @throws \JsonException Si el cuerpo está vacío, mal formado o no es un objeto.
     */
    public function json(): array
    {
        if (trim($this->rawBody) === '') {
            throw new JsonException('El cuerpo de la petición está vacío; se esperaba un objeto JSON.');
        }

        try {
            $decoded = json_decode($this->rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // `json_last_error_msg()` devuelve textos en inglés y, además,
            // `JSON_THROW_ON_ERROR` reinicia `json_last_error()` a NONE: el
            // código fiable es el de la propia excepción.
            throw new JsonException(
                sprintf(
                    'el cuerpo no es un JSON válido (%s).',
                    self::describeJsonError($exception->getCode())
                ),
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new JsonException('El cuerpo debe ser un objeto JSON, no un valor simple.');
        }

        // Un array con claves numéricas indica que llegó una lista, no un objeto.
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            throw new JsonException('El cuerpo debe ser un objeto JSON, no una lista.');
        }

        return $decoded;
    }

    /**
     * Traduce un código de error de JSON a un mensaje en español.
     *
     * @param int $code Código devuelto por `JsonException::getCode()`.
     *
     * @return string Descripción del error de sintaxis.
     */
    private static function describeJsonError(int $code): string
    {
        switch ($code) {
            case JSON_ERROR_NONE:
                return 'sin error';

            case JSON_ERROR_DEPTH:
                return 'la estructura es demasiado profunda';

            case JSON_ERROR_STATE_MISMATCH:
                return 'valor no válido para el tipo esperado';

            case JSON_ERROR_CTRL_CHAR:
                return 'contiene caracteres de control sin escapar';

            case JSON_ERROR_SYNTAX:
                return 'error de sintaxis';

            case JSON_ERROR_UTF8:
                return 'contiene caracteres UTF-8 inválidos';

            default:
                return 'el documento no está bien formado';
        }
    }

    /**
     * Indica si la cabecera `Content-Type` declara JSON.
     *
     * @return bool `true` si el tipo de contenido es JSON o no fue enviado.
     */
    public function isJsonContentType(): bool
    {
        $contentType = (string) $this->header('Content-Type', '');

        if ($contentType === '') {
            // Se acepta la ausencia de cabecera para no romper clientes simples.
            return true;
        }

        $mimeType = trim((string) preg_replace('/;.*$/', '', $contentType));

        return in_array(strtolower($mimeType), ['application/json', 'text/json'], true);
    }

    /**
     * Devuelve un parámetro de ruta capturado por el router.
     *
     * @param string $name    Nombre del parámetro (por ejemplo `uuid`).
     * @param mixed  $default Valor por defecto si no fue capturado.
     *
     * @return mixed Valor del parámetro o `$default`.
     */
    public function param(string $name, $default = null)
    {
        return $this->routeParams[$name] ?? $default;
    }

    /**
     * Almacena los parámetros de ruta capturados por el router.
     *
     * @param array<string, string> $params Parámetros capturados.
     *
     * @return void
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Devuelve todos los parámetros de ruta capturados por el router.
     *
     * @return array<string, string> Mapa de nombre a valor.
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Normaliza una URL cruda a una ruta utilizable por el router.
     *
     * Pasos aplicados:
     * 1. Elimina el query string y el fragmento.
     * 2. Decodifica los porcentajes.
     * 3. Elimina el prefijo del proyecto dentro del DocumentRoot.
     * 4. Elimina el segmento `/public` si aparece.
     * 5. Colapsa barras duplicadas y recorta la barra final.
     *
     * @param string $uri URL completa tal como llega en `REQUEST_URI`.
     *
     * @return string Ruta normalizada que siempre comienza por `/`.
     */
    public static function normalizePath(string $uri): string
    {
        $path = (string) preg_replace('/[?#].*$/', '', $uri);
        $path = rawurldecode($path);
        $path = self::stripBasePath($path);

        // El front controller puede vivir en `public/`; ese segmento no forma
        // parte de la API y debe desaparecer de la ruta.
        if ($path === '/public') {
            $path = '/';
        } elseif (strpos($path, '/public/') === 0) {
            $path = substr($path, strlen('/public'));
        }

        $path = (string) preg_replace('#/+#', '/', $path);

        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return ($path === '') ? '/' : $path;
    }

    /**
     * Elimina de la ruta el prefijo que corresponde a la ubicación del proyecto
     * dentro del `DocumentRoot`.
     *
     * Se prueban varios candidatos porque Apache informa la ruta del script
     * ejecutado de forma distinta según cuál `.htaccess` resolvió la petición:
     *
     * - `/fel-dentall`         : ruta del proyecto dentro del `DocumentRoot`
     *                             (`www`), cuando el `.htaccess` de la raíz
     *                             reenvía a `public/index.php`.
     * - `/fel-dentall/public`  : directorio del script (`SCRIPT_NAME`), cuando
     *                             el `DocumentRoot` es el proyecto completo.
     *
     * Se aplica el primer candidato que coincida con el inicio de la ruta; si
     * ninguno coincide (por ejemplo, `DocumentRoot` en `public/`), la ruta se
     * devuelve sin modificar.
     *
     * @param string $path Ruta ya recortada de query string y decodificada.
     *
     * @return string Ruta sin el prefijo de instalación.
     */
    private static function stripBasePath(string $path): string
    {
        foreach (self::basePathCandidates() as $candidate) {
            if ($path === $candidate) {
                return '/';
            }

            if (strpos($path, $candidate . '/') === 0) {
                return substr($path, strlen($candidate));
            }
        }

        return $path;
    }

    /**
     * Construye la lista de prefijos candidatos de la instalación.
     *
     * @return array<int, string> Prefijos normalizados, sin duplicados ni vacíos.
     */
    private static function basePathCandidates(): array
    {
        $candidates = [];

        // Candidato 1: ruta del proyecto (carpeta que contiene `app/`) relativa
        // al DocumentRoot. Solo es válido si el proyecto vive dentro de él.
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $projectRoot  = str_replace('\\', '/', dirname(__DIR__, 2));

        if ($documentRoot !== '' && $projectRoot !== '') {
            $normalizedDocumentRoot = rtrim(str_replace('\\', '/', realpath($documentRoot) ?: $documentRoot), '/');
            $normalizedProjectRoot  = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');

            if ($normalizedProjectRoot !== $normalizedDocumentRoot
                && strpos($normalizedProjectRoot, $normalizedDocumentRoot . '/') === 0
            ) {
                $candidates[] = substr($normalizedProjectRoot, strlen($normalizedDocumentRoot));
            }
        }

        // Candidato 2: directorio del script ejecutado.
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';

        if ($scriptName !== '') {
            $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

            if ($scriptDir !== '' && $scriptDir !== '/') {
                $candidates[] = $scriptDir;
            }
        }

        // Se eliminan duplicados conservando el orden de prioridad.
        return array_values(array_unique(array_filter($candidates, static function (string $value): bool {
            return $value !== '' && $value[0] === '/';
        })));
    }

    /**
     * Extrae y normaliza todas las cabeceras de la petición.
     *
     * @return array<string, string> Cabeceras indexadas en mayúsculas.
     */
    private static function captureHeaders(): array
    {
        /** @var array<string, string> $headers */
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (strpos($key, 'HTTP_') === 0) {
                $headers[substr($key, 5)] = $value;
            }
        }

        // CONTENT_TYPE y CONTENT_LENGTH no llevan el prefijo HTTP_.
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT_TYPE'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['CONTENT_LENGTH'] = $_SERVER['CONTENT_LENGTH'];
        }

        // Algunos servidores reescriben la cabecera Authorization como
        // REDIRECT_HTTP_AUTHORIZATION al pasar por mod_rewrite.
        if (!isset($headers['AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $redirectAuth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            if (is_string($redirectAuth)) {
                $headers['AUTHORIZATION'] = $redirectAuth;
            }
        }

        foreach ($headers as $key => $value) {
            $headers[strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $headers;
    }
}