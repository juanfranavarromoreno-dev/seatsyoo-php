<?php

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\Http\TransportException;
use Seatsyoo\SeatsyooException;

grupo('Reintentos');

const BIEN = ['objects' => ['Platea-C-1'], 'seq' => 3];

prueba('un GET se reintenta tras un 503 y devuelve la respuesta buena', function (): void {
    $e = new Entorno([errorApi(503, 'internal'), json(200, ['events' => []])]);
    igual(['events' => []], $e->events->list());
    igual(2, $e->peticiones());
    igual(1, count($e->esperas));
});

prueba('la espera crece de forma exponencial y lleva azar (entre la mitad y el total)', function (): void {
    $alto = new Entorno([errorApi(503, 'x'), errorApi(502, 'x'), json(200)], 2, 1.0);
    $alto->workspace->retrieve();
    igual([0.5, 1.0], $alto->esperas);
    $bajo = new Entorno([errorApi(504, 'x'), errorApi(503, 'x'), json(200)], 2, 0.0);
    $bajo->workspace->retrieve();
    igual([0.25, 0.5], $bajo->esperas);
    // Con muchos reintentos, la espera tiene un tope de 8 s.
    $respuestas = array_merge(array_fill(0, 7, errorApi(503, 'x')), [json(200)]);
    $muchos = new Entorno($respuestas, 7, 1.0);
    $muchos->workspace->retrieve();
    igual([0.5, 1.0, 2.0, 4.0, 8.0, 8.0, 8.0], $muchos->esperas);
});

prueba('se rinde tras maxRetries reintentos y lanza el último error', function (): void {
    $e = new Entorno([errorApi(503, 'a'), errorApi(503, 'b'), errorApi(503, 'c', 'El último')]);
    $error = lanza(SeatsyooException::class, fn () => $e->workspace->retrieve());
    igual(503, $error->getStatus());
    igual('c', $error->getErrorCode());
    igual('El último', $error->getMessage());
    igual(3, $e->peticiones());
    $sin = new Entorno([errorApi(503, 'x')], 0);
    lanza(SeatsyooException::class, fn () => $sin->workspace->retrieve());
    igual(1, $sin->peticiones());
    igual([], $sin->esperas);
});

prueba('reintenta 502, 503 y 504; no 500, 400, 401, 404 ni 409', function (): void {
    foreach ([502, 503, 504] as $status) {
        $e = new Entorno([errorApi($status, 'x'), json(200)]);
        $e->charts->list();
        igual(2, $e->peticiones(), "HTTP $status");
    }
    foreach ([500 => 'internal', 400 => 'bad-request', 401 => 'unauthorized', 404 => 'not-found', 409 => 'key-taken'] as $status => $codigo) {
        $e = new Entorno([errorApi($status, $codigo)]);
        $error = lanza(SeatsyooException::class, fn () => $e->charts->list());
        igual($codigo, $error->getErrorCode());
        igual(1, $e->peticiones(), "HTTP $status");
    }
});

prueba('un 429 espera los segundos de Retry-After', function (): void {
    $e = new Entorno([errorApi(429, 'rate-limited', 'Demasiadas', null, ['Retry-After' => '7']), json(200, ['seq' => 0, 'objects' => []])]);
    $e->events->statuses('hamlet');
    igual([7.0], $e->esperas);
    igual(2, $e->peticiones());
});

prueba('Retry-After tiene un tope de 60 s', function (): void {
    $e = new Entorno([errorApi(429, 'rate-limited', 'x', null, ['Retry-After' => '3600']), json(200)]);
    $e->workspace->retrieve();
    igual([60.0], $e->esperas);
});

prueba('Retry-After como fecha HTTP', function (): void {
    $fecha = gmdate('D, d M Y H:i:s \G\M\T', time() + 20);
    $e = new Entorno([errorApi(429, 'rate-limited', 'x', null, ['Retry-After' => $fecha]), json(200)]);
    $e->workspace->retrieve();
    entre(18.0, 20.0, $e->esperas[0]);
});

prueba('un 429 sin Retry-After espera como los demás', function (): void {
    $e = new Entorno([errorApi(429, 'rate-limited'), json(200)], 2, 1.0);
    $e->workspace->retrieve();
    igual([0.5], $e->esperas);
});

prueba('la cuota diaria agotada (429 quota-exceeded) no se reintenta', function (): void {
    $e = new Entorno([errorApi(429, 'quota-exceeded', 'Cuota', ['limit' => 1000])]);
    $error = lanza(SeatsyooException::class, fn () => $e->workspace->retrieve());
    igual('quota-exceeded', $error->getErrorCode());
    igual(['limit' => 1000], $error->getDetails());
    igual(1, $e->peticiones());
});

prueba('un GET se reintenta tras un error de red y tras un tiempo agotado', function (): void {
    $e = new Entorno([sinRed(), agotado(), json(200, ['name' => 'Teatro'])]);
    igual('Teatro', $e->workspace->retrieve()['name']);
    igual(3, $e->peticiones());
});

prueba('sin respuesta tras los reintentos: estado 0 y código timeout o network-error', function (): void {
    $e = new Entorno([agotado(), agotado(), agotado()]);
    $error = lanza(SeatsyooException::class, fn () => $e->workspace->retrieve());
    igual(0, $error->getStatus());
    igual('timeout', $error->getErrorCode());
    contiene('30 s', $error->getMessage());
    cierto($error->getPrevious() instanceof TransportException, 'guarda el error del transporte');
    $e = new Entorno([sinRed(), sinRed(), sinRed()]);
    $error = lanza(SeatsyooException::class, fn () => $e->workspace->retrieve());
    igual(0, $error->getStatus());
    igual('network-error', $error->getErrorCode());
    contiene('Could not connect', $error->getMessage());
});

prueba('PUT y DELETE se reintentan; PATCH no', function (): void {
    $e = new Entorno([errorApi(503, 'x'), json(200, ['ok' => true])]);
    igual(['ok' => true], $e->events->delete('hamlet'));
    igual(2, $e->peticiones());
    igual('DELETE', $e->transporte->ultima()['method']);
    $e = new Entorno([errorApi(503, 'x'), json(200, ['ok' => true])]);
    $e->http->request('PUT', '/events/hamlet/channels', ['channels' => []]);
    igual(2, $e->peticiones());
    $e = new Entorno([errorApi(503, 'x')]);
    lanza(SeatsyooException::class, fn () => $e->events->update('hamlet', ['name' => 'Otro']));
    igual(1, $e->peticiones());
});

prueba('retener no se reintenta (ni tras un 503 ni tras un error de red)', function (): void {
    $e = new Entorno([errorApi(503, 'x')]);
    lanza(SeatsyooException::class, fn () => $e->events->hold('hamlet', ['Platea-C-1'], 'hold_1'));
    igual(1, $e->peticiones());
    $e = new Entorno([sinRed()]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->hold('hamlet', ['Platea-C-1'], 'hold_1'));
    igual('network-error', $error->getErrorCode());
    igual(1, $e->peticiones());
});

prueba('reservar sin número de pedido no se reintenta', function (): void {
    $e = new Entorno([agotado()]);
    $error = lanza(SeatsyooException::class, fn () => $e->events->book('hamlet', ['Platea-C-1'], holdToken: 'hold_1'));
    igual('timeout', $error->getErrorCode());
    igual(1, $e->peticiones());
});

prueba('reservar con número de pedido se reintenta con la misma petición', function (): void {
    $e = new Entorno([agotado(), errorApi(503, 'x'), json(200, BIEN)]);
    igual(BIEN, $e->events->book('hamlet', ['Platea-C-1'], holdToken: 'hold_1', orderId: 'pedido-2001'));
    igual(3, $e->peticiones());
    $cuerpos = array_unique(array_column($e->transporte->peticiones, 'body'));
    igual(['{"objects":["Platea-C-1"],"holdToken":"hold_1","orderId":"pedido-2001"}'], array_values($cuerpos));
});

prueba('devolver un pedido entero se reintenta; una parte, no', function (): void {
    $e = new Entorno([sinRed(), json(200, BIEN)]);
    $e->events->refund('hamlet', 'pedido-2001');
    igual(2, $e->peticiones());
    $e = new Entorno([errorApi(503, 'x')]);
    lanza(SeatsyooException::class, fn () => $e->events->refund('hamlet', 'pedido-2001', ['Platea-C-1']));
    igual(1, $e->peticiones());
});

prueba('las demás acciones no se reintentan', function (): void {
    $acciones = [
        'release' => fn (Entorno $e) => $e->events->release('hamlet', ['Platea-C-1'], 'hold_1'),
        'changeStatus' => fn (Entorno $e) => $e->events->changeStatus('hamlet', ['Platea-C-1'], 'invitacion', orderId: 'prensa-1'),
        'bestAvailable con pedido' => fn (Entorno $e) => $e->events->bestAvailable('hamlet', 2, orderId: 'pedido-9'),
        'markNotForSale' => fn (Entorno $e) => $e->events->markNotForSale('hamlet', ['Platea-C-1']),
        'holdTokens.create' => fn (Entorno $e) => $e->holdTokens->create(),
        'holdTokens.extend' => fn (Entorno $e) => $e->holdTokens->extend('hold_1', 600),
        'webhooks.create' => fn (Entorno $e) => $e->webhooks->create(['url' => 'https://mitienda.example/avisos']),
        'events.create' => fn (Entorno $e) => $e->events->create(['chartKey' => 'teatro']),
    ];
    foreach ($acciones as $nombre => $accion) {
        $e = new Entorno([errorApi(503, 'x')]);
        lanza(SeatsyooException::class, fn () => $accion($e));
        igual(1, $e->peticiones(), $nombre);
    }
});

prueba('temporadas: reservar con pedido y devolver entero se reintentan; lo demás no', function (): void {
    $e = new Entorno([errorApi(502, 'x'), json(200, BIEN + ['events' => 3])]);
    $e->seasons->book('abono', ['Platea-C-1'], orderId: 'abono-1');
    igual(2, $e->peticiones());
    $e = new Entorno([agotado(), json(200, BIEN + ['events' => 3])]);
    $e->seasons->refund('abono', 'abono-1');
    igual(2, $e->peticiones());
    $e = new Entorno([errorApi(503, 'x')]);
    lanza(SeatsyooException::class, fn () => $e->seasons->refund('abono', 'abono-1', ['Platea-C-1']));
    igual(1, $e->peticiones());
    $e = new Entorno([errorApi(503, 'x')]);
    lanza(SeatsyooException::class, fn () => $e->seasons->book('abono', ['Platea-C-1'], holdToken: 'hold_1'));
    igual(1, $e->peticiones());
    $e = new Entorno([errorApi(503, 'x'), json(200, ['seq' => 0, 'objects' => []])]);
    $e->seasons->statuses('abono');
    igual(2, $e->peticiones());
});

prueba('Client::request: GET se reintenta, POST no', function (): void {
    $transporte = new TransporteFalso([errorApi(503, 'x'), json(200, ['total' => []])]);
    $cliente = new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', transport: $transporte, maxRetries: 1);
    // La espera de verdad es corta (entre 0,25 y 0,5 s).
    igual(['total' => []], $cliente->request('GET', '/events/hamlet/report/summary'));
    igual(2, count($transporte->peticiones));
    $transporte = new TransporteFalso([errorApi(503, 'x')]);
    $cliente = new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', transport: $transporte);
    lanza(SeatsyooException::class, fn () => $cliente->request('POST', '/charts/teatro/publish'));
    igual(1, count($transporte->peticiones));
});
