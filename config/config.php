<?php

/**
 * Developed by: Web art studios
 * Project: API middleware Guatefacturas
 * Year: 2026
 * All rights reserved. Proprietary software.
 */

declare(strict_types=1);

/**
 * Archivo de configuración de la aplicación.
 *
 * Devuelve un array de configuración con los valores por defecto de cada
 * entorno. Los valores reales de producción NUNCA deben depender solamente de
 * este archivo: {@see \App\Core\Env} permite sobreescribirlos mediante
 * variables de entorno del sistema, que tienen prioridad sobre lo aquí definido.
 *
 * @package App\Config
 *
 * @author  fel-dentall
 * @license MIT
 */

/**
 * Nombre del entorno activo.
 *
 * Se resuelve leyendo la variable de entorno `APP_ENV`. Si no está definida,
 * se asume `local`, que es el comportamiento seguro para desarrollo.
 *
 * @var string
 */
$env = getenv('APP_ENV');
$env = (is_string($env) && $env !== '') ? strtolower($env) : 'local';

/**
 * Definición de configuración por entorno.
 *
 * - `db`   : credenciales y parámetros de conexión MySQL/MariaDB.
 * - `auth` : credenciales de HTTP Basic Auth y realm publicado.
 * - `cors` : lista blanca de orígenes permitidos para CORS.
 * - `items`: reglas de paginación del recurso `items`.
 * - `billing`: reglas de negocio de la facturación electrónica.
 * - `fel`  : endpoints y tiempos del proveedor de facturación.
 *
 * @var array<string, array<string, mixed>>
 */
$environments = [
    'local' => [
        'db' => [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'fel_dentall_api',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
        ],
        'auth' => [
            'username' => 'admin',
            'password' => 'admin123',
            'realm'    => 'API REST Items',
        ],
        'cors' => [
            // En local se permiten los orígenes de desarrollo habituales.
            'allowed_origins' => [
                'http://localhost:5173',
                'http://localhost:3000',
                'http://127.0.0.1:5173',
                'http://127.0.0.1:3000',
            ],
        ],
        'items' => [
            'default_limit' => 25,
            'max_limit'     => 50,
        ],
        'billing' => [
            // IVA por defecto de una línea. Puede variarse por línea.
            'default_tax_percent'  => 12,
            // Tope de líneas del carrito.
            'max_lines'             => 200,
            'order_code_max_length' => 64,
            'customer_name_max'     => 100,
            'customer_address_max'  => 200,
            'line_description_max'  => 255,
            // Zona en la que se fecha el documento. NO es la zona del servidor:
            // el proveedor rechaza con «56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR
            // A LA FECHA DEL SISTEMA» los documentos fechados un día por
            // delante, lo que ocurre si se usa la fecha UTC entre las 18:00 y
            // las 24:00 hora de Guatemala.
            'document_timezone'     => 'America/Guatemala',
        ],
        'fel' => [
            // Sandbox. En `local` se emite siempre contra el entorno de
            // pruebas, nunca contra el de producción.
            'wsdl_url' => 'https://pdte.guatefacturas.com/webservices63/feltestSB/Guatefac?wsdl',
            'endpoint_url' => 'https://pdte.guatefacturas.com/webservices63/feltestSB/Guatefac',
            'connection_timeout' => 10,
            'response_timeout'   => 60,
            // Las credenciales viven en config/config.local.php o en variables
            // de entorno; aquí solo se declaran los nombres para la documentación.
            'username' => '',
            'password' => '',
            'nit'      => '',
            'establishment' => '',
            'basic_username' => '',
            'basic_password' => '',
        ],
    ],

    'produccion' => [
        'db' => [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'fel_dentall_api',
            // Marcadores de posición: deben sobreescribirse con variables de
            // entorno (DB_HOST, DB_NAME, DB_USER, DB_PASS) antes de desplegar.
            'username' => '',
            'password' => '',
            'charset'  => 'utf8mb4',
        ],
        'auth' => [
            // Ídem: sobreescribir con API_USER y API_PASS.
            'username' => '',
            'password' => '',
            'realm'    => 'API REST Items',
        ],
        'cors' => [
            // Lista blanca explícita: nunca comodín en producción.
            'allowed_origins' => [],
        ],
        'items' => [
            'default_limit' => 25,
            'max_limit'     => 50,
        ],
        'billing' => [
            'default_tax_percent'  => 12,
            'max_lines'             => 200,
            'order_code_max_length' => 64,
            'customer_name_max'     => 100,
            'customer_address_max'  => 200,
            'line_description_max'  => 255,
            'document_timezone'     => 'America/Guatemala',
            // Antigüedad tras la cual una orden en EN_PROCESO se considera
            // atascada y puede volver a emitirse. Si el proceso muere entre
            // enviar el XML y registrar la respuesta, la orden quedaría
            // bloqueada con un 409 eterno y sin ninguna vía de salida.
            'processing_timeout'    => 300,
            // Tope de intentos por orden. Sin él, un POS que reintenta en bucle
            // consume emisiones del proveedor sin límite.
            'max_attempts'          => 5,
        ],
        'fel' => [
            'wsdl_url'     => 'https://pdte.guatefacturas.com/webservices63/fel/Guatefac?wsdl',
            'endpoint_url' => 'https://pdte.guatefacturas.com/webservices63/fel/Guatefac',
            'connection_timeout' => 10,
            'response_timeout'   => 90,
            'username' => '',
            'password' => '',
            'nit'      => '',
            'establishment' => '',
            'basic_username' => '',
            'basic_password' => '',
        ],
    ],
];

/**
 * Selecciona la rama del entorno solicitado. Si `APP_ENV` contiene un valor
 * desconocido se degrada a `local`, nunca a `produccion`, para no exponer
 * detalles técnicos de forma accidental.
 *
 * @var array<string, mixed>
 */
$active = $environments[$env] ?? $environments['local'];

/**
 * @var array<string, mixed> $config
 */
$config = [
    'env'            => $env,
    'display_errors' => ($env === 'produccion') ? false : true,
    'timezone'       => 'UTC',
    'db'             => $active['db'],
    'auth'           => $active['auth'],
    'cors'           => $active['cors'],
    'items'          => $active['items'],
    'billing'        => $active['billing'],
    'fel'            => $active['fel'],
];

/**
 * Capa de sobreescritura local, si existe.
 *
 * `config/config.local.php` contiene credenciales reales y está excluido por
 * `.gitignore`. Si no está presente, la aplicación funciona igualmente con los
 * valores por defecto (que para el proveedor son vacíos, de modo que fallará
 * con un error de configuración explícito y no con un error de SOAP críptico).
 *
 * Nótese que un archivo PHP cuyo único contenido es un `return` no produce
 * ninguna salida si se solicita por HTTP: el servidor lo ejecuta y devuelve el
 * cuerpo vacío. Esa es la razón de que aquí vivan las credenciales y no en un
 * `.env`, que Apache serviría en texto plano.
 *
 * @var array<string, mixed> $localConfig
 */
$localFile = __DIR__ . '/config.local.php';
$localConfig = is_file($localFile) && is_readable($localFile) ? (array) require $localFile : [];

if (isset($localConfig['fel']) && is_array($localConfig['fel'])) {
    $config['fel'] = array_merge($config['fel'], $localConfig['fel']);
}

return $config;