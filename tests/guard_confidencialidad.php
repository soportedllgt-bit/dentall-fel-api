<?php

declare(strict_types=1);

/**
 * Guardián de confidencialidad.
 *
 * Comprueba que las dos barreras que protegen el material sensible siguen en pie:
 *
 * 1. `.gitignore` excluye los documentos confidenciales y las credenciales.
 * 2. `.htaccess` bloquea el acceso HTTP a las carpetas internas del proyecto.
 *
 * Ambas barreras son independientes: un `.gitignore` correcto no evita que un
 * servidor web mal configurado exponga `config/config.php`. Por eso se
 * comprueban las dos, y por eso una regresión en cualquiera de ellas falla la
 * suite en lugar de descubrirse en una auditoría.
 *
 * Uso: php tests/guard_confidentialidad.php
 *
 * @package App\Tests
 *
 * @author  fel-dentall
 * @license MIT
 */

$projectRoot = dirname(__DIR__);
$passed      = 0;
$failed      = 0;

/**
 * Registra el resultado de una aserción.
 *
 * @param string $name      Descripción de la aserción.
 * @param bool   $condition Resultado esperado.
 * @param string $detail    Detalle a mostrar si falla.
 *
 * @return void
 */
$assert = static function (string $name, bool $condition, string $detail = '') use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  [OK]    {$name}\n";
        return;
    }

    $failed++;
    echo "  [FALLA] {$name}\n";

    if ($detail !== '') {
        echo "          {$detail}\n";
    }
};

/**
 * Lee un archivo del proyecto devolviendo su contenido o `null` si no existe.
 *
 * @param string $path Ruta absoluta.
 *
 * @return string|null Contenido del archivo o `null`.
 */
$read = static function (string $path): ?string {
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $contents = @file_get_contents($path);

    return $contents === false ? null : $contents;
};

/**
 * Simula las reglas de `.gitignore` contra una ruta relativa.
 *
 * No cubre todos los casos de la especificación de gitignore, sino las reglas
 * que este proyecto declara de forma explícita. Es suficiente para detectar que
 * alguien borró o desactivó una exclusión.
 *
 * @param array<int, string> $rules Líneas de `.gitignore` sin comentarios.
 * @param string             $path Ruta relativa con separador `/`.
 *
 * @return bool `true` si la ruta quedaría excluida.
 */
$isIgnored = static function (array $rules, string $path): bool {
    // Se recorren en orden, igual que hace git: la última regla que casa gana.
    $ignored = false;

    foreach ($rules as $rule) {
        $negate = strpos($rule, '!') === 0;

        if ($negate) {
            $rule = substr($rule, 1);
        }

        if ($rule === '') {
            continue;
        }

        // Regla de directorio: `docs/` excluye todo lo que cuelgue de él.
        if (substr($rule, -1) === '/') {
            $directory = substr($rule, 0, -1);

            if (strpos($path, $directory . '/') === 0) {
                $ignored = !$negate;
            }

            continue;
        }

        // Regla con comodín: se traduce a una expresión regular.
        $pattern = preg_quote($rule, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = str_replace('\?', '.', $pattern);

        if (preg_match('#^' . $pattern . '$#', $path) === 1) {
            $ignored = !$negate;
        }
    }

    return $ignored;
};

echo "== .gitignore ==\n";

$gitignore = $read($projectRoot . '/.gitignore');

$assert('existe el archivo .gitignore', $gitignore !== null);

if ($gitignore === null) {
    echo "\nNo se puede continuar sin .gitignore.\n";
    exit(1);
}

// Se extraen solo las reglas: sin comentarios ni líneas vacías.
$rules = [];
foreach (explode("\n", str_replace("\r\n", "\n", $gitignore)) as $line) {
    $line = trim($line);

    if ($line === '' || strpos($line, '#') === 0) {
        continue;
    }

    $rules[] = $line;
}

$assert('.gitignore tiene reglas effectivas', $rules !== []);

$mustIgnore = [
    'docs/guia-operativa.pdf'          => 'manual confidencial del proveedor',
    'docs/manual-otro-proveedor.pdf'    => 'cualquier manual en PDF',
    'docs/subcarpeta/anexos.pdf'        => 'PDF en subcarpeta',
    'config/config.local.php'           => 'sobreescritura local de credenciales',
    '.env'                              => 'variables de entorno',
    '.env.produccion'                   => 'variables de entorno por entorno',
    'report.html'                       => 'informe de Newman con respuestas reales',
    'api.credentials'                   => 'archivo de credenciales',
    'tests/fixtures/real/respuesta.xml' => 'captura del Web Service real',
    'debug.log'                         => 'log con datos de ejecución',
];

foreach ($mustIgnore as $path => $motivo) {
    $assert("excluye {$path} ({$motivo})", $isIgnored($rules, $path));
}

$mustTrack = [
    '.env.example'                        => 'plantilla de variables sin secretos',
    'config/config.php'                   => 'configuración por entorno versionable',
    'config/config.local.example.php'     => 'plantilla de credenciales sin secretos',
    'docs/base.md'                        => 'documentación técnica propia',
    'app/Core/Router.php'                 => 'código fuente',
    'sql/migrations/001_x.sql'            => 'migraciones',
    'tests/postman/fel-dentall-api.postman_collection.json' => 'entregable de QA',
];

foreach ($mustTrack as $path => $motivo) {
    $assert("versiona {$path} ({$motivo})", !$isIgnored($rules, $path));
}

// Escanear literales de credencial por palabra clave produce falsos positivos:
// `config/config.php` contiene la clave `'password'` y el README documenta
// `DB_PASS`, sin que exista ningún secreto. Lo que sí importa se comprueba abajo
// de forma específica: que las credenciales de producción estén vacías y que no
// haya valores reales del proveedor en archivos versionados.

// La rama de producción no debe traer ninguna credencial por defecto: si se
// escribe una aquí, se versionaría un secreto.
$configSource = $read($projectRoot . '/config/config.php');

$assert('existe config/config.php', $configSource !== null);

if ($configSource !== null) {
    if (preg_match("/'produccion'\s*=>\s*\[(.*?)\n    \],/s", $configSource, $matches) === 1) {
        $productionBlock = $matches[1];

        $assert(
            'la rama de producción no declara credenciales por defecto',
            preg_match("/'username'\s*=>\s*'[^']+'/", $productionBlock) !== 1
                && preg_match("/'password'\s*=>\s*'[^']+'/", $productionBlock) !== 1,
            'La rama produccion de config/config.php trae usuario o contraseña literales.'
        );

        $assert(
            'la rama de producción exige credenciales por variable de entorno',
            stripos($productionBlock, 'DB_USER') !== false
                || stripos($configSource, 'DB_USER') !== false,
            'No hay referencia a DB_USER en config/config.php.'
        );
    } else {
        $assert('se localiza el bloque de configuración de producción', false, 'No se encontró la rama produccion.');
    }
}

// Ningún archivo versionado puede contener credenciales del proveedor de
// facturación electrónica ni NITs de emisores reales.
//
// El patrón distingue dos cosas que se parecen pero no son lo mismo:
//   - `'pUsuario' => 'FEL_GUATEFAC_USER'`  → referencia al nombre de la variable
//   - `'pUsuario' => 'WSFEL_XXXXXXXXX'`   → credencial filtrada
// Los valores que empiezan por un prefijo de variable de entorno se excluyen
// mediante una aserción negativa.
$credentialPattern = '/(pUsuario|pPassword|GF_USERNAME|GF_PASSWORD|'
    . 'FEL_GUATEFAC_USER|FEL_GUATEFAC_PASSWORD|'
    . 'FEL_GUATEFAC_BASIC_USER|FEL_GUATEFAC_BASIC_PASS)'
    . '\s*[\'"]?\s*[:=]>?\s*[\'"]'
    . '(?!(?:FEL_|GF_|API_|DB_|CORS_)[A-Z_]+\b)'
    . '[A-Za-z0-9!#$%&*_\-]{6,}[\'"]/i';
$leaked               = [];

$scannable = [
    'app',
    'config',
    'tests',
    'sql',
    'public',
    'docs',
];

foreach ($scannable as $relative) {
    $directory = $projectRoot . '/' . $relative;

    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $extension = strtolower($file->getExtension());

        // Solo texto plano; los binarios no aportan literales legibles.
        if (!in_array($extension, ['php', 'sql', 'json', 'md', 'txt', 'ini'], true)) {
            continue;
        }

        if ($file->getSize() > 2097152) {
            continue;
        }

        $relativePath = ltrim(str_replace('\\', '/', $file->getPathname()), '/');
        $relativePath = preg_replace('#^' . preg_quote($projectRoot, '#') . '/#', '', $relativePath);

        // El propio guardián contiene los literales que busca (`pUsuario`,
        // `FEL_GUATEFAC_PASSWORD`...) porque su patrón está escrito en el
        // archivo. Escanearse a sí mismo produciría un positivo permanente.
        if (realpath((string) $file->getPathname()) === realpath(__FILE__)) {
            continue;
        }

        // Se respeta la regla de exclusión: lo ignorado no puede filtrarse.
        if ($isIgnored($rules, (string) $relativePath)) {
            continue;
        }

        $contents = $read($file->getPathname());

        if ($contents !== null && preg_match($credentialPattern, $contents, $hits) === 1) {
            $leaked[] = $relativePath . ' (' . trim($hits[0][0]) . ')';
        }
    }
}

$assert(
    'ningún archivo versionado contiene credenciales literales del proveedor',
    $leaked === [],
    implode(', ', $leaked)
);

echo "\n== .htaccess (protección del servidor web) ==\n";

$htaccess = $read($projectRoot . '/.htaccess');

$assert('existe el .htaccess de la raíz del proyecto', $htaccess !== null);

if ($htaccess !== null) {
    // Las carpetas internas se protegen con un único RewriteRule cuyo patrón es
    // una alternancia: `^(app|config|sql|docs)(/|$)`. Validar cada alternativa
    // por separado exigiría un patrón distinto para cada una, así que se extrae
    // la lista completa y se comprueba como conjunto.
    $deniedDirectories = [];

    if (preg_match_all(
        '/^\s*RewriteRule\s+\^\(([^)]*)\)\(\/\|\$\)\s+-\s+\[F(?:,|])/mi',
        $htaccess,
        $rulesFound
    ) > 0) {
        foreach ($rulesFound[1] as $alternatives) {
            foreach (explode('|', $alternatives) as $alternative) {
                $alternative = trim($alternative);

                if ($alternative !== '') {
                    $deniedDirectories[] = $alternative;
                }
            }
        }
    }

    foreach (['app', 'config', 'sql', 'docs'] as $directory) {
        $assert(
            "bloquea el acceso HTTP a {$directory}/",
            in_array($directory, $deniedDirectories, true),
            'Alternativas encontradas: ' . (implode(', ', $deniedDirectories) ?: 'ninguna')
        );
    }

    $assert(
        'no expone ninguna carpeta interna fuera de la lista prevista',
        array_diff($deniedDirectories, ['app', 'config', 'sql', 'docs']) === [],
        implode(', ', array_diff($deniedDirectories, ['app', 'config', 'sql', 'docs']))
    );

    $assert(
        'impide el listado de directorios (Options -Indexes)',
        (bool) preg_match('/^\s*Options\s+-Indexes/mi', $htaccess)
    );

    $assert(
        'deniega el acceso a archivos ocultos',
        stripos($htaccess, 'FilesMatch "^\\."') !== false
            || stripos($htaccess, 'FilesMatch "^\."') !== false
    );
}

$publicHtaccess = $read($projectRoot . '/public/.htaccess');

$assert('existe el .htaccess de public/', $publicHtaccess !== null);

if ($publicHtaccess !== null) {
    $assert(
        'el front controller usa RewriteRule ^ index.php [QSA,L]',
        (bool) preg_match('/RewriteRule\s+\^\s+index\.php\s+\[QSA,L\]/i', $publicHtaccess)
    );

    $assert(
        'el front controller no reescribe archivos existentes',
        (bool) preg_match('/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-f/i', $publicHtaccess)
    );

    $assert(
        'impide el listado de directorios en public/',
        (bool) preg_match('/^\s*Options\s+-Indexes/mi', $publicHtaccess)
    );
}

echo "\n== Plantilla de credenciales ==\n";

$example = $read($projectRoot . '/config/config.local.example.php');
$assert('existe config/config.local.example.php', $example !== null);

if ($example !== null) {
    // La plantilla SÍ se versiona: cualquier valor real en ella es una fuga.
    $exampleFields = [
        'username', 'password', 'nit', 'establishment',
        'basic_username', 'basic_password', 'wsdl_url', 'endpoint_url',
    ];

    $filled = [];

    foreach ($exampleFields as $field) {
        if (preg_match(
            sprintf("/'%s'\s*=>\s*'([^']*)'/", preg_quote($field, '/')),
            $example,
            $matches
        ) === 1 && trim($matches[1]) !== '') {
            $filled[] = $field;
        }
    }

    $assert(
        'la plantilla de credenciales no tiene ningún valor real',
        $filled === [],
        'Campos con valor: ' . implode(', ', $filled)
    );
}

echo "\n== Codificación de los archivos ==\n";

// Un BOM UTF-8 antes de `<?php` hace que PHP emita tres bytes antes de ejecutar
// nada, y `declare(strict_types=1)` deja de ser la primera sentencia: el
// archivo deja de ser válido. Es un fallo silencioso que solo aparece al lintar,
// y lo introduce fácilmente cualquier herramienta que reescriba un archivo.
$bomFiles = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    if (!in_array(strtolower($file->getExtension()), ['php', 'sql'], true)) {
        continue;
    }

    $path = $file->getPathname();

    if (realpath($path) === realpath(__FILE__)) {
        continue;
    }

    $bytes = @file_get_contents($path, false, null, 0, 3);

    if ($bytes === false || strlen($bytes) < 3) {
        continue;
    }

    if (ord($bytes[0]) === 0xEF && ord($bytes[1]) === 0xBB && ord($bytes[2]) === 0xBF) {
        $bomFiles[] = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $path);
    }
}

$assert(
    'ningún archivo PHP o SQL empieza con BOM',
    $bomFiles === [],
    'Archivos con BOM: ' . implode(', ', $bomFiles)
);

echo "\n== Estructura del proyecto ==\n";

$expected = [
    'app/bootstrap.php',
    'app/Core/Auth.php',
    'app/Core/Database.php',
    'app/Integrations/Guatefacturas/GuatefacturasClient.php',
    'app/Integrations/Guatefacturas/GuatefacturasConfig.php',
    'app/Integrations/Guatefacturas/DocumentResult.php',
    'config/config.php',
    'config/config.local.example.php',
    'public/index.php',
    'public/.htaccess',
    'sql/schema.sql',
    '.htaccess',
    '.gitignore',
];

foreach ($expected as $relative) {
    $assert("existe {$relative}", is_file($projectRoot . '/' . $relative));
}

// `docs/` no se versiona: el manual del proveedor es confidencial y la nota de
// entrega se entrega por separado. La carpeta puede estar ausente, vacía en el
// árbol de trabajo, o poblada si alguien restaura la documentación; en ese último
// caso sí se le exige el índice. Git no registra directorios vacíos, así que una
// carpeta vacía nunca llega al repositorio.
if (is_dir($projectRoot . '/docs') && count((array) scandir($projectRoot . '/docs')) > 2) {
    echo "\n== docs/ presente en el árbol de trabajo (no se versiona) ==\n";
    $assert(
        'si hay documentación en docs/, el índice va dentro',
        is_file($projectRoot . '/docs/README.md')
    );
}

echo "\n----------------------------------------\n";
echo sprintf("Pasaron: %d | Fallaron: %d\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);