<?php

/**
 * Contra el servidor de pruebas: los ajustes del espacio (las plazas por comprador del visor) y su entorno de
 * pruebas, con la clave sk_test_… que prepara tests/ejecutar.mjs: crearlo, copiarle un plano, vender en él y
 * vaciarlo sin tocar producción.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;

grupo('Integración: el espacio y su entorno de pruebas');

function clienteDeProduccion(): Client
{
    return new Client(secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'), server: (string) getenv('SEATSYOO_TEST_SERVER'));
}

function clienteDelEntornoDePruebas(): Client
{
    return new Client(secretKey: (string) getenv('SEATSYOO_TEST_TEST_SECRET_KEY'), server: (string) getenv('SEATSYOO_TEST_SERVER'));
}

prueba('cambiar las plazas por comprador (maxHoldPerToken) y otros ajustes del espacio', function (): void {
    $seatsyoo = clienteDeProduccion();
    $espacio = $seatsyoo->workspace->update(['maxHoldPerToken' => 20, 'holdPeriodSeconds' => 600, 'appearance' => []]);
    igual(20, $espacio['maxHoldPerToken']);
    igual(600, $espacio['holdPeriodSeconds']);
    igual(20, $seatsyoo->workspace->retrieve()['maxHoldPerToken']);
    // Fuera de margen (de 1 a 500): 400 y no cambia nada.
    foreach ([0, 501] as $fuera) {
        $error = lanza(SeatsyooException::class, fn () => $seatsyoo->workspace->update(['maxHoldPerToken' => $fuera]));
        igual(400, $error->getStatus());
        igual('bad-request', $error->getErrorCode());
    }
    igual(20, $seatsyoo->workspace->retrieve()['maxHoldPerToken']);
    // Como estaba, para las demás pruebas.
    $espacio = $seatsyoo->workspace->update(['maxHoldPerToken' => 10, 'holdPeriodSeconds' => 900]);
    igual([10, 900], [$espacio['maxHoldPerToken'], $espacio['holdPeriodSeconds']]);
});

prueba('el entorno de pruebas ya existe: createTestEnvironment() da su clave pública, sin clave secreta', function (): void {
    $entorno = clienteDeProduccion()->workspace->createTestEnvironment();
    igual(getenv('SEATSYOO_TEST_TEST_PUBLIC_KEY'), $entorno['publicKey']);
    cierto(strncmp($entorno['publicKey'], 'pk_test_', 8) === 0, 'empieza por pk_test_');
    cierto(!array_key_exists('secretKey', $entorno), 'sin clave secreta: solo se ve al crearlo');
    // Con la clave sk_test_…, el espacio es el de pruebas y lo de producción se hace en producción.
    $pruebas = clienteDelEntornoDePruebas();
    $espacio = $pruebas->workspace->retrieve();
    igual(true, $espacio['test']);
    igual(getenv('SEATSYOO_TEST_PUBLIC_KEY'), $espacio['production']['publicKey']);
    $error = lanza(SeatsyooException::class, fn () => $pruebas->workspace->createTestEnvironment());
    igual(409, $error->getStatus());
    igual('production-only', $error->getErrorCode());
    $error = lanza(SeatsyooException::class, fn () => $pruebas->workspace->update(['name' => 'Otro']));
    igual('production-only', $error->getErrorCode());
});

prueba('copiar un plano a pruebas, vender allí y vaciar el entorno sin tocar producción', function (): void {
    $produccion = clienteDeProduccion();
    $pruebas = clienteDelEntornoDePruebas();
    igual([], $pruebas->charts->list()['charts']);
    $copia = $produccion->charts->copyToTest('teatro');
    igual('teatro', $copia['key']);
    igual('published', $copia['status']);
    igual(false, $copia['replaced']);
    igual(true, $produccion->charts->copyToTest('teatro')['replaced']);
    $error = lanza(SeatsyooException::class, fn () => $pruebas->charts->copyToTest('teatro'));
    igual('production-only', $error->getErrorCode());
    $error = lanza(SeatsyooException::class, fn () => $produccion->charts->copyToTest('no-existe'));
    igual(404, $error->getStatus());

    // En pruebas, la misma clave de plano que en producción.
    $pruebas->events->create(['chartKey' => 'teatro', 'key' => 'ensayo', 'name' => 'Ensayo']);
    $pruebas->events->book('ensayo', ['Platea-A-1', ['key' => 'Pista', 'quantity' => 2]], orderId: 'pedido-de-prueba');
    igual(['Pista', 'Platea-A-1'], ordenadas(array_column($pruebas->events->retrieveOrder('ensayo', 'pedido-de-prueba')['objects'], 'key')));
    $pruebas->seasons->create(['chartKey' => 'teatro', 'key' => 'abono-de-prueba', 'eventKeys' => ['ensayo']]);
    // Producción no ve nada de pruebas.
    lanza(SeatsyooException::class, fn () => $produccion->events->retrieve('ensayo'));

    // Con una clave de producción no se vacía nada.
    $error = lanza(SeatsyooException::class, fn () => $produccion->workspace->reset());
    igual(409, $error->getStatus());
    igual('not-test-environment', $error->getErrorCode());
    igual('hamlet', $produccion->events->retrieve((string) getenv('SEATSYOO_TEST_EVENT'))['key']);

    igual(['ok' => true, 'deleted' => ['events' => 1, 'seasons' => 1]], $pruebas->workspace->reset());
    igual([], $pruebas->events->list()['events']);
    igual([], $pruebas->seasons->list()['seasons']);
    igual(['teatro'], array_column($pruebas->charts->list()['charts'], 'key'), 'los planos se quedan');
    igual(['ok' => true, 'deleted' => ['events' => 0, 'seasons' => 0]], $pruebas->workspace->reset());
});
