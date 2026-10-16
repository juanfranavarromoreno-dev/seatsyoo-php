<?php

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Http\Response;
use Seatsyoo\SeatsyooException;

grupo('Errores y respuestas');

prueba('un 409 con details: estado, código, mensaje y detalles', function (): void {
    $detalles = [['key' => 'Platea-C-1', 'reason' => 'booked'], ['key' => 'Pista', 'reason' => 'insufficient-capacity']];
    $e = new Entorno([errorApi(409, 'objects-not-available', 'Platea-C-1 ya está reservado', $detalles)]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->book('hamlet', ['Platea-C-1', 'Pista']));
    igual(409, $error->getStatus());
    igual(409, $error->getCode());
    igual('objects-not-available', $error->getErrorCode());
    igual('Platea-C-1 ya está reservado', $error->getMessage());
    igual($detalles, $error->getDetails());
    cierto($error instanceof \RuntimeException, 'es una RuntimeException');
});

prueba('sin details, getDetails() es null', function (): void {
    $e = new Entorno([errorApi(404, 'not-found', 'El evento no existe')]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->retrieve('nada'));
    igual('not-found', $error->getErrorCode());
    igual(null, $error->getDetails());
});

prueba('400 bad-request con la lista de problemas', function (): void {
    $problemas = [['path' => ['number'], 'message' => 'Invalid input']];
    $e = new Entorno([errorApi(400, 'bad-request', 'Petición no válida', $problemas)]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->bestAvailable('hamlet', 0));
    igual('bad-request', $error->getErrorCode());
    igual($problemas, $error->getDetails());
});

prueba('un error sin el cuerpo de la API (un proxy): código http-error', function (): void {
    $e = new Entorno([new Response(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>')], 0);
    $error = lanza(SeatsyooException::class, fn () => $e->workspace->retrieve());
    igual(502, $error->getStatus());
    igual('http-error', $error->getErrorCode());
    igual('Error 502 de Seatsyoo', $error->getMessage());
    $e = new Entorno([json(500, ['message' => 'sin code'])]);
    igual('http-error', lanza(SeatsyooException::class, fn () => $e->workspace->retrieve())->getErrorCode());
    $e = new Entorno([new Response(500, ['Content-Type' => 'application/json'], '{roto')]);
    igual('http-error', lanza(SeatsyooException::class, fn () => $e->workspace->retrieve())->getErrorCode());
});

prueba('las redirecciones no se siguen: son un error', function (): void {
    $e = new Entorno([new Response(302, ['Location' => 'https://otro.example/'], '')]);
    $error = lanza(SeatsyooException::class, fn () => $e->workspace->retrieve());
    igual(302, $error->getStatus());
    igual('http-error', $error->getErrorCode());
    igual(1, $e->peticiones());
});

prueba('un 200 que no es JSON válido: invalid-response', function (): void {
    $e = new Entorno([new Response(200, ['Content-Type' => 'application/json'], '{"a":')]);
    $error = lanza(SeatsyooException::class, fn () => $e->workspace->retrieve());
    igual('invalid-response', $error->getErrorCode());
    igual(200, $error->getStatus());
    // Una página web en lugar de la API (dirección del servidor equivocada).
    $e = new Entorno([new Response(200, ['Content-Type' => 'text/html'], '<!doctype html>')]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->list());
    igual('invalid-response', $error->getErrorCode());
    contiene('dirección del servidor', $error->getMessage());
});

prueba('las rutas que no son JSON (CSV) devuelven el texto con request()', function (): void {
    $e = new Entorno([new Response(200, ['Content-Type' => 'text/csv; charset=utf-8'], "\u{FEFF}pedido,fecha\r\npedido-1,2026-10-16\r\n")]);
    $csv = $e->http->request('GET', '/events/hamlet/report/orders.csv');
    cierto(is_string($csv), 'es texto');
    contiene('pedido-1', $csv);
});

prueba('las respuestas JSON se devuelven como arrays asociativos', function (): void {
    $e = new Entorno([json(200, ['key' => 'hamlet', 'settings' => ['pricing' => ['currency' => 'EUR', 'categories' => ['platea' => 30.5]]]])]);
    $evento = $e->events->retrieve('hamlet');
    igual('hamlet', $evento['key']);
    igual(30.5, $evento['settings']['pricing']['categories']['platea']);
});

prueba('una respuesta vacía es un array vacío', function (): void {
    $e = new Entorno([new Response(204, [], '')]);
    igual([], $e->events->delete('hamlet'));
});

prueba('sin respuesta: estado 0 (error de red y tiempo agotado)', function (): void {
    $e = new Entorno([sinRed()]);
    $error = lanza(SeatsyooException::class, fn () => $e->holdTokens->create());
    igual(0, $error->getStatus());
    igual(0, $error->getCode());
    igual('network-error', $error->getErrorCode());
    igual(null, $error->getDetails());
});
