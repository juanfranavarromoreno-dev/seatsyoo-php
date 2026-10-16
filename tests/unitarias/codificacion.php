<?php

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;

grupo('Rutas, claves y cuerpos');

const RESPUESTA = ['objects' => [], 'seq' => 0];

prueba('las claves de la ruta van codificadas para URL', function (): void {
    $e = new Entorno([json(200), json(200), json(200)]);
    $e->events->retrieveObject('Función 1', 'Palco 1');
    igual('/events/Funci%C3%B3n%201/objects/Palco%201', $e->transporte->ruta());
    $e->events->retrieveOrder('hamlet', 'pedido/2001?a=b#c');
    igual('/events/hamlet/orders/pedido%2F2001%3Fa%3Db%23c', $e->transporte->ruta());
    $e->events->retrieve('100%+más');
    igual('/events/100%25%2Bm%C3%A1s', $e->transporte->ruta());
});

prueba('«.» y «..» como clave no suben de carpeta', function (): void {
    $e = new Entorno([json(200), json(200)]);
    $e->events->retrieve('.');
    igual('/events/%2E', $e->transporte->ruta());
    $e->events->retrieveObject('..', '..');
    igual('/events/%2E%2E/objects/%2E%2E', $e->transporte->ruta());
});

prueba('una clave vacía es un error antes de llamar', function (): void {
    $e = new Entorno([]);
    lanza(\InvalidArgumentException::class, fn () => $e->events->retrieve(''));
    lanza(\InvalidArgumentException::class, fn () => $e->events->retrieveOrder('hamlet', ''));
    lanza(\InvalidArgumentException::class, fn () => $e->holdTokens->retrieve(''));
    igual(0, $e->peticiones());
});

prueba('cada acción va a su ruta con su método', function (): void {
    $casos = [
        ['POST', '/events/hamlet/actions/hold', fn (Entorno $e) => $e->events->hold('hamlet', ['A'], 'hold_1')],
        ['POST', '/events/hamlet/actions/book', fn (Entorno $e) => $e->events->book('hamlet', ['A'])],
        ['POST', '/events/hamlet/actions/release', fn (Entorno $e) => $e->events->release('hamlet', ['A'])],
        ['POST', '/events/hamlet/actions/release', fn (Entorno $e) => $e->events->refund('hamlet', 'p-1')],
        ['POST', '/events/hamlet/actions/change-status', fn (Entorno $e) => $e->events->changeStatus('hamlet', ['A'], 'booked')],
        ['POST', '/events/hamlet/actions/best-available', fn (Entorno $e) => $e->events->bestAvailable('hamlet', 2)],
        ['POST', '/events/hamlet/not-for-sale', fn (Entorno $e) => $e->events->markNotForSale('hamlet', ['A'])],
        ['POST', '/events/hamlet/for-sale', fn (Entorno $e) => $e->events->markForSale('hamlet')],
        ['GET', '/events/hamlet/statuses', fn (Entorno $e) => $e->events->statuses('hamlet')],
        ['GET', '/events/hamlet/status-changes', fn (Entorno $e) => $e->events->statusChanges('hamlet')],
        ['GET', '/events', fn (Entorno $e) => $e->events->list()],
        ['POST', '/events', fn (Entorno $e) => $e->events->create(['chartKey' => 'teatro'])],
        ['GET', '/events/hamlet', fn (Entorno $e) => $e->events->retrieve('hamlet')],
        ['PATCH', '/events/hamlet', fn (Entorno $e) => $e->events->update('hamlet', ['name' => 'Hamlet'])],
        ['DELETE', '/events/hamlet', fn (Entorno $e) => $e->events->delete('hamlet')],
        ['POST', '/hold-tokens', fn (Entorno $e) => $e->holdTokens->create()],
        ['GET', '/hold-tokens/hold_1', fn (Entorno $e) => $e->holdTokens->retrieve('hold_1')],
        ['POST', '/hold-tokens/hold_1/extend', fn (Entorno $e) => $e->holdTokens->extend('hold_1')],
        ['GET', '/seasons', fn (Entorno $e) => $e->seasons->list()],
        ['POST', '/seasons', fn (Entorno $e) => $e->seasons->create(['name' => 'Abono', 'chartKey' => 'teatro'])],
        ['GET', '/seasons/abono', fn (Entorno $e) => $e->seasons->retrieve('abono')],
        ['PATCH', '/seasons/abono', fn (Entorno $e) => $e->seasons->update('abono', ['pick' => null])],
        ['DELETE', '/seasons/abono', fn (Entorno $e) => $e->seasons->delete('abono')],
        ['POST', '/seasons/abono/events', fn (Entorno $e) => $e->seasons->addEvents('abono', ['hamlet'])],
        ['DELETE', '/seasons/abono/events/hamlet', fn (Entorno $e) => $e->seasons->removeEvent('abono', 'hamlet')],
        ['GET', '/seasons/abono/statuses', fn (Entorno $e) => $e->seasons->statuses('abono')],
        ['POST', '/seasons/abono/actions/hold', fn (Entorno $e) => $e->seasons->hold('abono', ['A'], 'hold_1')],
        ['POST', '/seasons/abono/actions/book', fn (Entorno $e) => $e->seasons->book('abono', ['A'])],
        ['POST', '/seasons/abono/actions/release', fn (Entorno $e) => $e->seasons->release('abono', ['A'])],
        ['POST', '/seasons/abono/actions/release', fn (Entorno $e) => $e->seasons->refund('abono', 'p-1')],
        ['POST', '/seasons/abono/actions/change-status', fn (Entorno $e) => $e->seasons->changeStatus('abono', ['A'], 'booked')],
        ['POST', '/seasons/abono/actions/best-available', fn (Entorno $e) => $e->seasons->bestAvailable('abono', 2)],
        ['GET', '/charts', fn (Entorno $e) => $e->charts->list()],
        ['GET', '/charts/teatro', fn (Entorno $e) => $e->charts->retrieve('teatro')],
        ['GET', '/webhooks', fn (Entorno $e) => $e->webhooks->list()],
        ['POST', '/webhooks', fn (Entorno $e) => $e->webhooks->create(['url' => 'https://mitienda.example/avisos'])],
        ['PATCH', '/webhooks/wh_1', fn (Entorno $e) => $e->webhooks->update('wh_1', ['active' => false])],
        ['DELETE', '/webhooks/wh_1', fn (Entorno $e) => $e->webhooks->delete('wh_1')],
        ['POST', '/webhooks/wh_1/test', fn (Entorno $e) => $e->webhooks->test('wh_1')],
        ['GET', '/webhooks/wh_1/deliveries', fn (Entorno $e) => $e->webhooks->deliveries('wh_1')],
        ['POST', '/webhooks/wh_1/deliveries/42/retry', fn (Entorno $e) => $e->webhooks->retryDelivery('wh_1', 42)],
        ['GET', '/workspace', fn (Entorno $e) => $e->workspace->retrieve()],
    ];
    foreach ($casos as [$metodo, $ruta, $llamada]) {
        $e = new Entorno([json(200)]);
        $llamada($e);
        igual($metodo . ' ' . $ruta, $e->transporte->ultima()['method'] . ' ' . $e->transporte->ruta());
    }
});

prueba('parámetros de la dirección: codificados y sin los que no se indican', function (): void {
    $e = new Entorno(array_fill(0, 9, json(200)));
    $e->events->statuses('hamlet', holdToken: 'hold_a b');
    igual('/events/hamlet/statuses?holdToken=hold_a%20b', $e->transporte->ruta());
    $e->events->statusChanges('hamlet', limit: 5, object: 'Palco 1');
    igual('/events/hamlet/status-changes?limit=5&object=Palco%201', $e->transporte->ruta());
    $e->events->statusChanges('hamlet', before: 10);
    igual('/events/hamlet/status-changes?before=10', $e->transporte->ruta());
    $e->events->delete('hamlet', force: true);
    igual('/events/hamlet?force=1', $e->transporte->ruta());
    $e->events->list(chart: 'teatro principal');
    igual('/events?chart=teatro%20principal', $e->transporte->ruta());
    $e->charts->list(archived: true, q: 'sala');
    igual('/charts?archived=1&q=sala', $e->transporte->ruta());
    $e->seasons->statuses('abono', holdToken: 'hold_1', events: ['e-1', 'e-2']);
    igual('/seasons/abono/statuses?holdToken=hold_1&events=e-1%2Ce-2', $e->transporte->ruta());
    $e->webhooks->deliveries('wh_1', limit: 200);
    igual('/webhooks/wh_1/deliveries?limit=200', $e->transporte->ruta());
    $e->charts->list();
    igual('/charts', $e->transporte->ruta());
});

prueba('el cuerpo va en JSON, sin los campos no indicados, y los objetos como lista', function (): void {
    $e = new Entorno([json(200, RESPUESTA), json(200, RESPUESTA), json(200, RESPUESTA)]);
    $e->events->book('hamlet', [3 => 'Platea-C-1', 7 => ['key' => 'Pista', 'quantity' => 3]], holdToken: 'hold_1', orderId: 'pedido-2001');
    igual('{"objects":["Platea-C-1",{"key":"Pista","quantity":3}],"holdToken":"hold_1","orderId":"pedido-2001"}', $e->transporte->ultima()['body']);
    $e->events->hold('hamlet', ['Platea-C-1'], 'hold_1', channelKeys: ['taquilla'], ignoreChannels: false);
    igual('{"objects":["Platea-C-1"],"holdToken":"hold_1","channelKeys":["taquilla"],"ignoreChannels":false}', $e->transporte->ultima()['body']);
    $e->events->bestAvailable('hamlet', 2, categories: ['platea'], accessible: true, allowSplit: true, holdToken: 'hold_1');
    igual('{"number":2,"categories":["platea"],"accessible":true,"allowSplit":true,"holdToken":"hold_1"}', $e->transporte->ultima()['body']);
});

prueba('devolver: pedido y, si es parcial, sus objetos', function (): void {
    $e = new Entorno([json(200, RESPUESTA), json(200, RESPUESTA)]);
    $e->events->refund('hamlet', 'pedido-2001');
    igual('{"orderId":"pedido-2001"}', $e->transporte->ultima()['body']);
    $e->events->refund('hamlet', 'pedido-2001', ['Platea-C-2', ['key' => 'Pista', 'quantity' => 1]]);
    igual('{"orderId":"pedido-2001","objects":["Platea-C-2",{"key":"Pista","quantity":1}]}', $e->transporte->ultima()['body']);
});

prueba('sin cuerpo: ni cuerpo ni Content-Type; cuerpo vacío: {}', function (): void {
    $e = new Entorno([json(201), json(200), json(200)]);
    $e->holdTokens->create();
    igual(null, $e->transporte->ultima()['body']);
    cierto(!isset($e->transporte->ultima()['headers']['Content-Type']), 'sin Content-Type');
    $e->holdTokens->extend('hold_1');
    igual('{}', $e->transporte->ultima()['body']);
    igual('application/json', $e->transporte->ultima()['headers']['Content-Type']);
    $e->holdTokens->extend('hold_1', seconds: 1200);
    igual('{"seconds":1200}', $e->transporte->ultima()['body']);
});

prueba('null explícito en los cambios se envía (quita la fecha o los precios)', function (): void {
    $e = new Entorno([json(200)]);
    $e->events->update('hamlet', ['date' => null, 'pricing' => null, 'name' => 'Función']);
    igual('{"date":null,"pricing":null,"name":"Función"}', $e->transporte->ultima()['body']);
});

prueba('sin objetos es un error antes de llamar', function (): void {
    $e = new Entorno([]);
    lanza(\InvalidArgumentException::class, fn () => $e->events->book('hamlet', []));
    lanza(\InvalidArgumentException::class, fn () => $e->events->refund('hamlet', 'p-1', []));
    igual(0, $e->peticiones());
});

prueba('cabeceras: clave secreta, JSON y versión del SDK', function (): void {
    $e = new Entorno([json(200)]);
    $e->workspace->retrieve();
    $cabeceras = $e->transporte->ultima()['headers'];
    igual('Bearer sk_prueba', $cabeceras['Authorization']);
    igual('application/json', $cabeceras['Accept']);
    contiene('Seatsyoo-PHP/1.0.0', $cabeceras['User-Agent']);
});

prueba('dirección del servidor: con o sin barra final, con /api/v1 o en una subcarpeta', function (): void {
    $casos = [
        'https://seatsyoo.example' => 'https://seatsyoo.example/api/v1/workspace',
        'https://seatsyoo.example/' => 'https://seatsyoo.example/api/v1/workspace',
        'https://seatsyoo.example/api/v1' => 'https://seatsyoo.example/api/v1/workspace',
        'https://seatsyoo.example/api/v1/' => 'https://seatsyoo.example/api/v1/workspace',
        'http://127.0.0.1:8080/seatsyoo' => 'http://127.0.0.1:8080/seatsyoo/api/v1/workspace',
    ];
    foreach ($casos as $servidor => $esperada) {
        $transporte = new TransporteFalso([json(200)]);
        (new Client(secretKey: 'sk_prueba', server: $servidor, transport: $transporte))->workspace->retrieve();
        igual($esperada, $transporte->ultima()['url'], $servidor);
    }
});

prueba('Client::request: con o sin /api/v1, con consulta y en minúsculas', function (): void {
    $transporte = new TransporteFalso([json(200), json(200), json(200)]);
    $cliente = new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', transport: $transporte);
    $cliente->request('GET', '/events/hamlet/report/by', query: ['by' => 'category']);
    igual('https://seatsyoo.example/api/v1/events/hamlet/report/by?by=category', $transporte->ultima()['url']);
    $cliente->request('get', '/api/v1/events/hamlet/report/sales', null, ['bucket' => 'hour', 'tz' => 'Europe/Madrid']);
    igual('GET', $transporte->ultima()['method']);
    igual('https://seatsyoo.example/api/v1/events/hamlet/report/sales?bucket=hour&tz=Europe%2FMadrid', $transporte->ultima()['url']);
    $cliente->request('POST', 'events/hamlet/channels', ['name' => 'Taquilla', 'color' => '#e0a526']);
    igual('https://seatsyoo.example/api/v1/events/hamlet/channels', $transporte->ultima()['url']);
    igual('{"name":"Taquilla","color":"#e0a526"}', $transporte->ultima()['body']);
});

prueba('el plazo se pasa al transporte', function (): void {
    $transporte = new TransporteFalso([json(200)]);
    (new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', timeout: 12.5, transport: $transporte))->workspace->retrieve();
    igual(12.5, $transporte->ultima()['timeout']);
    $transporte = new TransporteFalso([json(200)]);
    (new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', transport: $transporte))->workspace->retrieve();
    igual(30.0, $transporte->ultima()['timeout']);
});

prueba('opciones no válidas del cliente', function (): void {
    lanza(\InvalidArgumentException::class, fn () => new Client(secretKey: '', server: 'https://seatsyoo.example'));
    lanza(\InvalidArgumentException::class, fn () => new Client(secretKey: 'sk_x', server: 'seatsyoo.example'));
    lanza(\InvalidArgumentException::class, fn () => new Client(secretKey: 'sk_x', server: 'ftp://seatsyoo.example'));
    lanza(\InvalidArgumentException::class, fn () => new Client(secretKey: 'sk_x', server: 'https://seatsyoo.example?x=1'));
    lanza(\InvalidArgumentException::class, fn () => new Client(secretKey: 'sk_x', server: 'https://seatsyoo.example', timeout: 0));
    lanza(\InvalidArgumentException::class, fn () => new Client(secretKey: 'sk_x', server: 'https://seatsyoo.example', maxRetries: -1));
});

prueba('la versión del SDK es la del paquete', function (): void {
    $paquete = json_decode((string) file_get_contents(__DIR__ . '/../../package.json'), true);
    igual($paquete['version'], Client::VERSION);
    igual('1.0.0', Client::VERSION);
});
