<?php

/**
 * La caja de ejemplo/tienda.php cuando la reserva falla con el pago ya cobrado o el aviso del pago se repite, con
 * respuestas preparadas (los casos que no se pueden provocar a tiempo contra el servidor, como un token que caduca).
 * Los de una plaza que se lleva otro y una devolución entre dos avisos están en las de integración
 * (tests/integracion/ejemplo.php).
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;

grupo('La caja del ejemplo (ejemplo/tienda.php)');

/**
 * Una caja con el transporte falso, que ya ha cobrado Platea-C-1 (30) con el token hold_1 en el pedido pedido-1. Las
 * respuestas que se le pasan son las de después de la caja.
 *
 * @param list<\Seatsyoo\Http\Response|\Throwable> $despues
 *
 * @return array{0: \Tienda, 1: TransporteFalso}
 */
function cajaCobrada(array $despues): array
{
    require_once __DIR__ . '/../../ejemplo/tienda.php';
    $transporte = new TransporteFalso(array_merge([
        json(200, ['seq' => 3, 'objects' => ['Platea-C-1' => ['s' => 'held', 'mine' => true], 'Platea-C-9' => ['s' => 'booked']]]),
        json(200, ['key' => 'Platea-C-1', 'kind' => 'seat', 'category' => 'platea', 'capacity' => 1]),
    ], $despues));
    // Sin reintentos: así una petición sin respuesta falla a la primera, sin esperar.
    $tienda = new \Tienda(new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', maxRetries: 0, transport: $transporte), ['platea' => 30]);
    igual(30.0, $tienda->alPasarPorCaja('pedido-1', 'hamlet', 'hold_1'));

    return [$tienda, $transporte];
}

/** Lo que responde retrieveOrder() para pedido-1 cuando no tiene nada vendido. */
function pedidoVacio(): \Seatsyoo\Http\Response
{
    return json(200, ['orderId' => 'pedido-1', 'objects' => []]);
}

prueba('token caducado al confirmar el pago: relanza el error, queda por devolver y no se vuelve a reservar', function (): void {
    [$tienda, $transporte] = cajaCobrada([errorApi(400, 'hold-token-expired', 'El token de retención ha caducado'), pedidoVacio()]);
    $error = lanza(SeatsyooException::class, fn () => $tienda->alConfirmarElPago('pedido-1'));
    igual([400, 'hold-token-expired'], [$error->getStatus(), $error->getErrorCode()]);
    // Reservó la lista guardada en la caja, con el token y el pedido, y antes de darlo por perdido miró el pedido.
    igual('{"objects":[{"key":"Platea-C-1","quantity":1}],"holdToken":"hold_1","orderId":"pedido-1"}', $transporte->peticiones[2]['body']);
    igual(['GET', '/orders/pedido-1'], [$transporte->ultima()['method'], substr($transporte->ruta(), -16)]);
    igual(['motivo' => 'hold-token-expired', 'detalles' => null], $tienda->guardado('pedido-1')['porDevolver'] ?? null);
    // Un aviso repetido de la pasarela no llega a Seatsyoo: el cobro se devuelve, no se reserva después.
    $repetido = lanza(\RuntimeException::class, fn () => $tienda->alConfirmarElPago('pedido-1'));
    contiene('está por devolver', $repetido->getMessage());
    igual(4, count($transporte->peticiones));
});

prueba('una plaza que ya no está: el pedido guarda cuál y por qué', function (): void {
    $detalles = [['key' => 'Platea-C-1', 'reason' => 'booked']];
    [$tienda] = cajaCobrada([errorApi(409, 'objects-not-available', 'No disponible', $detalles), pedidoVacio()]);
    lanza(SeatsyooException::class, fn () => $tienda->alConfirmarElPago('pedido-1'));
    igual(['motivo' => 'objects-not-available', 'detalles' => $detalles], $tienda->guardado('pedido-1')['porDevolver'] ?? null);
});

prueba('sin respuesta al reservar no queda por devolver: se repite con el mismo pedido y vende una vez', function (): void {
    [$tienda, $transporte] = cajaCobrada([agotado(), json(200, ['objects' => ['Platea-C-1'], 'seq' => 4])]);
    $error = lanza(SeatsyooException::class, fn () => $tienda->alConfirmarElPago('pedido-1'));
    igual('timeout', $error->getErrorCode());
    cierto(!isset($tienda->guardado('pedido-1')['porDevolver']), 'no se sabe si se vendió: no queda por devolver');
    igual(['objects' => ['Platea-C-1'], 'seq' => 4], $tienda->alConfirmarElPago('pedido-1'));
    igual($transporte->peticiones[2]['body'], $transporte->peticiones[3]['body'], 'la misma reserva, con el mismo pedido');
});

prueba('otro error de la API (un 500) tampoco queda por devolver', function (): void {
    [$tienda] = cajaCobrada([errorApi(500, 'internal-error')]);
    lanza(SeatsyooException::class, fn () => $tienda->alConfirmarElPago('pedido-1'));
    cierto(!isset($tienda->guardado('pedido-1')['porDevolver']), 'sin porDevolver');
});

prueba('ya reservado: un aviso repetido devuelve lo que respondió la reserva sin llamar a book()', function (): void {
    // Regresión: cada aviso repetía book() con el mismo pedido, que tras una devolución parcial vuelve a vender lo
    // devuelto si sigue libre y el token vive.
    [$tienda, $transporte] = cajaCobrada([json(200, ['objects' => ['Platea-C-1'], 'seq' => 4])]);
    $reserva = $tienda->alConfirmarElPago('pedido-1');
    igual(['objects' => ['Platea-C-1'], 'seq' => 4], $reserva);
    igual($reserva, $tienda->guardado('pedido-1')['reservado'] ?? null);
    igual($reserva, $tienda->alConfirmarElPago('pedido-1'));
    igual(3, count($transporte->peticiones), 'ni book() ni nada más');
    // Y ya no vuelve a la caja: lo guardado (y lo reservado) no se pisa.
    $caja = lanza(\RuntimeException::class, fn () => $tienda->alPasarPorCaja('pedido-1', 'hamlet', 'hold_1'));
    contiene('ya se cobró', $caja->getMessage());
    igual(3, count($transporte->peticiones));
});

prueba('token caducado tras una devolución parcial: el pedido ya tenía plazas y no queda por devolver', function (): void {
    // La primera reserva se hizo pero su respuesta no llegó; después se devolvió Platea-C-2 y el token caducó. El
    // aviso repetido da hold-token-expired, pero el pedido tiene Platea-C-1 vendida: no hay que devolver el cobro.
    require_once __DIR__ . '/../../ejemplo/tienda.php';
    $transporte = new TransporteFalso([
        json(200, ['seq' => 3, 'objects' => ['Platea-C-1' => ['s' => 'held', 'mine' => true], 'Platea-C-2' => ['s' => 'held', 'mine' => true]]]),
        json(200, ['key' => 'Platea-C-1', 'kind' => 'seat', 'category' => 'platea', 'capacity' => 1]),
        json(200, ['key' => 'Platea-C-2', 'kind' => 'seat', 'category' => 'platea', 'capacity' => 1]),
        agotado(),
        errorApi(400, 'hold-token-expired', 'El token de retención ha caducado'),
        json(200, ['orderId' => 'pedido-2', 'objects' => [['key' => 'Platea-C-1', 'quantity' => 1, 'status' => 'booked']]]),
    ]);
    $tienda = new \Tienda(new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', maxRetries: 0, transport: $transporte), ['platea' => 30]);
    igual(60.0, $tienda->alPasarPorCaja('pedido-2', 'hamlet', 'hold_2'));
    igual('timeout', lanza(SeatsyooException::class, fn () => $tienda->alConfirmarElPago('pedido-2'))->getErrorCode());

    $reserva = $tienda->alConfirmarElPago('pedido-2');
    igual(['objects' => ['Platea-C-1'], 'seq' => 0, 'retried' => true], $reserva, 'reservado con lo que tiene el pedido');
    cierto(!isset($tienda->guardado('pedido-2')['porDevolver']), 'no queda por devolver');
    // Ya reservado: el siguiente aviso no vuelve a Seatsyoo.
    igual($reserva, $tienda->alConfirmarElPago('pedido-2'));
    igual(6, count($transporte->peticiones));
});
