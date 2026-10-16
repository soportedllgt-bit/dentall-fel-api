<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lector de variables de entorno con valores por defecto seguros.
 *
 * Aísla por completo el uso de `getenv()` y `$_ENV`/`$_SERVER` para que el
 * resto de la aplicación dependa siempre de esta clase. La precedencia es:
 *
 * 1. Variable de entorno real del sistema (`getenv`).
 * 2. Entrada en `$_ENV`.
 * 3. Entrada en `$_SERVER`.
 * 4. Valor por defecto proporcionado por el llamador.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Env
{
    /**
     * Clave de la variable de entorno que define el entorno de ejecución.
     *
     * @var string
     */
    public const KEY_APP_ENV = 'APP_ENV';

    /**
     * Nombre del entorno de trabajo del programador.
     *
     * @var string
     */
    public const ENV_LOCAL = 'local';

    /**
     * Entorno utilizado cuando `APP_ENV` no está definido.
     *
     * @var string
     */
    public const DEFAULT_ENV = self::ENV_LOCAL;

    /**
     * Entorno de desarrollo desplegado en un servidor real.
     *
     * Comparte los endpoints de pruebas con `local`, pero no expone los errores
     * PHP en la respuesta ni deja credenciales de arranque por defecto. Es el
     * entorno indicado para un servidor de desarrollo con dominio propio, que
     * es accesible desde Internet y no desde la red interna del programador.
     *
     * @var string
     */
    public const ENV_DEVELOPMENT = 'desarrollo';

    /**
     * Entorno de producción: documentos reales contra el servicio real.
     *
     * @var string
     */
    public const ENV_PRODUCTION = 'produccion';

    /**
     * La clase es puramente estática: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Obtiene el valor crudo de una variable de entorno.
     *
     * @param string $key     Nombre de la variable (por ejemplo `DB_HOST`).
     * @param mixed  $default Valor devuelto si la variable no existe.
     *
     * @return mixed Valor de la variable o `$default` si no está definida.
     */
    public static function get(string $key, $default = null)
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }

        return $default;
    }

    /**
     * Indica si una variable de entorno está definida y no vacía.
     *
     * @param string $key Nombre de la variable.
     *
     * @return bool `true` si existe con contenido, `false` en caso contrario.
     */
    public static function has(string $key): bool
    {
        $value = getenv($key);

        return is_string($value) && $value !== '';
    }

    /**
     * Obtiene una variable de entorno como cadena de texto.
     *
     * @param string $key     Nombre de la variable.
     * @param string $default Valor por defecto si no está definida.
     *
     * @return string Valor de la variable o `$default`.
     */
    public static function string(string $key, string $default): string
    {
        $value = self::get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Obtiene una variable de entorno como entero.
     *
     * @param string $key     Nombre de la variable.
     * @param int    $default Valor por defecto si no está definida o no es numérica.
     *
     * @return int Valor de la variable o `$default`.
     */
    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Obtiene una variable de entorno como booleano.
     *
     * Acepta `1`, `true`, `on`, `yes` (verdadero) y `0`, `false`, `off`, `no`
     * (falso), sin distinguir mayúsculas.
     *
     * @param string $key     Nombre de la variable.
     * @param bool   $default Valor por defecto si no está definida.
     *
     * @return bool Interpretación booleana de la variable.
     */
    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Devuelve el nombre del entorno de ejecución activo.
     *
     * Solo se reconocen tres nombres: `produccion`, `desarrollo` y `local`.
     * Cualquier otro valor se degrada a `local`, que es el entorno más
     * restrictivo en cuanto a credenciales por defecto, de modo que un
     * `APP_ENV` mal escrito nunca deja la API apuntando al servicio real de
     * facturación por accidente.
     *
     * @return string `local`, `desarrollo` o `produccion`.
     */
    public static function appEnv(): string
    {
        $value = strtolower(trim(self::string(self::KEY_APP_ENV, self::DEFAULT_ENV)));

        if ($value === self::ENV_PRODUCTION) {
            return self::ENV_PRODUCTION;
        }

        if ($value === self::ENV_DEVELOPMENT) {
            return self::ENV_DEVELOPMENT;
        }

        return self::DEFAULT_ENV;
    }

    /**
     * Convierte una lista de orígenes separada por comas en un array normalizado.
     *
     * @param string $rawOrigenes Lista de orígenes separados por comas.
     *
     * @return array<int, string> Orígenes normalizados, sin duplicados y sin vacíos.
     */
    public static function parseOrigins(string $rawOrigenes): array
    {
        $parts = array_map('trim', explode(',', $rawOrigenes));
        $parts = array_filter($parts, static function (string $origin): bool {
            return $origin !== '' && $origin !== '*';
        });

        return array_values(array_unique($parts));
    }
}