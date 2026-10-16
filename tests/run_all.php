<?php

declare(strict_types=1);

/**
 * Ejecuta todas las pruebas del proyecto y resume el resultado.
 *
 * ## Por qué existe
 *
 * Cada suite se puede correr por separado, y así se documentan. Lo que no había
 * era una forma de correrlas todas, y al final cada comprobación se hacía a mano
 * en el orden que uno se acordaba.
 *
 * ## Qué usa red
 *
 * Solo la última suite llama al proveedor real. Con `--no-network` ninguna lo
 * hace, que es lo que corresponde en un entorno sin credenciales o cuando no se
 * quiere gastar emisiones del sandbox.
 *
 * @package App\Tests
 *
 * @author  fel-dentall
 * @license MIT
 */

$sinRed    = in_array('--no-network', $argv, true);
// Este archivo vive en `<raiz>/tests/`, así que la raíz del proyecto es `__DIR__`
// subido un nivel: `dirname(__DIR__)`.
$raiz      = dirname(__DIR__);

// Las rutas relativas se resuelven de forma fiable en este entorno; una ruta
// absoluta completa falló al abrir `tests/guard_confidencialidad.php` pese a que
// el archivo existe y PHP lo abre sin problema cuando se le pide en relativo.
$cwdOriginal = getcwd();

if (!@chdir($raiz)) {
    fwrite(STDERR, "No se pudo cambiar al directorio del proyecto.\n");
    exit(1);
}
$resultado = [];

/**
 * Localiza el archivo real de una suite dentro del directorio indicado.
 *
 * ## Por qué no se usa la ruta literal
 *
 * En este entorno `is_file('tests/guard_confidencialidad.php')` devuelve `false`
 * mientras que el mismo nombre, tomado del listado del directorio, resuelve
 * correctamente. No es un problema del nombre —su bytes son ASCII puro— sino de la
 * forma en que el sistema de archivos indexa la entrada. Resolver el nombre
 * enumerando el directorio funciona siempre y no depende de en qué máquina se
 * ejecute.
 *
 * @param string $directorio Ruta del directorio, relativa a la raíz.
 * @param string $prefijo    Prefijo del archivo a localizar.
 *
 * @return string|null Ruta relativa del archivo, o `null` si no está.
 */
$localizar = static function (string $directorio, string $prefijo): ?string {
    $entradas = @scandir($directorio);

    if ($entradas === false) {
        return null;
    }

    foreach ($entradas as $entrada) {
        if (strncmp($entrada, $prefijo, strlen($prefijo)) === 0) {
            return $directorio . '/' . $entrada;
        }
    }

    return null;
};

/**
 * Ejecuta una suite y devuelve su resumen.
 *
 * @param string $nombre    Nombre visible de la suite.
 * @param string $directorio Directorio de la suite.
 * @param string $prefijo   Prefijo del archivo de la suite.
 * @param bool   $requiereRed Si la suite llama al proveedor.
 *
 * @return array<string, mixed> Resumen de la ejecución.
 */
$ejecutar = static function (
    string $nombre,
    string $directorio,
    string $prefijo,
    bool $requiereRed = false
) use ($sinRed, $localizar): array {
    if ($requiereRed && $sinRed) {
        return ['nombre' => $nombre, 'omitida' => true, 'codigo' => null, 'resumen' => 'omitida (--no-network)'];
    }

    $ruta = $localizar($directorio, $prefijo);

    if ($ruta === null) {
        return [
            'nombre'  => $nombre,
            'omitida' => false,
            'codigo'  => 1,
            'resumen' => 'archivo no encontrado',
            'detalle' => 'No se encontró ' . $directorio . '/' . $prefijo . '*',
            'comando' => '(ninguno)',
        ];
    }

    $salida = [];
    $codigo = 0;

    $comando = sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($ruta));

    exec($comando, $salida, $codigo);

    $texto = implode("\n", $salida);
    $resumen = '';

    if (preg_match('/Pasaron: (\d+) \| Fallaron: (\d+)/', $texto, $coincidencia) === 1) {
        $resumen = sprintf('%d / %d', (int) $coincidencia[1], (int) $coincidencia[1] + (int) $coincidencia[2]);
    } elseif (preg_match('/(\d+) de (\d+) comprobaciones correctas/', $texto, $coincidencia) === 1) {
        $resumen = $coincidencia[1] . ' / ' . $coincidencia[2];
    }

    return [
        'nombre'   => $nombre,
        'omitida'  => false,
        'codigo'   => $codigo,
        'resumen'  => $resumen !== '' ? $resumen : 'sin resumen',
        'detalle'  => $texto,
        'comando'  => $comando,
    ];
};

echo "fel-dentall · ejecución de pruebas\n";
echo str_repeat('=', 60), "\n\n";

$suites = [
    ['Lógica pura',             'tests', 'pure_units',                 false],
    ['Contingencia y reintento', 'tests', 'contingencia',              false],
    ['Confidencialidad',        'tests', 'guard_confidencialidad',    false],
    ['Contrato HTTP',           'tests', 'http_matrix',               false],
    ['Integración real',        'tests', 'integration_guatefacturas', true],
];

$fallos = 0;

foreach ($suites as $suite) {
    $resultado = $ejecutar($suite[0], $suite[1], $suite[2], $suite[3]);

    if ($resultado['omitida']) {
        printf("  %-26s %s\n", $resultado['nombre'], $resultado['resumen']);
        continue;
    }

    $marca = $resultado['codigo'] === 0 ? 'OK  ' : 'FALLA';
    printf("  [%s] %-24s %s\n", $marca, $resultado['nombre'], $resultado['resumen']);

    if ($resultado['codigo'] !== 0) {
        $fallos++;
        echo "\n--- detalle de {$resultado['nombre']} ---\n";
        echo '  comando: ' . ($resultado['comando'] ?? '(ninguno)') . "\n";

        foreach (explode("\n", (string) $resultado['detalle']) as $linea) {
            $recortada = trim($linea);

            if ($recortada !== '') {
                echo '  ', mb_substr($recortada, 0, 160), "\n";
            }
        }

        echo "\n";
    }
}

echo "\n", str_repeat('=', 60), "\n";

if (is_string($cwdOriginal)) {
    chdir($cwdOriginal);
}

if ($fallos === 0) {
    echo "Todas las suites pasaron.\n";
    exit(0);
}

echo "{$fallos} suite(s) con fallos.\n";
exit(1);