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

// Revisión de los SDK (25 oct 2026): el catch de «Vender» del README daba por «un 4xx, no se ha vendido nada» todo lo
// que no era 0 ni un 5xx, también un 2xx con invalid-response (la reserva pudo hacerse y perderse el cuerpo) o un 3xx,
// y llevaba a devolver el cobro de lo que quizá se vendió.
prueba('el catch de «Vender» del README: solo un 4xx no ha vendido nada; sin respuesta, un 2xx, un 3xx o un 5xx, no se sabe', function (): void {
    // fragmentoDelReadme() está en precios.php. La condición del if que lleva a «no se sabe si se vendió».
    $fragmento = fragmentoDelReadme('Vender');
    $aviso = strpos($fragmento, 'no se sabe si se vendió');
    cierto($aviso !== false, 'el catch dice cuándo no se sabe si se vendió');
    $inicio = strrpos(substr($fragmento, 0, (int) $aviso), 'if (');
    cierto($inicio !== false && preg_match('/\Gif \((.+)\) \{\n/', $fragmento, $si, 0, (int) $inicio) === 1, 'un if antes del aviso');
    contiene('// Un 4xx (entre 400 y 499): esta reserva no ha vendido nada', $fragmento);
    // Los errores de verdad que lanza book(), sin reintentos; true si el README dice que no se sabe si se vendió.
    $casos = [
        'un 200 que no es JSON' => [new Response(200, ['Content-Type' => 'text/html'], '<!doctype html>'), true],
        'un 200 cortado' => [new Response(200, ['Content-Type' => 'application/json'], '{"objects":["Platea-'), true],
        'una redirección' => [new Response(302, ['Location' => 'https://otro.example/'], ''), true],
        'un 502 de un proxy' => [new Response(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>'), true],
        'un 503 de la API' => [errorApi(503, 'unavailable'), true],
        'sin respuesta' => [agotado(), true],
        '409 objects-not-available' => [errorApi(409, 'objects-not-available'), false],
        '400 hold-token-expired' => [errorApi(400, 'hold-token-expired'), false],
        '403 production-locked' => [errorApi(403, 'production-locked'), false],
        '404 de un evento que ya no existe' => [errorApi(404, 'not-found'), false],
    ];
    foreach ($casos as $que => [$respuesta, $noSeSabe]) {
        $entorno = new Entorno([$respuesta], 0);
        $e = lanza(SeatsyooException::class, fn () => $entorno->events->book('hamlet', [['key' => 'Platea-C-1', 'quantity' => 1]], holdToken: 'hold_1', orderId: 'pedido-2001'));
        igual($noSeSabe, eval("return {$si[1]};"), $que);
    }
    // Y el resumen en inglés dice lo mismo.
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    $resumen = (string) preg_replace('/\s+/', ' ', substr($readme, (int) strpos($readme, "\n## English summary\n")));
    contiene("a 2xx with `invalid-response`, a 3xx with `http-error`), you can't tell whether it sold", $resumen);
    contiene('Any other 4xx (`getStatus()` from 400 to 499) sold nothing either', $resumen);
});

prueba('las rutas que no son JSON (CSV) devuelven el texto con request()', function (): void {
    $e = new Entorno([new Response(200, ['Content-Type' => 'text/csv; charset=utf-8'], "\u{FEFF}pedido,fecha\r\npedido-1,2026-10-16\r\n")]);
    $csv = $e->http->request('GET', '/events/hamlet/report/orders.csv');
    cierto(is_string($csv), 'es texto');
    contiene('pedido-1', $csv);
});

prueba('las respuestas JSON se devuelven como arrays asociativos', function (): void {
    $e = new Entorno([json(200, ['key' => 'hamlet', 'settings' => ['categoryOverrides' => ['Platea-A-1' => 'preferente']]]), json(200, ['occupancy' => 0.8])]);
    $evento = $e->events->retrieve('hamlet');
    igual('hamlet', $evento['key']);
    igual('preferente', $evento['settings']['categoryOverrides']['Platea-A-1']);
    igual(0.8, $e->http->request('GET', '/reports/events')['occupancy']);
});

prueba('los errores de los precios y del entorno de pruebas llegan con su código y no se reintentan', function (): void {
    $casos = [
        [400, 'pricing-not-supported', fn (Entorno $e) => $e->events->update('hamlet', ['pricing' => null])],
        [403, 'production-locked', fn (Entorno $e) => $e->events->list()],
        [409, 'production-only', fn (Entorno $e) => $e->workspace->createTestEnvironment()],
        [409, 'not-test-environment', fn (Entorno $e) => $e->workspace->reset()],
        [409, 'test-environment-missing', fn (Entorno $e) => $e->charts->copyToTest('teatro')],
    ];
    foreach ($casos as [$estado, $codigo, $llamada]) {
        $e = new Entorno([errorApi($estado, $codigo, 'Mensaje')]);
        $error = lanza(SeatsyooException::class, fn () => $llamada($e));
        igual([$estado, $codigo, null], [$error->getStatus(), $error->getErrorCode(), $error->getDetails()], $codigo);
        igual(1, $e->peticiones(), $codigo);
    }
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
