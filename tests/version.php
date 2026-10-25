<?php

/**
 * La versión del SDK y la etiqueta con la que se publica, que han de cuadrar:
 *
 *     php tests/version.php            # dice la versión del código (Client::VERSION)
 *     php tests/version.php v1.2.0     # comprueba que la etiqueta es vX.Y.Z y que X.Y.Z es Client::VERSION
 *
 * Packagist toma la versión de la etiqueta del repositorio (vX.Y.Z); el SDK la lleva en Client::VERSION, que va en
 * su User-Agent. El CI lo comprueba al subir una etiqueta, y conviene hacerlo antes de crearla (README, «Publicar
 * una versión»). Sale con 0 si cuadra y con 1 si no.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Seatsyoo\Client;

$version = Client::VERSION;
if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "Client::VERSION ($version) no es una versión X.Y.Z\n");
    exit(1);
}

$etiqueta = $argv[1] ?? null;
if ($etiqueta === null) {
    echo $version . "\n";
    exit(0);
}
if (preg_match('/^v(\d+\.\d+\.\d+)$/', $etiqueta, $partes) !== 1) {
    fwrite(STDERR, "La etiqueta «{$etiqueta}» no es vX.Y.Z (por ejemplo, v{$version})\n");
    exit(1);
}
if ($partes[1] !== $version) {
    fwrite(STDERR, "La etiqueta {$etiqueta} no cuadra con el código: Client::VERSION es {$version} (src/Client.php)\n");
    exit(1);
}
echo "La etiqueta {$etiqueta} cuadra con Client::VERSION ({$version})\n";
