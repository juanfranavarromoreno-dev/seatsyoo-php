<?php

/**
 * Ejecuta las pruebas del SDK. Solo necesita PHP.
 *
 *     php tests/ejecutar.php               # unitarias y, si hay servidor de pruebas, de integración
 *     php tests/ejecutar.php unitarias     # solo unitarias (lo mismo que «composer test»)
 *
 * Las de integración necesitan un servidor de Seatsyoo de pruebas (SEATSYOO_TEST_SERVER y compañía): las
 * prepara tests/ejecutar.mjs, que levanta uno en memoria con el servidor de la copia a la que apunte BUTACAS_DIR
 * (BUTACAS_DIR=/ruta/al/servidor node tests/ejecutar.mjs). Sin él, se saltan con un aviso.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
// Cualquier aviso de PHP (también los de funciones obsoletas) cuenta como fallo.
set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
    if ((error_reporting() & $nivel) === 0) {
        return false;
    }
    throw new \ErrorException($mensaje, 0, $nivel, $archivo, $linea);
});

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'El SDK necesita PHP 8.1 o posterior (este es ' . PHP_VERSION . ")\n");
    exit(1);
}

require __DIR__ . '/../autoload.php';
require __DIR__ . '/marco.php';
require __DIR__ . '/apoyo.php';

$carpetas = array_slice($argv, 1);
if ($carpetas === []) {
    $carpetas = ['unitarias', 'integracion'];
}

echo 'PHP ' . PHP_VERSION . (extension_loaded('curl') ? ' con curl' : ' sin curl') . "\n";
$integracionSaltada = false;
foreach ($carpetas as $carpeta) {
    if ($carpeta === 'integracion' && getenv('SEATSYOO_TEST_SERVER') === false) {
        $integracionSaltada = true;
        echo "Sin servidor de pruebas (SEATSYOO_TEST_SERVER): se saltan las de integración. Para lanzarlas:\n"
            . "  BUTACAS_DIR=/ruta/a/la/copia/del/servidor node tests/ejecutar.mjs (mira «Pruebas» en el README).\n";
        continue;
    }
    $archivos = glob(__DIR__ . '/' . basename($carpeta) . '/*.php') ?: [];
    if ($archivos === []) {
        fwrite(STDERR, "No hay pruebas en tests/$carpeta\n");
        exit(1);
    }
    sort($archivos);
    foreach ($archivos as $archivo) {
        require $archivo;
    }
}

$fallos = ejecutar();
if ($integracionSaltada) {
    // El resumen de arriba solo cuenta las pruebas cargadas: que no parezca que se ha probado todo.
    echo "Integración: saltada entera (sin SEATSYOO_TEST_SERVER).\n";
}
exit($fallos > 0 ? 1 : 0);
