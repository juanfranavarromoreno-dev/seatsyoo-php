<?php

/**
 * Contra el servidor de pruebas, con curl y con los flujos de PHP: respuestas perdidas que el cliente repite
 * (reserva, devolución y borrado), un número de pedido vacío, las claves «.» y «..», y mapas vacíos en un
 * evento. Usa su propio evento, «repeticiones».
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\Http\Transport;
use Seatsyoo\SeatsyooException;

grupo('Integración: respuestas perdidas, claves raras y mapas vacíos');

const EVENTO_REP = 'repeticiones';

function clienteDePruebas(?Transport $transporte = null): Client
{
    return new Client(
        secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'),
        server: (string) getenv('SEATSYOO_TEST_SERVER'),
        transport: $transporte,
    );
}

prueba('mapas vacíos al crear y cambiar un evento (antes, 400 «expected record»)', function (): void {
    $seatsyoo = clienteDePruebas();
    $evento = $seatsyoo->events->create(['chartKey' => 'teatro', 'key' => EVENTO_REP, 'name' => 'Repeticiones', 'categoryOverrides' => [], 'tableModes' => []]);
    igual(EVENTO_REP, $evento['key']);
    $seatsyoo->events->update(EVENTO_REP, ['categoryOverridesChanges' => [], 'tableModesChanges' => [], 'categoryOverrides' => [], 'tableModes' => []]);
    $evento = $seatsyoo->events->update(EVENTO_REP, ['categoryOverridesChanges' => ['Platea-A-1' => 'preferente'], 'tableModesChanges' => ['M2' => 'whole']]);
    igual(['Platea-A-1' => 'preferente'], $evento['settings']['categoryOverrides']);
    igual(['M2' => 'whole'], $evento['settings']['tableModes']);
    $evento = $seatsyoo->events->update(EVENTO_REP, ['categoryOverrides' => [], 'tableModes' => []]);
    igual([], $evento['settings']['categoryOverrides']);
    igual([], $evento['settings']['tableModes']);
});

prueba('un número de pedido vacío no llega al servidor (ni vende dos veces en una zona de pie)', function (): void {
    $perdedor = new TransporteQuePierde(array_values(transportes())[0](), 'POST');
    $seatsyoo = clienteDePruebas($perdedor);
    lanza(\InvalidArgumentException::class, fn () => $seatsyoo->events->book(EVENTO_REP, [['key' => 'Pista', 'quantity' => 2]], orderId: ''));
    igual([], $perdedor->respuestas);
    cierto(!isset(clienteDePruebas()->events->statuses(EVENTO_REP)['objects']['Pista']), 'la pista sigue libre');
});

$filas = ['A', 'B'];
foreach (array_keys(transportes()) as $i => $nombre) {
    $crear = transportes()[$nombre];
    $butacas = ["Platea-{$filas[$i]}-1", "Platea-{$filas[$i]}-2"];
    $pedido = 'perdido-' . $filas[$i];

    prueba("$nombre: reserva con pedido cuya respuesta se pierde: devuelve lo vendido", function () use ($crear, $butacas, $pedido): void {
        $seatsyoo = clienteDePruebas();
        $token = $seatsyoo->holdTokens->create()['token'];
        $seatsyoo->events->hold(EVENTO_REP, $butacas, holdToken: $token);
        $perdedor = new TransporteQuePierde($crear(), 'POST');
        $r = clienteDePruebas($perdedor)->events->book(EVENTO_REP, [...$butacas, ['key' => 'Pista', 'quantity' => 2]], holdToken: $token, orderId: $pedido);
        igual(['POST 200', 'POST 200'], $perdedor->respuestas, 'el primer intento se hizo y se repitió');
        igual([...$butacas, 'Pista'], $r['objects']);
        igual(true, $r['retried']);
        igual(0, $r['seq']);
        $vendido = array_column($seatsyoo->events->retrieveOrder(EVENTO_REP, $pedido)['objects'], 'quantity', 'key');
        igual([$butacas[0] => 1, $butacas[1] => 1, 'Pista' => 2], $vendido);
    });

    prueba("$nombre: devolución entera cuya respuesta se pierde: retried y el pedido vacío", function () use ($crear, $pedido): void {
        $perdedor = new TransporteQuePierde($crear(), 'POST');
        $r = clienteDePruebas($perdedor)->events->refund(EVENTO_REP, $pedido);
        igual(['POST 200', 'POST 200'], $perdedor->respuestas);
        igual(['objects' => [], 'seq' => 0, 'retried' => true], $r);
        igual([], clienteDePruebas()->events->retrieveOrder(EVENTO_REP, $pedido)['objects']);
    });

    prueba("$nombre: borrar un evento cuya respuesta se pierde no da 404", function () use ($crear, $pedido): void {
        $seatsyoo = clienteDePruebas();
        $seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'borrar-' . $pedido]);
        $perdedor = new TransporteQuePierde($crear(), 'DELETE');
        igual(['ok' => true, 'retried' => true], clienteDePruebas($perdedor)->events->delete('borrar-' . $pedido));
        igual(['DELETE 200', 'DELETE 404'], $perdedor->respuestas);
        $error = lanza(SeatsyooException::class, fn () => $seatsyoo->events->retrieve('borrar-' . $pedido));
        igual('not-found', $error->getErrorCode());
    });
}

prueba('quitar un evento de una temporada dos veces no da error', function (): void {
    $seatsyoo = clienteDePruebas();
    $seatsyoo->seasons->create(['name' => 'Repetida', 'chartKey' => 'teatro', 'key' => 'repetida', 'eventKeys' => [EVENTO_REP]]);
    igual([], $seatsyoo->seasons->removeEvent('repetida', EVENTO_REP)['events']);
    igual([], $seatsyoo->seasons->removeEvent('repetida', EVENTO_REP)['events']);
    igual(['ok' => true], $seatsyoo->seasons->delete('repetida'));
});

prueba('pedidos «.» y «..» con cada transporte: no suben de carpeta', function (): void {
    $seatsyoo = clienteDePruebas();
    $seatsyoo->events->book(EVENTO_REP, ['Platea-H-1'], orderId: '.');
    $seatsyoo->events->book(EVENTO_REP, ['Platea-H-2', 'Platea-H-3'], orderId: '..');
    foreach (transportes() as $nombre => $crear) {
        $cliente = clienteDePruebas($crear());
        $punto = $cliente->events->retrieveOrder(EVENTO_REP, '.');
        igual('.', $punto['orderId'], $nombre);
        igual(['Platea-H-1'], array_column($punto['objects'], 'key'), $nombre);
        $dos = $cliente->events->retrieveOrder(EVENTO_REP, '..');
        igual('..', $dos['orderId'], $nombre);
        igual(['Platea-H-2', 'Platea-H-3'], array_column($dos['objects'], 'key'), $nombre);
    }
});
