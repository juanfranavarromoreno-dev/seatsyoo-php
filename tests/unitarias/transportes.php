<?php

/**
 * Los dos transportes (curl y flujos de PHP) contra servidores locales de PHP (tests/receptor.php): plazos, goteo y
 * error de red. Solo necesitan PHP, así que corren con las unitarias, también en la matriz de versiones del CI. Lo que
 * necesita un servidor de Seatsyoo de verdad está en tests/integracion/transportes.php.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\Http\CurlTransport;
use Seatsyoo\SeatsyooException;

grupo('Transportes, contra servidores locales');

prueba('el cliente usa curl si está', function (): void {
    if (!extension_loaded('curl')) {
        saltar('este PHP no tiene curl');
    }
    $cliente = new Client(secretKey: 'sk_x', server: 'https://seatsyoo.example');
    $http = (new \ReflectionProperty(Client::class, 'http'))->getValue($cliente);
    $transporte = (new \ReflectionProperty($http, 'transport'))->getValue($http);
    igual(CurlTransport::class, get_class($transporte));
});

foreach (transportes() as $nombre => $crear) {
    prueba("$nombre: plazo agotado contra un servidor lento", function () use ($crear): void {
        $lento = new ServidorLocal(__DIR__ . '/../receptor.php', ['SEATSYOO_LENTO_MS' => '2000']);
        $seatsyoo = new Client(secretKey: 'sk_x', server: $lento->url . '/lento', timeout: 0.4, maxRetries: 0, transport: $crear());
        $inicio = microtime(true);
        $error = lanza(SeatsyooException::class, fn () => $seatsyoo->workspace->retrieve());
        igual(0, $error->getStatus());
        igual('timeout', $error->getErrorCode());
        contiene('0,4 s', $error->getMessage());
        entre(0.3, 1.9, microtime(true) - $inicio, 'corta a tiempo');
        $lento->parar();
    });

    prueba("$nombre: el plazo es de la petición entera, aunque el cuerpo llegue a goteo", function () use ($crear): void {
        // Un byte cada 0,3 s durante 3 s: cada lectura llega a tiempo, pero la respuesta entera no.
        $goteo = new ServidorLocal(__DIR__ . '/../receptor.php', ['SEATSYOO_GOTEO_MS' => '300', 'SEATSYOO_GOTEO_VECES' => '10']);
        $seatsyoo = new Client(secretKey: 'sk_x', server: $goteo->url . '/goteo', timeout: 1.0, maxRetries: 0, transport: $crear());
        $inicio = microtime(true);
        $error = lanza(SeatsyooException::class, fn () => $seatsyoo->workspace->retrieve());
        igual('timeout', $error->getErrorCode());
        entre(0.8, 2.0, microtime(true) - $inicio, 'corta al segundo, sin esperar al final del goteo');
        $goteo->parar();
        // Con plazo de sobra, el goteo llega entero.
        $goteo = new ServidorLocal(__DIR__ . '/../receptor.php', ['SEATSYOO_GOTEO_MS' => '100', 'SEATSYOO_GOTEO_VECES' => '5']);
        $seatsyoo = new Client(secretKey: 'sk_x', server: $goteo->url . '/goteo', timeout: 5.0, maxRetries: 0, transport: $crear());
        igual(['goteo' => 'xxxxx'], $seatsyoo->workspace->retrieve());
        $goteo->parar();
    });

    prueba("$nombre: error de red contra un puerto cerrado", function () use ($crear): void {
        $seatsyoo = new Client(secretKey: 'sk_x', server: 'http://127.0.0.1:' . puertoLibre(), maxRetries: 0, transport: $crear());
        $error = lanza(SeatsyooException::class, fn () => $seatsyoo->workspace->retrieve());
        igual(0, $error->getStatus());
        igual('network-error', $error->getErrorCode());
        contiene('No se pudo conectar con Seatsyoo', $error->getMessage());
    });
}
