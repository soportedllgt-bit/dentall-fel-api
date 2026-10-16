<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use RuntimeException;
use Throwable;

/**
 * Excepción base de la integración con GUATEFACTURAS.
 *
 * REGLA DE SEGURIDAD: ninguna credencial puede aparecer en el mensaje, ni
 * siquiera en desarrollo. Un `SoapFault` del proveedor puede incluir parte de
 * la petición enviada, que contiene `pUsuario` y `pPassword`. Todas las
 * subclases pasan por {@see GuatefacturasException::scrub()}, que sustituye
 * cualquier secreto conocido por un marcador antes de construir el mensaje.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
class GuatefacturasException extends RuntimeException
{
    /**
     * Sustituto con el que se enmascaran los secretos.
     *
     * @var string
     */
    private const MASK = '[OCULTO]';

    /**
     * Secretos conocidos que deben desaparecer de cualquier mensaje.
     *
     * @var array<int, string>
     */
    private $secrets = [];

    /**
     * @param string         $message Mensaje del error.
     * @param Throwable|null $previous Excepción original, si la hubo.
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(self::scrub($message), 0, $previous);
    }

    /**
     * Registra los valores que deben enmascararse en los mensajes.
     *
     * @param array<int, string> $values Credenciales conocidas.
     *
     * @return void
     */
    public function setSecrets(array $values): void
    {
        // Se descartan los valores triviales: enmascarar "1" convertiría cada
        // aparición del dígito en "[OCULTO]" y volvería ilegible el mensaje.
        $this->secrets = array_values(array_filter(
            array_map('strval', $values),
            static function (string $value): bool {
                return strlen($value) >= 4;
            }
        ));
    }

    /**
     * Elimina de un texto cualquier credencial registrada.
     *
     * @param string $message Texto a limpiar.
     *
     * @return string Texto sin secretos.
     */
    public function scrub(string $message): string
    {
        foreach ($this->secrets as $secret) {
            $message = str_replace($secret, self::MASK, $message);
        }

        return $message;
    }

    /**
     * Construye el mensaje descifrado de una excepción de SoapFault.
     *
     * @param \Throwable $exception Excepción original.
     *
     * @return string Mensaje sin credenciales.
     */
    protected function describeThrowable(Throwable $exception): string
    {
        return self::scrub(sprintf(
            '%s: %s',
            get_class($exception),
            $exception->getMessage()
        ));
    }
}