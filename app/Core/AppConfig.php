<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Acceso centralizado y de solo lectura a la configuración de la aplicación.
 *
 * Recibe el array devuelto por `config/config.php` y lo combina con las
 * variables de entorno del sistema, que siempre tienen prioridad sobre los
 * valores escritos en el archivo de configuración.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class AppConfig
{
    /**
     * Nombre del entorno de desarrollo.
     *
     * @var string
     */
    public const ENV_LOCAL = 'local';

    /**
     * Nombre del entorno de desarrollo desplegado en un servidor real.
     *
     * @var string
     */
    public const ENV_DEVELOPMENT = 'desarrollo';

    /**
     * Nombre del entorno de producción.
     *
     * @var string
     */
    public const ENV_PRODUCTION = 'produccion';

    /**
     * Configuración en bruto, tal como fue cargada desde el archivo.
     *
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @param array<string, mixed> $config Array devuelto por `config/config.php`.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Devuelve el nombre del entorno activo.
     *
     * @return string `local`, `desarrollo` o `produccion`.
     */
    public function getEnv(): string
    {
        return Env::appEnv();
    }

    /**
     * Indica si la aplicación se ejecuta en el entorno de desarrollo.
     *
     * @return bool `true` si el entorno activo es `local`.
     */
    public function isLocal(): bool
    {
        return $this->getEnv() === self::ENV_LOCAL;
    }

    /**
     * Indica si la aplicación se ejecuta en el entorno de producción.
     *
     * @return bool `true` si el entorno activo es `produccion`.
     */
    public function isProduction(): bool
    {
        return $this->getEnv() === self::ENV_PRODUCTION;
    }

    /**
     * Indica si la aplicación se ejecuta en el entorno de desarrollo desplegado
     * en un servidor real.
     *
     * @return bool `true` si el entorno activo es `desarrollo`.
     */
    public function isDevelopment(): bool
    {
        return $this->getEnv() === self::ENV_DEVELOPMENT;
    }

    /**
     * Indica si el entorno activo está expuesto a Internet y por tanto no debe
     * revelar detalle técnico en las respuestas.
     *
     * @return bool `true` en `produccion` y en `desarrollo`.
     */
    public function isPublicFacing(): bool
    {
        return $this->isProduction() || $this->isDevelopment();
    }

    /**
     * Devuelve la configuración de conexión a la base de datos.
     *
     * Las variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`
     * sobreescriben los valores del archivo de configuración.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string, charset: string}
     *         Parámetros de conexión PDO.
     */
    public function getDbConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = isset($this->config['db']) && is_array($this->config['db'])
            ? $this->config['db']
            : [];

        $fallbackDatabase = (string) ($defaults['database'] ?? 'fel_dentall_api');
        $fallbackCharset = (string) ($defaults['charset'] ?? 'utf8mb4');
        $fallbackHost = (string) ($defaults['host'] ?? '127.0.0.1');
        $fallbackPort = isset($defaults['port']) ? (int) $defaults['port'] : 3306;
        $fallbackUser = (string) ($defaults['username'] ?? '');
        $fallbackPassword = (string) ($defaults['password'] ?? '');

        return [
            'host'     => Env::string('DB_HOST', $fallbackHost),
            'port'     => Env::int('DB_PORT', $fallbackPort),
            'database' => Env::string('DB_NAME', $fallbackDatabase),
            'username' => Env::string('DB_USER', $fallbackUser),
            'password' => Env::string('DB_PASS', $fallbackPassword),
            'charset'  => Env::string('DB_CHARSET', $fallbackCharset),
        ];
    }

    /**
     * Devuelve la configuración de autenticación HTTP Basic.
     *
     * Las variables `API_USER` y `API_PASS` sobreescriben los valores del
     * archivo de configuración.
     *
     * @return array{username: string, password: string, realm: string}
     *         Credenciales y realm publicado en la cabecera `WWW-Authenticate`.
     */
    public function getAuthConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = isset($this->config['auth']) && is_array($this->config['auth'])
            ? $this->config['auth']
            : [];

        return [
            'username' => Env::string('API_USER', (string) ($defaults['username'] ?? '')),
            'password' => Env::string('API_PASS', (string) ($defaults['password'] ?? '')),
            'realm'    => (string) ($defaults['realm'] ?? 'API REST Items'),
        ];
    }

    /**
     * Devuelve la configuración de CORS.
     *
     * La variable `CORS_ALLOWED_ORIGINS` acepta una lista de orígenes separados
     * por comas y sobreescribe la lista definida en el archivo de configuración.
     *
     * @return array{allowed_origins: array<int, string>}
     *         Lista blanca de orígenes permitidos.
     */
    public function getCorsConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = isset($this->config['cors']) && is_array($this->config['cors'])
            ? $this->config['cors']
            : [];

        /** @var array<int, string> $fallback */
        $fallback = isset($defaults['allowed_origins']) && is_array($defaults['allowed_origins'])
            ? array_values(array_filter($defaults['allowed_origins'], 'is_string'))
            : [];

        if (Env::has('CORS_ALLOWED_ORIGINS')) {
            return ['allowed_origins' => Env::parseOrigins(Env::string('CORS_ALLOWED_ORIGINS', ''))];
        }

        return ['allowed_origins' => $fallback];
    }

    /**
     * Devuelve los parámetros de paginación del recurso `items`.
     *
     * @return array{default_limit: int, max_limit: int}
     *         Límite por defecto y tope máximo de resultados por página.
     */
    public function getItemsConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = isset($this->config['items']) && is_array($this->config['items'])
            ? $this->config['items']
            : [];

        $maxLimit = isset($defaults['max_limit']) ? (int) $defaults['max_limit'] : 50;
        $defaultLimit = isset($defaults['default_limit']) ? (int) $defaults['default_limit'] : 25;

        // Garantiza que el límite por defecto nunca supere el tope máximo.
        $defaultLimit = max(1, min($defaultLimit, $maxLimit));

        return [
            'default_limit' => $defaultLimit,
            'max_limit'     => $maxLimit,
        ];
    }

    /**
     * Devuelve las reglas de negocio de la facturación electrónica.
     *
     * Los valores por defecto coinciden con los de `config/config.php`, pero se
     * vuelven a aplicar aquí para que la clase siga funcionando si se le pasa
     * un array de configuración incompleto, como ocurre en las pruebas.
     *
     * @return array{
     *     default_tax_percent: string, max_lines: int,
     *     order_code_max_length: int, customer_name_max: int,
     *     customer_address_max: int, line_description_max: int,
     *     document_timezone: string,
     *     processing_timeout: int, max_attempts: int
     * }
     *         Reglas de facturación resueltas.
     */
    public function getBillingConfig(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = isset($this->config['billing']) && is_array($this->config['billing'])
            ? $this->config['billing']
            : [];

        $defaultTax = isset($settings['default_tax_percent'])
            ? (string) $settings['default_tax_percent']
            : '12';

        // El IVA se conserva como cadena decimal en lugar de float, por el
        // mismo motivo que impide usar coma flotante en los importes. Aquí solo
        // se comprueba que tenga forma numérica: la aritmética es del servicio.
        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $defaultTax) !== 1) {
            $defaultTax = '12';
        }

        return [
            'default_tax_percent'  => $defaultTax,
            'max_lines'             => max(1, (int) ($settings['max_lines'] ?? 200)),
            'order_code_max_length' => max(8, (int) ($settings['order_code_max_length'] ?? 64)),
            'customer_name_max'     => max(1, (int) ($settings['customer_name_max'] ?? 100)),
            'customer_address_max'  => max(1, (int) ($settings['customer_address_max'] ?? 200)),
            'line_description_max'  => max(1, (int) ($settings['line_description_max'] ?? 255)),
            'document_timezone'     => self::resolveTimezone(
                (string) ($settings['document_timezone'] ?? 'America/Guatemala')
            ),
            // Estas dos reglas las consumen `RetryPolicy::fromRules()` y
            // `FacturacionService`. Se devuelven aquí porque los servicios leen
            // exactamente este array: si no se exponen, sus valores por defecto
            // internas prevailsen siempre y lo que se declare en la configuración
            // no tiene efecto ninguno.
            'processing_timeout'    => max(1, (int) ($settings['processing_timeout'] ?? 300)),
            'max_attempts'          => max(1, (int) ($settings['max_attempts'] ?? 5)),
        ];
    }

    /**
     * Verifica que un nombre de zona horaria sea válido.
     *
     * Una zona inválida haría que `DateTimeImmutable` lanzara una excepción en
     * el momento de emitir la factura, no al arrancar: es exactamente el tipo
     * de fallo que se descubre en producción. Aquí se descarta de inmediato.
     *
     * @param string $name Nombre de la zona, por ejemplo `America/Guatemala`.
     *
     * @return string La zona indicada, o `America/Guatemala` si no es válida.
     */
    private static function resolveTimezone(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return 'America/Guatemala';
        }

        return in_array($name, \DateTimeZone::listIdentifiers(), true) ? $name : 'America/Guatemala';
    }

    /**
     * Devuelve la configuración de GUATEFACTURAS.
     *
     * La precedencia de cada campo es, de mayor a menor:
     *
     * 1. Variable de entorno (`FEL_GUATEFAC_USER`, `FEL_ESTABLECIMIENTO`...).
     * 2. `config/config.local.php`, excluido del control de versiones.
     * 3. `config/config.php`, ya fusionado con la capa local.
     *
     * Así, si el hosting donde se despliegue permite fijar variables de
     * entorno, basta con definirlas: el archivo local deja de tener efecto y
     * puede eliminarse sin tocar código.
     *
     * @return array{
     *     username: string, password: string, nit: string, establishment: string,
     *     basic_username: string, basic_password: string,
     *     wsdl_url: string, endpoint_url: string,
     *     connection_timeout: int, response_timeout: int
     * }
     *         Configuración del proveedor de facturación electrónica.
     */
    public function getGuatefacturasConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = isset($this->config['fel']) && is_array($this->config['fel'])
            ? $this->config['fel']
            : [];

        $connectionTimeout = isset($defaults['connection_timeout'])
            ? (int) $defaults['connection_timeout']
            : 10;
        $responseTimeout   = isset($defaults['response_timeout'])
            ? (int) $defaults['response_timeout']
            : 60;

        return [
            'username'  => Env::string('FEL_GUATEFAC_USER', (string) ($defaults['username'] ?? '')),
            'password'  => Env::string('FEL_GUATEFAC_PASSWORD', (string) ($defaults['password'] ?? '')),
            'nit'       => Env::string('FEL_EMISOR_NIT', (string) ($defaults['nit'] ?? '')),
            'establishment' => Env::string(
                'FEL_ESTABLECIMIENTO',
                (string) ($defaults['establishment'] ?? '')
            ),

            'basic_username' => Env::string(
                'FEL_GUATEFAC_BASIC_USER',
                (string) ($defaults['basic_username'] ?? '')
            ),
            'basic_password' => Env::string(
                'FEL_GUATEFAC_BASIC_PASS',
                (string) ($defaults['basic_password'] ?? '')
            ),

            'wsdl_url' => Env::string('FEL_WSDL_URL', (string) ($defaults['wsdl_url'] ?? '')),
            'endpoint_url' => Env::string('FEL_ENDPOINT_URL', (string) ($defaults['endpoint_url'] ?? '')),

            // Un tiempo de espera de 0 desactivaría el control: se protege contra
            // un archivo local mal configurado que deje la conexión colgada.
            'connection_timeout' => max(1, Env::int('FEL_CONNECTION_TIMEOUT', $connectionTimeout)),
            'response_timeout'   => max(1, Env::int('FEL_RESPONSE_TIMEOUT', $responseTimeout)),
        ];
    }

    /**
     * Indica si los errores PHP deben mostrarse en la respuesta.
     *
     * Se fuerzan a `false` en `produccion` y en `desarrollo`, sin importar lo que
     * diga el archivo de configuración: en ambos la API está en un servidor
     * alcanzable desde Internet, y un error 500 que devuelve el tipo de
     * excepción, la ruta del archivo, el número de línea y la traza entrega al
     * visitante el mapa interno de la aplicación.
     *
     * El diagnóstico no se pierde por ocultarlo: el manejador de excepciones
     * escribe siempre en el `error_log` del servidor.
     *
     * @return bool `true` si se permite mostrar mensajes de error.
     */
    public function shouldDisplayErrors(): bool
    {
        if ($this->isPublicFacing()) {
            return false;
        }

        return (bool) ($this->config['display_errors'] ?? true);
    }

    /**
     * Devuelve la zona horaria configurada para la aplicación.
     *
     * @return string Identificador de zona horaria IANA.
     */
    public function getTimezone(): string
    {
        return (string) ($this->config['timezone'] ?? 'UTC');
    }
}