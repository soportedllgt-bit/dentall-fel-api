<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

/**
 * El proveedor rechazó la operación con un error de negocio.
 *
 * No es un fallo técnico: el proveedor recibió la petición, la entendió y la
 * rechazó por los datos. Se distingue de la comunicación porque **no tiene
 * sentido reintentar**: con los mismos datos volverá a rechazar.
 *
 * La respuesta llega con HTTP 200 y el error viaja dentro de `<result>`, por lo
 * que nunca se detecta por el código de estado.
 *
 * Hay dos formatos observados contra el servicio real:
 *
 * - `ERROR el TAG1 ImpIva no existe o viene mal definido en el formato XML`
 * - `82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO`
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ProviderBusinessException extends GuatefacturasException
{
    /**
     * Código numérico del proveedor, si lo incluye.
     *
     * @var int|null
     */
    private $providerCode;

    /**
     * Límite de caracteres del mensaje de error.
     *
     * El proveedor puede devolver el XML DTE firmado completo dentro de `<result>`,
     * que ocupa decenas de kilobytes. Volcarlo entero en el mensaje inundaría
     * el log y devolvería una respuesta de error inmanejable. El texto íntegro
     * sigue disponible en {@see ProviderBusinessException::rawResult()}.
     *
     * @var int
     */
    private const MAX_MESSAGE_LENGTH = 400;

    /**
     * Texto íntegro devuelto por el proveedor.
     *
     * @var string
     */
    private $rawResult;

    /**
     * @param string    $rawResult Texto devuelto por el proveedor.
     * @param int|null  $providerCode Código numérico extraído, si existe.
     */
    public function __construct(string $rawResult, ?int $providerCode = null)
    {
        $this->rawResult    = $rawResult;
        $this->providerCode = $providerCode;

        parent::__construct(sprintf(
            'GUATEFACTURAS rechazó la operación%s: %s',
            $providerCode === null ? '' : sprintf(' (código %d)', $providerCode),
            self::truncate($rawResult)
        ));
    }

    /**
     * Recorta un texto largo para poder incluirlo en un mensaje.
     *
     * @param string $text Texto original.
     *
     * @return string Texto recortado con un indicador de omisión.
     */
    private static function truncate(string $text): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if (strlen($clean) <= self::MAX_MESSAGE_LENGTH) {
            return $clean;
        }

        return substr($clean, 0, self::MAX_MESSAGE_LENGTH)
            . sprintf('… [+%d caracteres]', strlen($clean) - self::MAX_MESSAGE_LENGTH);
    }

    /**
     * @param string $rawResult Texto devuelto por el proveedor.
     *
     * @return self Excepción con el código detectado.
     */
    public static function fromResult(string $rawResult): self
    {
        return new self($rawResult, self::extractCode($rawResult));
    }

    /**
     * Extrae el código numérico del mensaje del proveedor.
     *
     * Los códigos de error del manual (p.121) aparecen como `NN-` al inicio.
     *
     * @param string $resultado Texto devuelto por el proveedor.
     *
     * @return int|null Código detectado o `null` si el mensaje no lo incluye.
     */
    public static function extractCode(string $resultado): ?int
    {
        if (preg_match('/^\s*(\d{1,3})-/', $resultado, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Devuelve el código numérico del error.
     *
     * @return int|null Código o `null` si el mensaje no lo incluye.
     */
    public function providerCode(): ?int
    {
        return $this->providerCode;
    }

    /**
     * Devuelve el texto íntegro devuelto por el proveedor.
     *
     * @return string Respuesta original sin procesar.
     */
    public function rawResult(): string
    {
        return $this->rawResult;
    }
}