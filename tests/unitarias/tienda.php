<?php

/**
 * La caja de ejemplo/tienda.php cuando la reserva falla con el pago ya cobrado, con respuestas preparadas (los casos
 * que no se pueden provocar a tiempo contra el servidor, como un token que caduca). El de una plaza que se lleva
 * otro está en las de integración (tests/integracion/ejemplo.php).
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

prueba('token caducado al confirmar el pago: relanza el error, queda por devolver y no se vuelve a reservar', function (): void {
    [$tienda, $transporte] = cajaCobrada([errorApi(400, 'hold-token-expired', 'El token de retención ha caducado')]);
    $error = lanza(SeatsyooException::class, fn () => $tienda->alConfirmarElPago('pedido-1'));
    igual([400, 'hold-token-expired'], [$error->getStatus(), $error->getErrorCode()]);
    // Reservó la lista guardada en la caja, con el token y el pedido.
    igual('{"objects":[{"key":"Platea-C-1","quantity":1}],"holdToken":"hold_1","orderId":"pedido-1"}', $transporte->ultima()['body']);
    igual(['motivo' => 'hold-token-expired', 'detalles' => null], $tienda->guardado('pedido-1')['porDevolver'] ?? null);
    // Un aviso repetido de la pasarela no llega a Seatsyoo: el cobro se devuelve, no se reserva después.
    $repetido = lanza(\RuntimeException::class, fn () => $tienda->alConfirmarElPago('pedido-1'));
    contiene('está por devolver', $repetido->getMessage());
    igual(3, count($transporte->peticiones));
});

prueba('una plaza que ya no está: el pedido guarda cuál y por qué', function (): void {
    $detalles = [['key' => 'Platea-C-1', 'reason' => 'booked']];
    [$tienda] = cajaCobrada([errorApi(409, 'objects-not-available', 'No disponible', $detalles)]);
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
