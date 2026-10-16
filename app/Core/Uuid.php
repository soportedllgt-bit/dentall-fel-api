<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Generación y validación de identificadores UUID versión 4 (RFC 4122).
 *
 * Todo el proceso se realiza en el lado del servidor mediante
 * `random_bytes()`, que es criptográficamente seguro y no depende de ninguna
 * librería externa.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Uuid
{
    /**
     * Expresión regular que valida un UUID v4 con formato canónico.
     *
     * Desglose:
     * - `[0-9a-f]{8}-[0-9a-f]{4}`   : `time_low` y `time_mid`.
     * - `4[0-9a-f]{3}`               : la versión debe ser exactamente 4.
     * - `[89ab][0-9a-f]{3}`          : `variant` debe ser 10xx (RFC 4122).
     * - `[0-9a-f]{12}`               : `clock_seq` y `node`.
     *
     * @var string
     */
    private const PATTERN_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * La clase es utilitaria: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Genera un identificador UUID v4 criptográficamente seguro.
     *
     * Se parte de 16 bytes aleatorios y se fijan los bits de versión (4) y de
     * variante (RFC 4122) en los octetos correspondientes.
     *
     * @return string UUID v4 en formato canónico de 36 caracteres.
     *
     * @throws \RuntimeException Si el generador de entropía no está disponible.
     */
    public static function v4(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (\Exception $exception) {
            throw new \RuntimeException(
                'No se pudo generar un UUID v4: el generador de números aleatorios no está disponible.',
                0,
                $exception
            );
        }

        // Fija la versión (4) en los 4 bits altos del octeto 6.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);

        // Fija la variante (10xx) en los 2 bits altos del octeto 8.
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Valida que una cadena tenga el formato canónico de un UUID v4.
     *
     * El UUID `00000000-0000-0000-0000-000000000000` es inválido porque su nibble
     * de versión no es `4`, por lo que queda descartado automáticamente.
     *
     * @param string $uuid Cadena a validar.
     *
     * @return bool `true` si es un UUID v4 válido.
     */
    public static function isValidV4(string $uuid): bool
    {
        if (strlen($uuid) !== 36) {
            return false;
        }

        return (bool) preg_match(self::PATTERN_V4, $uuid);
    }

    /**
     * Normaliza un UUID válido a minúsculas para su uso en consultas SQL.
     *
     * MySQL con collation `utf8mb4_unicode_ci` ya compara sin distinguir
     * mayúsculas, pero normalizar de forma explícita mantiene el comportamiento
     * idéntico en cualquier motor de base de datos.
     *
     * @param string $uuid UUID a normalizar.
     *
     * @return string UUID en minúsculas.
     *
     * @throws \InvalidArgumentException Si el UUID no tiene formato v4 válido.
     */
    public static function normalize(string $uuid): string
    {
        if (!self::isValidV4($uuid)) {
            throw new InvalidArgumentException('El UUID proporcionado no tiene un formato UUID v4 válido.');
        }

        return strtolower($uuid);
    }
}