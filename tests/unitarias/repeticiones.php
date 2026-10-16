<?php

/**
 * Lo que pasa cuando un intento llega al servidor pero su respuesta se pierde y el cliente lo repite: la
 * reserva y la devolución no parecen vacías, un pedido vacío no se reintenta y un borrado no da 404.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\SeatsyooException;

grupo('Reintentos: respuestas a una repetición');

/** Lo que responde el servidor a una reserva o devolución repetida que ya estaba hecha. */
const SIN_CAMBIOS = ['objects' => [], 'seq' => 0];

prueba('reserva con pedido repetida «sin cambios»: devuelve las claves pedidas y retried', function (): void {
    $e = new Entorno([agotado(), json(200, SIN_CAMBIOS)]);
    $r = $e->events->book('hamlet', ['Platea-C-5', ['key' => 'Pista', 'quantity' => 2], 'Platea-C-6', 'Platea-C-5'], holdToken: 'hold_1', orderId: 'pedido-77');
    igual(['objects' => ['Platea-C-5', 'Pista', 'Platea-C-6'], 'seq' => 0, 'retried' => true], $r);
    igual(2, $e->peticiones());
    // Las claves numéricas siguen siendo texto.
    $e = new Entorno([errorApi(502, 'http-error'), json(200, SIN_CAMBIOS)]);
    igual(['12', '7'], $e->events->book('hamlet', ['12', '7'], orderId: 'pedido-78')['objects']);
});

prueba('si el reintento sí reserva, la respuesta es la del servidor (con retried)', function (): void {
    $e = new Entorno([sinRed(), json(200, ['objects' => ['Platea-C-5'], 'seq' => 9])]);
    igual(['objects' => ['Platea-C-5'], 'seq' => 9, 'retried' => true], $e->events->book('hamlet', ['Platea-C-5'], orderId: 'pedido-79'));
});

prueba('sin reintento, la respuesta queda tal cual (sin retried), también «sin cambios»', function (): void {
    $e = new Entorno([json(200, SIN_CAMBIOS)]);
    igual(SIN_CAMBIOS, $e->events->book('hamlet', ['Platea-C-5'], orderId: 'pedido-80'));
    $e = new Entorno([json(200, SIN_CAMBIOS)]);
    igual(SIN_CAMBIOS, $e->events->refund('hamlet', 'pedido-80'));
});

prueba('devolución entera repetida: retried y objects vacío (ya estaba devuelta)', function (): void {
    $e = new Entorno([agotado(), json(200, SIN_CAMBIOS)]);
    igual(SIN_CAMBIOS + ['retried' => true], $e->events->refund('hamlet', 'pedido-77'));
});

prueba('temporadas: la reserva repetida trae las claves pedidas y conserva events', function (): void {
    $e = new Entorno([agotado(), json(200, SIN_CAMBIOS + ['events' => 3])]);
    $r = $e->seasons->book('abono', ['Anfiteatro-B-3', 'Anfiteatro-B-4'], orderId: 'abono-9');
    igual(['objects' => ['Anfiteatro-B-3', 'Anfiteatro-B-4'], 'seq' => 0, 'events' => 3, 'retried' => true], $r);
    $e = new Entorno([agotado(), json(200, SIN_CAMBIOS + ['events' => 3])]);
    igual(SIN_CAMBIOS + ['events' => 3, 'retried' => true], $e->seasons->refund('abono', 'abono-9'));
});

prueba('un número de pedido vacío es un error antes de llamar (no se reintentaría como idempotente)', function (): void {
    $llamadas = [
        'events.book' => fn (Entorno $e) => $e->events->book('hamlet', [['key' => 'Pista', 'quantity' => 2]], orderId: ''),
        'events.refund' => fn (Entorno $e) => $e->events->refund('hamlet', ''),
        'events.refund parcial' => fn (Entorno $e) => $e->events->refund('hamlet', '', ['Platea-C-1']),
        'events.changeStatus' => fn (Entorno $e) => $e->events->changeStatus('hamlet', ['Platea-C-1'], 'invitacion', orderId: ''),
        'events.bestAvailable' => fn (Entorno $e) => $e->events->bestAvailable('hamlet', 2, orderId: ''),
        'seasons.book' => fn (Entorno $e) => $e->seasons->book('abono', ['Platea-C-1'], orderId: ''),
        'seasons.refund' => fn (Entorno $e) => $e->seasons->refund('abono', ''),
        'seasons.changeStatus' => fn (Entorno $e) => $e->seasons->changeStatus('abono', ['Platea-C-1'], 'invitacion', orderId: ''),
        'seasons.bestAvailable' => fn (Entorno $e) => $e->seasons->bestAvailable('abono', 2, orderId: ''),
    ];
    foreach ($llamadas as $nombre => $llamada) {
        $e = new Entorno([]);
        $error = lanza(\InvalidArgumentException::class, fn () => $llamada($e));
        contiene('pedido', $error->getMessage(), $nombre);
        igual(0, $e->peticiones(), $nombre);
    }
    // null sigue siendo «sin pedido» (y entonces no se reintenta).
    $e = new Entorno([agotado()]);
    lanza(SeatsyooException::class, fn () => $e->events->book('hamlet', ['Platea-C-1'], holdToken: 'hold_1', orderId: null));
    igual(1, $e->peticiones());
});

prueba('un borrado repetido que da 404 not-found se da por hecho', function (): void {
    $borrados = [
        'events' => fn (Entorno $e) => $e->events->delete('hamlet'),
        'seasons' => fn (Entorno $e) => $e->seasons->delete('abono'),
        'webhooks' => fn (Entorno $e) => $e->webhooks->delete('wh_1'),
    ];
    foreach ($borrados as $nombre => $borrar) {
        $e = new Entorno([agotado(), errorApi(404, 'not-found', 'No existe')]);
        igual(['ok' => true, 'retried' => true], $borrar($e), $nombre);
        igual(2, $e->peticiones(), $nombre);
        igual('DELETE', $e->transporte->ultima()['method'], $nombre);
    }
});

prueba('un 404 al primer intento, o uno que no es not-found, sigue siendo un error', function (): void {
    $e = new Entorno([errorApi(404, 'not-found', 'El evento no existe')]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->delete('hamlet'));
    igual('not-found', $error->getErrorCode());
    igual(1, $e->peticiones());
    // Un 404 sin el error de la API (un proxy, una dirección mal puesta) no se da por bueno.
    $e = new Entorno([agotado(), new \Seatsyoo\Http\Response(404, ['Content-Type' => 'text/html'], '<h1>404</h1>')]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->delete('hamlet'));
    igual('http-error', $error->getErrorCode());
    // Otros errores de un reintento, tampoco.
    $e = new Entorno([agotado(), errorApi(409, 'event-has-sales')]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->delete('hamlet'));
    igual('event-has-sales', $error->getErrorCode());
    // Sin reintentos (maxRetries 0) no hay repetición que valga.
    $e = new Entorno([agotado()], 0);
    $error = lanza(SeatsyooException::class, fn () => $e->events->delete('hamlet'));
    igual('timeout', $error->getErrorCode());
});

prueba('quitar un evento de una temporada no cambia: un 404 ahí es un error de verdad', function (): void {
    $e = new Entorno([agotado(), errorApi(404, 'not-found', 'El evento no existe')]);
    $error = lanza(SeatsyooException::class, fn () => $e->seasons->removeEvent('abono', 'hamlet'));
    igual('not-found', $error->getErrorCode());
    igual(2, $e->peticiones());
});
