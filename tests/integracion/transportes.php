<?php

/** Los dos transportes (curl y flujos de PHP) contra el servidor de Seatsyoo de pruebas. Los casos que solo necesitan PHP
 * (servidor lento, goteo, puerto cerrado) están en tests/unitarias/transportes.php. */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;

grupo('Integración: transportes');

$creado = false;
foreach (transportes() as $nombre => $crear) {
    prueba("$nombre: lecturas, POST sin cuerpo, JSON con tildes, errores con details y CSV", function () use ($crear, &$creado): void {
        $seatsyoo = new Client(
            secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'),
            server: (string) getenv('SEATSYOO_TEST_SERVER'),
            transport: $crear(),
        );
        if (!$creado) {
            $seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'transportes', 'name' => 'Transportes']);
            $creado = true;
        }
        igual('Pruebas PHP', $seatsyoo->workspace->retrieve()['name']);
        $token = $seatsyoo->holdTokens->create()['token'];
        $otro = $seatsyoo->holdTokens->create()['token'];
        igual('Función «ñ» €', $seatsyoo->events->update('transportes', ['name' => 'Función «ñ» €'])['name']);
        $libre = null;
        foreach (['Anfiteatro-E-1', 'Anfiteatro-E-2'] as $clave) {
            if (!isset($seatsyoo->events->statuses('transportes')['objects'][$clave])) {
                $libre = $clave;
                break;
            }
        }
        cierto($libre !== null, 'queda una butaca libre');
        $seatsyoo->events->hold('transportes', [(string) $libre], holdToken: $token);
        $error = lanza(SeatsyooException::class, fn () => $seatsyoo->events->hold('transportes', [(string) $libre], holdToken: $otro));
        igual(409, $error->getStatus());
        igual([['key' => $libre, 'reason' => 'held']], $error->getDetails());
        $error = lanza(SeatsyooException::class, fn () => $seatsyoo->events->retrieve('no existe'));
        igual(404, $error->getStatus());
        igual('not-found', $error->getErrorCode());
        $seatsyoo->events->book('transportes', [(string) $libre], holdToken: $token, orderId: 'transporte-' . $libre);
        $csv = $seatsyoo->request('GET', '/events/transportes/report/orders.csv');
        cierto(is_string($csv), 'CSV como texto');
        contiene('transporte-' . $libre, $csv);
    });
}
