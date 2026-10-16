<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Emisor centralizado de respuestas JSON.
 *
 * Todas las respuestas de la API comparten la misma envolvente (envelope):
 *
 * ```json
 * { "success": true,  "message": "...", "data": { ... }, "errors": null }
 * { "success": false, "message": "...", "data": null, "errors": { ... } }
 * ```
 *
 * La clase mantiene una bandera estática de "ya se envió una respuesta" para
 * impedir una doble salida cuando el manejador de apagado (shutdown) interviene
 * después de que el controlador ya haya respondido.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Response
{
    /**
     * Indica si ya se ha enviado una respuesta durante esta petición.
     *
     * @var bool
     */
    private static $sent = false;

    /**
     * Orígenes permitidos por CORS, cargados desde la configuración.
     *
     * @var array<int, string>
     */
    private static $allowedOrigins = [];

    /**
     * La clase es utilitaria: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Carga la lista blanca de orígenes permitidos.
     *
     * @param array<int, string> $origins Orígenes permitidos.
     *
     * @return void
     */
    public static function configureCors(array $origins): void
    {
        self::$allowedOrigins = array_values(array_filter($origins, 'is_string'));
    }

    /**
     * Indica si la respuesta ya fue enviada.
     *
     * @return bool `true` si no queda nada por emitir.
     */
    public static function isSent(): bool
    {
        return self::$sent;
    }

    /**
     * Restablece el estado interno. Reservado para pruebas.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$sent = false;
        self::$allowedOrigins = [];
    }

    /**
     * Envía una respuesta JSON con el código indicado.
     *
     * @param mixed $data Cuerpo de la respuesta ya serializable.
     * @param int   $code Código de estado HTTP.
     *
     * @return void
     */
    public static function json($data, int $code = 200): void
    {
        self::send($code, $data);
    }

    /**
     * Envía una respuesta de éxito.
     *
     * @param mixed  $data    Contenido útil de la respuesta.
     * @param string $message Mensaje legible en español.
     * @param int    $code    Código de estado HTTP.
     *
     * @return void
     */
    public static function success($data = null, string $message = 'Operación exitosa', int $code = 200): void
    {
        self::send($code, [
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ]);
    }

    /**
     * Envía una respuesta de error.
     *
     * ## El cuarto parámetro
     *
     * Cuando una operación se ha registrado pero no ha podido completarse, el
     * cliente necesita el identificador para poder consultarla o reintentarla más
     * tarde. Se emite en un bloque `contexto` en lugar de mezclar esos campos con
     * los de la envolvente, de modo que los demás endpoints mantienen exactamente
     * la misma forma de respuesta que antes.
     *
     * ```json
     * {
     *   "success": false,
     *   "message": "El proveedor no está disponible…",
     *   "data": null,
     *   "errors": null,
     *   "contexto": {
     *     "uuid": "…", "estado": "PENDIENTE_REINTENTO",
     *     "reintentable": true, "orden": { … }
     *   }
     * }
     * ```
     *
     * El `uuid` **no** se emite embebido en el mensaje: un mensaje es texto para
     * personas y cambia con las traducciones, no es un contrato programático.
     *
     * @param string                      $message Mensaje legible en español.
     * @param int                         $code    Código de estado HTTP.
     * @param array<string, array<int, string>>|null $errors Detalle de errores
     *        de validación por campo.
     * @param array<string, mixed>|null   $contexto Datos de la operación fallida.
     *
     * @return void
     */
    public static function error(
        string $message,
        int $code = 400,
        ?array $errors = null,
        ?array $contexto = null
    ): void {
        $payload = [
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
        ];

        if ($contexto !== null) {
            $payload['contexto'] = $contexto;
        }

        self::send($code, $payload);
    }

    /**
     * Emite una respuesta `429` con la cabecera `Retry-After`.
     *
     * ## Por qué una cabecera y no solo un campo en el cuerpo
     *
     * El estándar HTTP define `Retry-After` precisamente para esto, y cualquier
     * cliente HTTP o reintentador automático la respeta sin que haya que
     * programarlo. Duplicar el dato en el cuerpo no es redundancia: el POS lee el
     * campo para pintar un temporizador, y la cabecera evita que un reintento
     * automático golpee el proveedor antes de tiempo.
     *
     * @param int $seconds Segundos que deben transcurrir antes de reintentar.
     *
     * @return void
     */
    public static function retryAfter(int $seconds): void
    {
        if (!headers_sent()) {
            header('Retry-After: ' . max(0, $seconds));
        }
    }

    /**
     * Emite una respuesta `204 No Content` con las cabeceras CORS de preflight.
     *
     * @return void
     */
    public static function noContent(): void
    {
        self::emitHeaders(204, false);
        self::emitCorsHeaders('GET, POST, PUT, PATCH, DELETE, OPTIONS');
        self::$sent = true;

        http_response_code(204);
    }

    /**
     * Emite una respuesta `405 Method Not Allowed` con la cabecera `Allow`.
     *
     * @param string               $message Mensaje legible en español.
     * @param array<int, string>   $allowed Verbos admitidos por la ruta.
     *
     * @return void
     */
    public static function methodNotAllowed(string $message, array $allowed): void
    {
        $allowed = array_values(array_unique(array_merge($allowed, ['OPTIONS'])));

        self::emitHeaders(405, false);
        self::emitCorsHeaders('GET, ' . implode(', ', $allowed));
        self::emitAllowHeader($allowed);
        self::$sent = true;

        http_response_code(405);
        self::writeBody([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => null,
        ]);
    }

    /**
     * Compone y emite la respuesta final.
     *
     * @param int   $code    Código de estado HTTP.
     * @param array $payload Cuerpo ya con la envolvente aplicada.
     *
     * @return void
     */
    private static function send(int $code, array $payload): void
    {
        if (self::$sent) {
            return;
        }

        self::emitHeaders($code, false);
        self::emitCorsHeaders(null);
        self::$sent = true;

        http_response_code($code);
        self::writeBody($payload);
    }

    /**
     * Envía las cabeceras HTTP comunes a toda respuesta.
     *
     * @param int  $code    Código de estado HTTP.
     * @param bool $preflight Reservado para futuras variantes de preflight.
     *
     * @return void
     */
    private static function emitHeaders(int $code, bool $preflight): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    /**
     * Emite la cabecera `Allow` cuando la respuesta es un `405`.
     *
     * @param array<int, string> $allowed Verbos admitidos por la ruta.
     *
     * @return void
     */
    private static function emitAllowHeader(array $allowed): void
    {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $allowed));
        }
    }

    /**
     * Emite las cabeceras CORS respetando la lista blanca configurada.
     *
     * Nunca se devuelve un origen comodín junto con autenticación Basic: si el
     * origen no está en la lista blanca, la respuesta se emite sin cabeceras CORS,
     * de modo que el navegador bloquee la lectura desde el cliente atacante.
     *
     * @param string|null $allowOverride Valor explícito de `Access-Control-Allow-Methods`,
     *                                   usado únicamente en el preflight.
     *
     * @return void
     */
    private static function emitCorsHeaders(?string $allowOverride): void
    {
        if (headers_sent()) {
            return;
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';

        if ($origin === '' || !in_array($origin, self::$allowedOrigins, true)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');

        if ($allowOverride === null) {
            return;
        }

        header('Access-Control-Allow-Methods: ' . $allowOverride);
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Max-Age: 600');
    }

    /**
     * Serializa el cuerpo y termina la ejecución.
     *
     * @param array $payload Cuerpo de la respuesta.
     *
     * @return void
     */
    private static function writeBody(array $payload): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $encoded = json_encode($payload, $flags);

        if ($encoded === false) {
            // utf8mb4 con caracteres no representables: se degrada a la
            // representación escapada en lugar de emitir un cuerpo vacío.
            $encoded = json_encode($payload);
        }

        if ($encoded === false) {
            // Último recurso: nunca se devuelve una respuesta sin cuerpo.
            $encoded = '{"success":false,"message":"Error de serializacion","data":null,"errors":null}';
        }

        if (!headers_sent()) {
            header('Content-Length: ' . strlen($encoded));
        }

        echo $encoded;
    }
}