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
 * Capa de sobreescritura local.
 *
 * Se carga primero, antes de resolver el entorno, porque `env` se declara aquí.
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

/**
 * Nombre del entorno activo.
 *
 * Se resuelve en este orden:
 *
 * 1. La variable de entorno `APP_ENV`, si existe de verdad, a través de
 *    `App\Core\Env`.
 * 2. La clave `env` de `config/config.local.php`, si declara un nombre válido.
 * 3. `local`.
 *
 * La variable de entorno gana sobre el archivo local para que un operador
 * pueda cambiar de entorno sin tocar el disco: pasar de `desarrollo` a
 * `produccion` tiene que ser una operación de un segundo, no una edición de
 * archivo en un servidor al que se entra por FTP.
 *
 * Se delega en `App\Core\Env` y no en un `getenv()` directo a propósito.
 * `Env::get()` consulta `getenv()`, `$_ENV` y `$_SERVER`, y esa tercera fuente
 * es la que hace funcionar la instrucción `SetEnv APP_ENV desarrollo` de un
 * `.htaccess`: Apache expone lo que fija con `SetEnv` en `$_SERVER`, pero no
 * necesariamente en el entorno del proceso cuando PHP corre como FPM o LSAPI.
 *
 * Leyendo solo `getenv()`, este archivo habría resuelto `local` en un servidor
 * que sí tiene `APP_ENV=desarrollo`, sin ningún aviso: la API habría arrancado
 * con `root` sin contraseña y `admin`/`admin123` por defecto, y con el detalle
 * técnico de los errores visible en las respuestas.
 *
 * La clave `env` del archivo local cubre el caso del hosting que no ofrece
 * forma ninguna de fijar variables de entorno y cuyo `.htaccess` no puede
 *Ledebido a que un redeploy sobrescriba el archivo. Al estar en `.gitignore`,
 * sobrevive a un `git pull`.
 *
 * Un nombre desconocido nunca se acepta: se degrada a `local`, de modo que una
 * errata en `env` no pueda dejar la API apuntando al servicio real de
 * facturación. `produccion` solo se alcanza escribiendo exactamente esa palabra,
 * y un despliegue con credenciales de `feltest` que llegase hasta allí emitiría
 * facturas de verdad.
 *
 * `Env` no tiene dependencias y este archivo lo carga él mismo con
 * `require_once`, en lugar de fiarse del autoloader: `config.php` se incluye y
 * se examina por separado, sin arrancar la aplicación, y debe seguir siendo
 * legible así. La comprobación previa evita cargarlo dos veces cuando el
 * autoloader de la aplicación ya lo trajo.
 *
 * @var string `local`, `desarrollo` o `produccion`.
 */
if (!class_exists('App\Core\Env', true)) {
    require_once __DIR__ . '/../app/Core/Env.php';
}

$envConocidos = [App\Core\Env::ENV_LOCAL, App\Core\Env::ENV_DEVELOPMENT, App\Core\Env::ENV_PRODUCTION];
$envLocal     = isset($localConfig['env']) && is_string($localConfig['env'])
    ? strtolower(trim($localConfig['env']))
    : '';

if (App\Core\Env::get(App\Core\Env::KEY_APP_ENV) !== null) {
    $env = App\Core\Env::appEnv();
} elseif (in_array($envLocal, $envConocidos, true)) {
    $env = $envLocal;
} else {
    $env = App\Core\Env::DEFAULT_ENV;
}

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
 * Entorno de desarrollo desplegado en un servidor con dominio propio.
 *
 * Se deriva de `produccion` en lugar de repetirse entero, y solo se le cambian
 * los dos valores que lo separan de ella:
 *
 * 1. Los endpoints pasan a ser los de `feltestSB`. Es la diferencia crítica:
 *    `produccion` apunta al servicio real, de modo que desplegar el código con
 *    `APP_ENV=produccion` y credenciales de prueba emitiría facturas de verdad.
 *    Aquí es imposible confundir un entorno con otro, porque cada uno lleva
 *    escrito su propio destino.
 * 2. Se conserva la lista blanca de CORS vacía y la ausencia de credenciales
 *    por defecto que trae `produccion`, que es lo que corresponde a un servidor
 *    alcanzable desde Internet.
 *
 * Lo que `desarrollo` no trae, a diferencia de `local`, es el detalle técnico
 * en las respuestas: `App\Core\AppConfig::shouldDisplayErrors()` lo fuerza a
 * `false` porque el servidor es público. Los errores siguen registrándose en el
 * `error_log`, así que no se pierde el diagnóstico.
 *
 * @var array<string, array<string, mixed>>
 */
$desarrollo = $environments['produccion'];
$desarrollo['fel'] = array_merge($desarrollo['fel'], [
    'wsdl_url'     => 'https://pdte.guatefacturas.com/webservices63/feltestSB/Guatefac?wsdl',
    'endpoint_url' => 'https://pdte.guatefacturas.com/webservices63/feltestSB/Guatefac',
    'response_timeout' => 60,
]);
$environments['desarrollo'] = $desarrollo;

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
    // `App\Core\AppConfig::shouldDisplayErrors()` lo fuerza a `false` en
    // `produccion` y en `desarrollo` de todos modos; aquí solo se deja explícito
    // para que el valor por defecto sea evidente al leer el archivo.
    'display_errors' => !in_array($env, ['produccion', 'desarrollo'], true),
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
 * Todas las secciones admiten sobreescritura, no solo `fel`. La merge es por
 * clave de primer nivel: basta declarar en el archivo local únicamente lo que
 * se quiere cambiar, y el resto de la sección conserva su valor por defecto.
 *
 * Se fusionan `fel`, `db`, `auth`, `cors` y `billing`. Las tres primeras son
 * las que llevan credenciales, y son precisamente las que un hosting
 * compartido no siempre permite fijar por variable de entorno: declarar aquí
 * `db` y `auth` evita depender de que `SetEnv` sobreviva al handler de PHP.
 *
 * La prioridad la sigue teniendo la variable de entorno, que se aplica después
 * en `App\Core\AppConfig`: por eso un servidor que sí permita fijarlas puede
 * borrar este archivo sin tocar código.
 *
 * @var array<string, mixed> $localConfig
 */
/**
 * Fusiona una sección de configuración con sus sobreescrituras locales.
 *
 * Se declara aquí y no en una clase porque este archivo no carga el autoloader
 * a propósito: se puede incluir y examinar por separado, sin arrancar la
 * aplicación, que es lo que hacen las pruebas y cualquier diagnóstico.
 *
 * Las sobreescrituras se aplican clave por clave, de modo que basta declarar en
 * el archivo local lo que se quiere cambiar y el resto de la sección conserva
 * su valor por defecto.
 *
 * Una cadena vacía se descarta en lugar de sobreescribir. Es la regla que hace
 * segura la plantilla `config/config.local.example.php`, que se distribuye con
 * todos sus campos en blanco: si una cadena vacía contara como sobreescritura,
 * copiar la plantilla tal cual dejaría a '' `wsdl_url`, y el cliente SOAP caería
 * a su valor por defecto en vez de usar el del entorno activo. El fallo es
 * silencioso y peligroso, porque en `produccion` convertiría una emisión real
 * en una de pruebas sin que nada lo delate.
 *
 * Un array vacío sí se aplica: `cors.allowed_origins => []` es una decisión
 * deliberada —no permitir ningún origen—, no una omisión.
 *
 * @param array<string, mixed> $base      Sección con los valores por defecto.
 * @param array<string, mixed> $overrides Sección con las sobreescrituras locales.
 *
 * @return array<string, mixed> Sección ya fusionada.
 */
$mergeSection = static function (array $base, array $overrides): array {
    $aplicables = [];

    foreach ($overrides as $clave => $valor) {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            continue;
        }

        $aplicables[$clave] = $valor;
    }

return $aplicables === [] ? $base : array_merge($base, $aplicables);
};

foreach (['fel', 'db', 'auth', 'cors', 'billing'] as $section) {
    if (isset($localConfig[$section]) && is_array($localConfig[$section])) {
        $config[$section] = $mergeSection($config[$section], $localConfig[$section]);
    }
}

return $config;