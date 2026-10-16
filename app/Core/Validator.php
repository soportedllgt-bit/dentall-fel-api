<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validador estricto y acumulativo de los datos de entrada.
 *
 * Devuelve TODOS los errores detectados en una sola pasada, en lugar de
 * abortar en el primer fallo, para que el cliente reciba una respuesta útil
 * y no tenga que corregir los campos de uno en uno.
 *
 * Formato de las reglas:
 * ```php
 * [
 *     'title' => ['required' => true, 'type' => 'string', 'max' => 255],
 *     'description' => ['required' => false, 'type' => 'string', 'max' => 65535, 'nullable' => true],
 * ]
 * ```
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Validator
{
    /**
     * La clase es utilitaria: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Valida un conjunto de datos contra un esquema de reglas.
     *
     * @param array<string, mixed> $input   Datos recibidos de la petición.
     * @param array<string, array<string, mixed>> $rules Reglas por campo.
     * @param bool                 $partial Si es `true`, los campos ausentes se
     *                                      ignoran (semántica de actualización
     *                                      parcial). Si es `false`, un campo
     *                                      marcado como obligatorio y ausente
     *                                      genera un error.
     *
     * @return array<string, array<int, string>> Mapa de campo a lista de errores.
     *                                           Array vacío si todo es correcto.
     */
    public static function validate(array $input, array $rules, bool $partial = false): array
    {
        /** @var array<string, array<int, string>> $errors */
        $errors = [];

        foreach ($rules as $field => $rule) {
            $rule = self::normalizeRule($rule);
            $present = array_key_exists($field, $input);

            if (!$present) {
                if ($rule['required'] && !$partial) {
                    $errors[$field][] = sprintf('El campo "%s" es obligatorio.', $field);
                }
                continue;
            }

            $value = $input[$field];

            if ($value === null) {
                if ($rule['nullable']) {
                    continue;
                }
                if ($rule['required']) {
                    $errors[$field][] = sprintf('El campo "%s" no puede ser nulo.', $field);
                }
                continue;
            }

            // Comprobación de tipo estricto antes de operar sobre el valor.
            if (!$rule['nullable'] && $value === '') {
                // Cadena vacía: solo es aceptable si el campo es opcional.
                if ($rule['required']) {
                    $errors[$field][] = sprintf('El campo "%s" no puede estar vacío.', $field);
                }
                continue;
            }

            $typeError = self::checkType($value, (string) $rule['type'], (string) $field);
            if ($typeError !== null) {
                $errors[$field][] = $typeError;
                continue;
            }

            if ($rule['max'] !== null) {
                $length = self::length($value);
                if ($length > (int) $rule['max']) {
                    $errors[$field][] = sprintf(
                        'El campo "%s" no puede superar los %d caracteres (recibidos: %d).',
                        $field,
                        (int) $rule['max'],
                        $length
                    );
                }
            }

            if ($rule['min'] !== null) {
                $length = self::length($value);
                if ($length < (int) $rule['min']) {
                    $errors[$field][] = sprintf(
                        'El campo "%s" debe tener al menos %d caracteres.',
                        $field,
                        (int) $rule['min']
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Valida que un valor sea un UUID v4.
     *
     * @param mixed  $value Valor a validar.
     * @param string $field Nombre del campo, usado en el mensaje de error.
     *
     * @return array<int, string> Lista de errores; vacía si el UUID es válido.
     */
    public static function uuid($value, string $field = 'uuid'): array
    {
        if (!is_string($value)) {
            return [sprintf('El campo "%s" debe ser una cadena de texto.', $field)];
        }

        if (!Uuid::isValidV4($value)) {
            return [sprintf('El campo "%s" no contiene un UUID v4 válido.', $field)];
        }

        return [];
    }

    /**
     * Detecta campos presentes en la entrada que no están declarados en las reglas.
     *
     * Esto evita que un cliente envíe columnas no contempladas (por ejemplo
     * `created_at` o `uuid`) esperando que se persistan.
     *
     * @param array<string, mixed>           $input Datos recibidos de la petición.
     * @param array<string, array<string, mixed>> $rules Reglas declaradas.
     *
     * @return array<int, string> Lista de nombres de campos no permitidos.
     */
    public static function rejectUnknownFields(array $input, array $rules): array
    {
        $allowed = array_keys($rules);
        $unexpected = array_diff(array_keys($input), $allowed);

        return array_values(array_map('strval', $unexpected));
    }

    /**
     * Completa las claves ausentes de una regla con valores por defecto seguros.
     *
     * @param array<string, mixed> $rule Regla original.
     *
     * @return array{required: bool, type: string, max: int|null, min: int|null, nullable: bool}
     *         Regla normalizada.
     */
    private static function normalizeRule(array $rule): array
    {
        return [
            'required' => (bool) ($rule['required'] ?? false),
            'type'     => (string) ($rule['type'] ?? 'string'),
            'max'      => isset($rule['max']) ? (int) $rule['max'] : null,
            'min'      => isset($rule['min']) ? (int) $rule['min'] : null,
            'nullable' => (bool) ($rule['nullable'] ?? false),
        ];
    }

    /**
     * Comprueba que el valor coincida con el tipo declarado.
     *
     * @param mixed  $value Valor a comprobar.
     * @param string $type  Tipo esperado (`string`, `int` o `uuid`).
     * @param string $field Nombre del campo.
     *
     * @return string|null Mensaje de error o `null` si el tipo es correcto.
     */
    private static function checkType($value, string $type, string $field): ?string
    {
        switch ($type) {
            case 'string':
                if (!is_string($value)) {
                    return sprintf('El campo "%s" debe ser una cadena de texto.', $field);
                }
                return null;

            case 'int':
                if (!is_int($value)) {
                    return sprintf('El campo "%s" debe ser un número entero.', $field);
                }
                return null;

            case 'uuid':
                if (!is_string($value)) {
                    return sprintf('El campo "%s" debe ser una cadena de texto.', $field);
                }
                if (!Uuid::isValidV4($value)) {
                    return sprintf('El campo "%s" no contiene un UUID v4 válido.', $field);
                }
                return null;

            default:
                return sprintf('Tipo de dato no soportado para el campo "%s".', $field);
        }
    }

    /**
     * Calcula la longitud de un valor respetando caracteres multibyte.
     *
     * @param mixed $value Valor ya validado como `string` o `int`.
     *
     * @return int Número de caracteres.
     */
    private static function length($value): int
    {
        return mb_strlen((string) $value, 'UTF-8');
    }
}