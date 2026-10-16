<?php

/**
 * Ejecuta las pruebas del SDK.
 *
 *     php tests/ejecutar.php               # unitarias y, si hay servidor de pruebas, de integración
 *     php tests/ejecutar.php unitarias     # solo unitarias
 *
 * Las de integración necesitan un servidor de Seatsyoo de pruebas (SEATSYOO_TEST_SERVER y compañía): las
 * prepara tests/ejecutar.mjs (pnpm test), que levanta uno en memoria.
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
foreach ($carpetas as $carpeta) {
    if ($carpeta === 'integracion' && getenv('SEATSYOO_TEST_SERVER') === false) {
        echo "Sin servidor de pruebas (SEATSYOO_TEST_SERVER): se saltan las de integración. Lánzalas con «pnpm test».\n";
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

exit(ejecutar() > 0 ? 1 : 0);
