<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Normalización y saneamiento de cadenas de texto recibidas por la API.
 *
 * IMPORTANTE: el saneamiento no sustituye a los *prepared statements*. Su
 * propósito es únicamente normalizar la entrada para que las validaciones de
 * longitud y tipo sean fiables y para impedir que un cuerpo JSON con
 * codificación rota termine rompiendo `json_encode()` en la capa de respuesta.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Sanitizer
{
    /**
     * Caracteres de control que se eliminan del texto, salvo saltos de línea
     * y tabulaciones, que son legítimos en una descripción.
     *
     * @var string
     */
    private const CONTROL_CHARS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    /**
     * La clase es utilitaria: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Normaliza una cadena de texto de una sola línea (por ejemplo `title`).
     *
     * Pasos aplicados en orden:
     * 1. Verificación de codificación UTF-8 válida.
     * 2. Eliminación de caracteres de control no imprimibles.
     * 3. Sustitución de cualquier tipo de espacio en blanco por un espacio simple.
     * 4. Recorte de espacios exteriores.
     *
     * @param string $value Texto de entrada sin sanear.
     *
     * @return string Texto normalizado, posiblemente vacío.
     *
     * @throws \InvalidArgumentException Si la cadena no es UTF-8 válido.
     */
    public static function singleLine(string $value): string
    {
        $value = self::assertUtf8($value);

        // \s con el modificador u cubre espacios, tabulaciones y saltos de línea.
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Normaliza un texto multilínea (por ejemplo `description`).
     *
     * A diferencia de {@see Sanitizer::singleLine()}, conserva los saltos de
     * línea, pero normaliza los finales de línea CRLF/CR a LF y colapsa los
     * espacios horizontales repetidos.
     *
     * @param string $value Texto de entrada sin sanear.
     *
     * @return string Texto normalizado, posiblemente vacío.
     *
     * @throws \InvalidArgumentException Si la cadena no es UTF-8 válido.
     */
    public static function multiLine(string $value): string
    {
        $value = self::assertUtf8($value);

        // Elimina caracteres de control preservando tabulaciones y saltos de línea.
        $value = (string) preg_replace(self::CONTROL_CHARS, '', $value);

        // Normaliza CRLF y CR a LF.
        $value = (string) preg_replace('/\r\n?/', "\n", $value);

        // Colapsa espacios y tabulaciones horizontales repetidos.
        $value = (string) preg_replace('/[ \t]+/u', ' ', $value);

        // Colapsa tres o más saltos de línea consecutivos en dos (párrafo vacío).
        $value = (string) preg_replace('/\n{3,}/', "\n\n", $value);

        return trim($value);
    }

    /**
     * Normaliza el título de un recurso.
     *
     * @param mixed $value Valor recibido en la petición.
     *
     * @return string Título normalizado.
     *
     * @throws \InvalidArgumentException Si el valor no es una cadena o no es UTF-8 válido.
     */
    public static function title($value): string
    {
        return self::singleLine(self::toString($value, 'title'));
    }

    /**
     * Normaliza la descripción de un recurso.
     *
     * Una descripción que queda vacía tras la normalización se traduce a `null`,
     * porque la columna `description` de la tabla es nullable.
     *
     * @param mixed $value Valor recibido en la petición.
     *
     * @return string|null Descripción normalizada o `null` si está vacía.
     *
     * @throws \InvalidArgumentException Si el valor no es una cadena o no es UTF-8 válido.
     */
    public static function description($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $description = self::multiLine(self::toString($value, 'description'));

        return ($description === '') ? null : $description;
    }

    /**
     * Comprueba que una cadena sea UTF-8 válido.
     *
     * Sin esta comprobación, un cuerpo JSON con bytes inválidos provocaría que
     * `json_encode()` devolviera `false` en la capa de respuesta, dejando al
     * cliente sin cuerpo alguno y con un error 200.
     *
     * @param string $value Texto a comprobar.
     *
     * @return string El texto original, sin modificar.
     *
     * @throws \InvalidArgumentException Si la cadena no es UTF-8 válido.
     */
    private static function assertUtf8(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('El texto recibido no utiliza una codificación UTF-8 válida.');
        }

        return $value;
    }

    /**
     * Convierte un valor arbitrario a cadena, rechazando tipos no admitidos.
     *
     * @param mixed  $value Valor a convertir.
     * @param string $field Nombre del campo, usado en el mensaje de error.
     *
     * @return string Representación textual del valor.
     *
     * @throws \InvalidArgumentException Si el valor no es una cadena ni un número entero.
     */
    private static function toString($value, string $field): string
    {
        if (is_string($value)) {
            return $value;
        }

        // Se acepta un entero por comodidad, pero se rechazan booleanos, arrays,
        // objetos, nulos y flotantes para no perder información en silencio.
        if (is_int($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('El campo "%s" debe ser una cadena de texto.', $field));
    }
}