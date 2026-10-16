<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Manejador global de errores, excepciones y apagados.
 *
 * Registra tres mecanismos de PHP:
 * - `set_exception_handler()`  : captura `Throwable` no controlados.
 * - `set_error_handler()`      : convierte avisos y avisos fatales en excepciones.
 * - `register_shutdown_function()` : recoge el error fatal que impide a PHP
 *   ejecutar el manejador de excepciones.
 *
 * En el entorno `local` con `display_errors` activo, la respuesta incluye el
 * detalle técnico completo para acelerar el desarrollo. En `produccion` se
 * devuelve exclusivamente un mensaje genérico con código `500`, sin stack
 * trace, rutas, credenciales ni mensajes del motor.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ExceptionHandler
{
    /**
     * Configuración de la aplicación.
     *
     * @var AppConfig
     */
    private $config;

    /**
     * Constructor de la base de datos, usado para clasificar excepciones de PDO.
     *
     * @var Database|null
     */
    private $database;

    /**
     * @param AppConfig        $config   Configuración de la aplicación.
     * @param Database|null    $database Base de datos, si ya está instanciada.
     */
    public function __construct(AppConfig $config, ?Database $database = null)
    {
        $this->config   = $config;
        $this->database = $database;
    }

    /**
     * Registra los tres manejadores globales de PHP.
     *
     * @return void
     */
    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Maneja una excepción no controlada.
     *
     * @param Throwable $exception Excepción capturada.
     *
     * @return void
     */
    public function handleException(Throwable $exception): void
    {
        $this->respond($exception);
    }

    /**
     * Convierte un error de PHP en una `ErrorException` y la propaga.
     *
     * Se respetan las supresiones con `@` y el nivel de error actual, tal como
     * hace el comportamiento nativo de PHP.
     *
     * @param int    $severity  Nivel del error (constantes `E_*`).
     * @param string $message   Mensaje del error.
     * @param string $file      Archivo donde ocurrió.
     * @param int    $line      Línea donde ocurrió.
     *
     * @return bool `false` para delegar en el manejador interno de PHP.
     *
     * @throws \ErrorException Siempre, salvo que el error esté suprimido.
     */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Si el error fue suprimido con `@` o no corresponde a error_reporting,
        // se devuelve false para que PHP aplique su comportamiento por defecto.
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Captura los errores fatales que impiden ejecutar el manejador de excepciones.
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        // Si ya se envió una respuesta, no se emite una segunda.
        if (Response::isSent()) {
            return;
        }

        $exception = new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );

        $this->respond($exception);
    }

    /**
     * Traduce una excepción a una respuesta HTTP y termina la ejecución.
     *
     * @param Throwable $exception Excepción a publicar.
     *
     * @return void
     */
    private function respond(Throwable $exception): void
    {
        if (Response::isSent()) {
            return;
        }

        // Los errores de validación y de formato son culpa del cliente: 400.
        if ($exception instanceof \InvalidArgumentException) {
            Response::error($exception->getMessage(), 400);
            $this->writeLog($exception);

            return;
        }

        // JSON mal formado en el cuerpo de la petición: 400.
        if ($exception instanceof \JsonException) {
            Response::error('El cuerpo de la petición no es JSON válido.', 400);
            $this->writeLog($exception);

            return;
        }

        // Violación de integridad en base de datos: 409.
        if ($exception instanceof \PDOException && $this->database !== null) {
            $description = $this->database->describeException($exception);
            Response::error($description['message'], $description['http_code']);
            $this->writeLog($exception);

            return;
        }

        // Cualquier otro fallo es interno: nunca se filtra su contenido.
        $payload = $this->buildErrorPayload($exception);

        $this->sendPayload($payload, 500);
        $this->writeLog($exception);
    }

    /**
     * Construye el cuerpo de la respuesta de error según el entorno.
     *
     * @param Throwable $exception Excepción a publicar.
     *
     * @return array<string, mixed> Cuerpo de la respuesta.
     */
    private function buildErrorPayload(Throwable $exception): array
    {
        $base = [
            'success' => false,
            'message' => 'Se produjo un error interno. Inténtelo de nuevo más tarde.',
            'data'    => null,
            'errors'  => null,
        ];

        if (!$this->config->shouldDisplayErrors()) {
            return $base;
        }

        // Solo en desarrollo se expone el detalle técnico.
        return array_merge($base, [
            'message' => $exception->getMessage(),
            'errors'  => [
                'type'      => get_class($exception),
                'code'      => $exception->getCode(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => $exception->getTraceAsString(),
                'previous'  => $this->describePrevious($exception),
            ],
        ]);
    }

    /**
     * Describe la excepción anterior de una cadena, si existe.
     *
     * @param Throwable $exception Excepción actual.
     *
     * @return array<string, mixed>|null Detalle de la excepción anterior.
     */
    private function describePrevious(Throwable $exception): ?array
    {
        $previous = $exception->getPrevious();

        if ($previous === null) {
            return null;
        }

        return [
            'type'  => get_class($previous),
            'code'  => $previous->getCode(),
            'file'  => $previous->getFile(),
            'line'  => $previous->getLine(),
            'trace' => $previous->getTraceAsString(),
        ];
    }

    /**
     * Envía un cuerpo de respuesta con el código indicado, reutilizando
     * {@see Response} cuando corresponde y evitando una segunda cabecera.
     *
     * @param array<string, mixed> $payload Cuerpo de la respuesta.
     * @param int                  $code   Código de estado HTTP.
     *
     * @return void
     */
    private function sendPayload(array $payload, int $code): void
    {
        Response::json($payload, $code);
    }

    /**
     * Registra la excepción en el log de PHP.
     *
     * El registro ocurre siempre, incluso en producción, para que el operador
     * pueda diagnosticar el fallo sin que el cliente reciba información interna.
     *
     * @param Throwable $exception Excepción a registrar.
     *
     * @return void
     */
    private function writeLog(Throwable $exception): void
    {
        $line = sprintf(
            "[%s] %s: %s en %s:%d\n%s\n\n",
            gmdate('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        error_log($line);
    }
}