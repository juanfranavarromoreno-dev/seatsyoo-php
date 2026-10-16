<?php

/**
 * Pruebas contra un servidor de Seatsyoo de verdad (lo levanta tests/ejecutar.mjs en memoria): el plano
 * «teatro» publicado y el evento «hamlet» con precios (preferente 45, platea 30, anfiteatro 20, pista 18).
 * Las pruebas van en orden y comparten lo que crean.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;
use Seatsyoo\Webhook;

grupo('Integración: la API de un servidor de verdad');

final class Api
{
    public static Client $seatsyoo;
    public static string $evento;
    public static string $token = '';
    public static string $webhook = '';
    public static string $secreto = '';
    public static string $avisos = '';
}

Api::$seatsyoo = new Client(secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'), server: (string) getenv('SEATSYOO_TEST_SERVER'));
Api::$evento = (string) getenv('SEATSYOO_TEST_EVENT');

/** @param list<string> $claves */
function ordenadas(array $claves): array
{
    sort($claves);

    return $claves;
}

/**
 * Los avisos que ha recibido el receptor local.
 *
 * @return list<array<string, mixed>>
 */
function avisosRecibidos(): array
{
    if (!is_file(Api::$avisos)) {
        return [];
    }
    $lineas = file(Api::$avisos, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_map(static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lineas);
}

/** Espera al primer aviso que cumpla $cumple. */
function esperarAviso(callable $cumple, string $que): array
{
    return esperarA(static function () use ($cumple): ?array {
        foreach (avisosRecibidos() as $registro) {
            if ($cumple($registro)) {
                return $registro;
            }
        }

        return null;
    }, 15.0, $que);
}

prueba('lee el espacio de la clave', function (): void {
    $espacio = Api::$seatsyoo->workspace->retrieve();
    igual('Pruebas PHP', $espacio['name']);
    igual(getenv('SEATSYOO_TEST_PUBLIC_KEY'), $espacio['publicKey']);
    igual([], $espacio['secretKeys']);
});

prueba('lista y lee planos y eventos', function (): void {
    $planos = Api::$seatsyoo->charts->list();
    igual(['teatro'], array_column($planos['charts'], 'key'));
    igual('published', Api::$seatsyoo->charts->retrieve('teatro')['status']);
    igual([], Api::$seatsyoo->charts->list(archived: true)['charts']);
    igual([Api::$evento], array_column(Api::$seatsyoo->events->list()['events'], 'key'));
    igual([Api::$evento], array_column(Api::$seatsyoo->events->list(chart: 'teatro')['events'], 'key'));
    $evento = Api::$seatsyoo->events->retrieve(Api::$evento);
    igual('Hamlet', $evento['name']);
    igual('EUR', $evento['settings']['pricing']['currency']);
});

prueba('crea, cambia y borra un evento', function (): void {
    $creado = Api::$seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'prueba-crud', 'name' => 'Función de prueba']);
    igual('prueba-crud', $creado['key']);
    igual('Función de prueba', $creado['name']);
    $cambiado = Api::$seatsyoo->events->update('prueba-crud', ['name' => 'Función ñ €', 'date' => '2026-12-01T20:00:00+01:00']);
    igual('Función ñ €', $cambiado['name']);
    igual('2026-12-01T20:00:00+01:00', $cambiado['date']);
    igual(null, Api::$seatsyoo->events->update('prueba-crud', ['date' => null])['date']);
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'prueba-crud']));
    igual(409, $error->getStatus());
    igual('key-taken', $error->getErrorCode());
    igual(['ok' => true], Api::$seatsyoo->events->delete('prueba-crud'));
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->retrieve('prueba-crud'));
    igual(404, $error->getStatus());
    igual('not-found', $error->getErrorCode());
});

prueba('da de alta un webhook hacia un receptor local en PHP', function (): void {
    $puerto = puertoLibre();
    $webhook = Api::$seatsyoo->webhooks->create([
        'url' => "http://127.0.0.1:$puerto/aviso",
        'types' => ['objects.booked', 'objects.released'],
        'description' => 'Pruebas del SDK de PHP',
    ]);
    cierto(strncmp($webhook['secret'], 'whsec_', 6) === 0, 'trae el secreto');
    igual(true, $webhook['active']);
    Api::$webhook = $webhook['key'];
    Api::$secreto = $webhook['secret'];
    Api::$avisos = (string) tempnam(sys_get_temp_dir(), 'seatsyoo-avisos-');
    alAcabar(static function (): void {
        @unlink(Api::$avisos);
    });
    new ServidorLocal(__DIR__ . '/../receptor.php', [
        'SEATSYOO_WEBHOOK_SECRET' => Api::$secreto,
        'SEATSYOO_AVISOS' => Api::$avisos,
    ], $puerto);
    $lista = Api::$seatsyoo->webhooks->list();
    igual([Api::$webhook], array_column($lista['webhooks'], 'key'));
    cierto(in_array('objects.booked', $lista['types'], true), 'lista los tipos de aviso');
});

prueba('token de retención: crear, leer y ampliar', function (): void {
    $token = Api::$seatsyoo->holdTokens->create();
    cierto(strncmp($token['token'], 'hold_', 5) === 0, 'empieza por hold_');
    entre(890, 900, (float) $token['expiresInSeconds']);
    igual($token['token'], Api::$seatsyoo->holdTokens->retrieve($token['token'])['token']);
    $ampliado = Api::$seatsyoo->holdTokens->extend($token['token'], seconds: 1200);
    entre(1190, 1200, (float) $ampliado['expiresInSeconds']);
    Api::$token = $token['token'];
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->holdTokens->retrieve('hold_no_existe'));
    igual(404, $error->getStatus());
});

prueba('retener butacas y plazas de pie, y verlas como mías', function (): void {
    $r = Api::$seatsyoo->events->hold(Api::$evento, ['Platea-C-1', 'Platea-C-2', ['key' => 'Pista', 'quantity' => 3]], holdToken: Api::$token);
    igual(['Pista', 'Platea-C-1', 'Platea-C-2'], ordenadas($r['objects']));
    cierto($r['seq'] > 0, 'seq crece');
    $estados = Api::$seatsyoo->events->statuses(Api::$evento, holdToken: Api::$token)['objects'];
    igual(['s' => 'held', 'mine' => true], $estados['Platea-C-1']);
    igual(3, $estados['Pista']['q']);
    igual(3, $estados['Pista']['mq']);
    // Sin token, nada es «mío».
    cierto(!isset(Api::$seatsyoo->events->statuses(Api::$evento)['objects']['Platea-C-1']['mine']), 'sin mine sin token');
});

prueba('reservar con número de pedido', function (): void {
    $r = Api::$seatsyoo->events->book(
        Api::$evento,
        ['Platea-C-1', 'Platea-C-2', ['key' => 'Pista', 'quantity' => 3]],
        holdToken: Api::$token,
        orderId: 'pedido-2001',
    );
    igual(['Pista', 'Platea-C-1', 'Platea-C-2'], ordenadas($r['objects']));
    igual('booked', Api::$seatsyoo->events->statuses(Api::$evento)['objects']['Platea-C-1']['s']);
});

prueba('repetir la reserva con el mismo pedido no vende otra vez', function (): void {
    $r = Api::$seatsyoo->events->book(
        Api::$evento,
        ['Platea-C-1', 'Platea-C-2', ['key' => 'Pista', 'quantity' => 3]],
        holdToken: Api::$token,
        orderId: 'pedido-2001',
    );
    igual(['objects' => [], 'seq' => 0], $r);
    igual(3, Api::$seatsyoo->events->statuses(Api::$evento)['objects']['Pista']['q']);
});

prueba('leer el pedido con sus importes', function (): void {
    $pedido = Api::$seatsyoo->events->retrieveOrder(Api::$evento, 'pedido-2001');
    igual('pedido-2001', $pedido['orderId']);
    $porClave = array_column($pedido['objects'], null, 'key');
    igual(['Pista', 'Platea-C-1', 'Platea-C-2'], ordenadas(array_keys($porClave)));
    igual(['key' => 'Platea-C-1', 'quantity' => 1, 'status' => 'booked', 'amount' => 45], $porClave['Platea-C-1']);
    igual(3, $porClave['Pista']['quantity']);
    igual(54, $porClave['Pista']['amount']);
    igual([], Api::$seatsyoo->events->retrieveOrder(Api::$evento, 'pedido que no existe')['objects']);
});

prueba('409 objects-not-available con el motivo de cada objeto en details', function (): void {
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->book(Api::$evento, ['Platea-C-1', 'Platea-C-3'], orderId: 'pedido-otro'));
    igual(409, $error->getStatus());
    igual('objects-not-available', $error->getErrorCode());
    igual([['key' => 'Platea-C-1', 'reason' => 'booked']], $error->getDetails());
    contiene('Platea-C-1', $error->getMessage());
    // Todo o nada: Platea-C-3, que estaba libre, sigue libre.
    cierto(!isset(Api::$seatsyoo->events->statuses(Api::$evento)['objects']['Platea-C-3']), 'Platea-C-3 sigue libre');
    $otro = Api::$seatsyoo->holdTokens->create()['token'];
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->hold(Api::$evento, ['Platea-C-2', ['key' => 'Pista', 'quantity' => 200]], holdToken: $otro));
    igual([['key' => 'Platea-C-2', 'reason' => 'booked'], ['key' => 'Pista', 'reason' => 'insufficient-capacity']], $error->getDetails());
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->book(Api::$evento, ['No-Existe-1']));
    igual(400, $error->getStatus());
    igual('unknown-objects', $error->getErrorCode());
});

prueba('devolución parcial de un pedido', function (): void {
    $r = Api::$seatsyoo->events->refund(Api::$evento, 'pedido-2001', ['Platea-C-2', ['key' => 'Pista', 'quantity' => 1]]);
    igual(['Pista', 'Platea-C-2'], ordenadas($r['objects']));
    $porClave = array_column(Api::$seatsyoo->events->retrieveOrder(Api::$evento, 'pedido-2001')['objects'], null, 'key');
    igual(['Pista', 'Platea-C-1'], ordenadas(array_keys($porClave)));
    igual(2, $porClave['Pista']['quantity']);
    igual(36, $porClave['Pista']['amount']);
});

prueba('repetir una devolución parcial de una butaca da 409 not-in-order', function (): void {
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->refund(Api::$evento, 'pedido-2001', ['Platea-C-2']));
    igual('objects-not-available', $error->getErrorCode());
    igual([['key' => 'Platea-C-2', 'reason' => 'not-in-order']], $error->getDetails());
});

prueba('devolución entera, y repetirla es seguro', function (): void {
    $r = Api::$seatsyoo->events->refund(Api::$evento, 'pedido-2001');
    igual(['Pista', 'Platea-C-1'], ordenadas($r['objects']));
    igual(['objects' => [], 'seq' => 0], Api::$seatsyoo->events->refund(Api::$evento, 'pedido-2001'));
    igual([], Api::$seatsyoo->events->retrieveOrder(Api::$evento, 'pedido-2001')['objects']);
    $estados = Api::$seatsyoo->events->statuses(Api::$evento)['objects'];
    cierto(!isset($estados['Platea-C-1']) && !isset($estados['Pista']), 'todo vuelve a estar libre');
});

prueba('mejor disponible: retener con token y reservar con pedido', function (): void {
    $token = Api::$seatsyoo->holdTokens->create()['token'];
    $r = Api::$seatsyoo->events->bestAvailable(Api::$evento, 2, categories: ['platea'], holdToken: $token);
    igual(2, count($r['objects']));
    igual(true, $r['contiguous']);
    $estados = Api::$seatsyoo->events->statuses(Api::$evento, holdToken: $token)['objects'];
    foreach ($r['objects'] as $clave) {
        igual(['s' => 'held', 'mine' => true], $estados[$clave], $clave);
    }
    Api::$seatsyoo->events->book(Api::$evento, $r['objects'], holdToken: $token, orderId: 'pedido-mejor');
    $directo = Api::$seatsyoo->events->bestAvailable(Api::$evento, 3, categories: ['anfiteatro'], orderId: 'pedido-mejor-2');
    igual(3, count($directo['objects']));
    $pedido = Api::$seatsyoo->events->retrieveOrder(Api::$evento, 'pedido-mejor-2');
    igual(ordenadas($directo['objects']), ordenadas(array_column($pedido['objects'], 'key')));
    igual([20, 20, 20], array_column($pedido['objects'], 'amount'));
});

prueba('mejor disponible imposible o fuera de margen', function (): void {
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->bestAvailable(Api::$evento, 100, categories: ['preferente']));
    igual(409, $error->getStatus());
    igual('best-available-not-found', $error->getErrorCode());
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->bestAvailable(Api::$evento, 0));
    igual(400, $error->getStatus());
    igual('bad-request', $error->getErrorCode());
});

prueba('un objeto con espacios en la clave (Palco 1)', function (): void {
    $palco = Api::$seatsyoo->events->retrieveObject(Api::$evento, 'Palco 1');
    igual('Palco 1', $palco['key']);
    igual('booth', $palco['kind']);
    igual('free', $palco['status']);
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->retrieveObject(Api::$evento, 'Palco 99'));
    igual('not-found', $error->getErrorCode());
});

prueba('un estado propio y el historial de cambios', function (): void {
    $r = Api::$seatsyoo->events->changeStatus(Api::$evento, ['Platea-E-1', 'Platea-E-2'], 'invitacion', orderId: 'prensa-1');
    igual(['Platea-E-1', 'Platea-E-2'], ordenadas($r['objects']));
    $objeto = Api::$seatsyoo->events->retrieveObject(Api::$evento, 'Platea-E-1');
    igual('invitacion', $objeto['status']);
    igual('prensa-1', $objeto['allocations'][0]['orderId']);
    $ultimos = Api::$seatsyoo->events->statusChanges(Api::$evento, limit: 2)['changes'];
    igual(2, count($ultimos));
    igual(['invitacion', 'invitacion'], array_column($ultimos, 'status'));
    cierto($ultimos[0]['id'] > $ultimos[1]['id'], 'del más reciente al más antiguo');
    $siguientes = Api::$seatsyoo->events->statusChanges(Api::$evento, limit: 1, before: $ultimos[1]['id'])['changes'];
    cierto($siguientes[0]['id'] < $ultimos[1]['id'], 'before pagina hacia atrás');
    $deUno = Api::$seatsyoo->events->statusChanges(Api::$evento, object: 'Platea-C-1')['changes'];
    igual(['Platea-C-1'], array_values(array_unique(array_column($deUno, 'key'))));
    igual(['free', 'booked', 'held'], array_column($deUno, 'status'));
    igual('api', $deUno[0]['origin']);
});

prueba('sacar de la venta y volver a poner', function (): void {
    $evento = Api::$seatsyoo->events->markNotForSale(Api::$evento, keys: ['Platea-F-1']);
    igual(['Platea-F-1'], $evento['settings']['notForSale']['keys']);
    $token = Api::$seatsyoo->holdTokens->create()['token'];
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->events->hold(Api::$evento, ['Platea-F-1'], holdToken: $token));
    igual([['key' => 'Platea-F-1', 'reason' => 'not-for-sale']], $error->getDetails());
    $evento = Api::$seatsyoo->events->markForSale(Api::$evento, keys: ['Platea-F-1']);
    igual([], $evento['settings']['notForSale']['keys']);
    Api::$seatsyoo->events->hold(Api::$evento, ['Platea-F-1'], holdToken: $token);
    $r = Api::$seatsyoo->events->release(Api::$evento, ['Platea-F-1'], holdToken: $token);
    igual(['Platea-F-1'], $r['objects']);
});

prueba('401 con una clave que no vale', function (): void {
    $mala = new Client(secretKey: 'sk_no_vale', server: (string) getenv('SEATSYOO_TEST_SERVER'));
    $error = lanza(SeatsyooException::class, fn () => $mala->workspace->retrieve());
    igual(401, $error->getStatus());
    igual('unauthorized', $error->getErrorCode());
});

prueba('temporadas: crear, reservar y devolver un abono', function (): void {
    Api::$seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'abono-1', 'name' => 'Primera', 'date' => '2026-11-01T20:00:00+01:00']);
    Api::$seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'abono-2', 'name' => 'Segunda', 'date' => '2026-11-08T20:00:00+01:00']);
    $temporada = Api::$seatsyoo->seasons->create(['name' => 'Abono de otoño', 'chartKey' => 'teatro', 'key' => 'otono', 'eventKeys' => ['abono-1']]);
    igual(['abono-1'], array_column($temporada['events'], 'key'));
    $temporada = Api::$seatsyoo->seasons->addEvents('otono', eventKeys: ['abono-2']);
    igual(['abono-1', 'abono-2'], array_column($temporada['events'], 'key'));
    igual(['otono'], array_column(Api::$seatsyoo->seasons->list()['seasons'], 'key'));
    igual('Abono de otoño', Api::$seatsyoo->seasons->retrieve('otono')['name']);

    $token = Api::$seatsyoo->holdTokens->create()['token'];
    $retenido = Api::$seatsyoo->seasons->hold('otono', ['Anfiteatro-B-3'], holdToken: $token);
    igual(2, $retenido['events']);
    igual(['s' => 'held', 'mine' => true], Api::$seatsyoo->seasons->statuses('otono', holdToken: $token)['objects']['Anfiteatro-B-3']);
    $reserva = Api::$seatsyoo->seasons->book('otono', ['Anfiteatro-B-3'], holdToken: $token, orderId: 'abono-2001');
    igual(['objects' => ['Anfiteatro-B-3'], 'events' => 2], ['objects' => $reserva['objects'], 'events' => $reserva['events']]);
    igual('booked', Api::$seatsyoo->events->statuses('abono-2')['objects']['Anfiteatro-B-3']['s']);
    igual(['Anfiteatro-B-3'], array_column(Api::$seatsyoo->events->retrieveOrder('abono-1', 'abono-2001')['objects'], 'key'));
    $devuelto = Api::$seatsyoo->seasons->refund('otono', 'abono-2001');
    igual(['Anfiteatro-B-3'], $devuelto['objects']);
    igual([], Api::$seatsyoo->events->retrieveOrder('abono-2', 'abono-2001')['objects']);

    igual(['abono-1'], array_column(Api::$seatsyoo->seasons->removeEvent('otono', 'abono-2')['events'], 'key'));
    igual(['name' => 'Otoño'], ['name' => Api::$seatsyoo->seasons->update('otono', ['name' => 'Otoño'])['name']]);
    igual(['ok' => true], Api::$seatsyoo->seasons->delete('otono'));
    igual('abono-1', Api::$seatsyoo->events->retrieve('abono-1')['key']);
});

prueba('request() para cualquier otra ruta: JSON, CSV y cuerpos', function (): void {
    $resumen = Api::$seatsyoo->request('GET', '/events/' . rawurlencode(Api::$evento) . '/report/summary');
    cierto(is_array($resumen) && isset($resumen['total']['capacity']), 'el resumen trae el aforo');
    $csv = Api::$seatsyoo->request('GET', '/api/v1/events/' . rawurlencode(Api::$evento) . '/report/orders.csv', null, ['sep' => ';']);
    cierto(is_string($csv), 'el CSV llega como texto');
    contiene('pedido-mejor', $csv);
    contiene(';', $csv);
    $evento = Api::$seatsyoo->request('POST', '/events/' . rawurlencode(Api::$evento) . '/channels', ['name' => 'Taquilla', 'color' => '#e0a526']);
    igual(['taquilla'], array_column($evento['settings']['channels'], 'key'));
});

prueba('los avisos llegan al receptor y se verifican con Webhook::verify', function (): void {
    $reserva = esperarAviso(
        static fn (array $r): bool => $r['tipo'] === 'objects.booked' && ($r['aviso']['data']['orderId'] ?? null) === 'pedido-2001',
        'el aviso objects.booked de pedido-2001',
    );
    igual(true, $reserva['valido']);
    igual('Seatsyoo-Webhooks/1', $reserva['agente']);
    igual('application/json', $reserva['contenido']);
    $datos = $reserva['aviso']['data'];
    igual(Api::$evento, $datos['event']['key']);
    igual(['Pista', 'Platea-C-1', 'Platea-C-2'], ordenadas(array_column($datos['objects'], 'key')));
    igual(144, $datos['total']);
    igual('EUR', $datos['currency']);
    igual('api', $datos['origin']);
    igual(getenv('SEATSYOO_TEST_PUBLIC_KEY'), $reserva['aviso']['workspace']);

    // La misma verificación aquí, con el cuerpo y la cabecera tal cual llegaron (con margen: puede haber tardado).
    $aviso = Webhook::verify($reserva['cuerpo'], $reserva['firma'], Api::$secreto, 600);
    igual($reserva['aviso']['id'], $aviso['id']);
    lanza(\Seatsyoo\SignatureVerificationException::class, fn () => Webhook::verify($reserva['cuerpo'] . ' ', $reserva['firma'], Api::$secreto, 600));
    lanza(\Seatsyoo\SignatureVerificationException::class, fn () => Webhook::verify($reserva['cuerpo'], $reserva['firma'], 'whsec_otro', 600));

    $devolucion = esperarAviso(
        static fn (array $r): bool => $r['tipo'] === 'objects.released' && ($r['aviso']['data']['orderId'] ?? null) === 'pedido-2001',
        'el aviso objects.released de pedido-2001',
    );
    igual(true, $devolucion['valido']);
});

prueba('el aviso de prueba (ping) y los envíos del webhook', function (): void {
    igual(['ok' => true], Api::$seatsyoo->webhooks->test(Api::$webhook));
    $ping = esperarAviso(static fn (array $r): bool => $r['tipo'] === 'ping', 'el ping');
    igual(true, $ping['valido']);
    igual('Prueba desde Seatsyoo', $ping['aviso']['data']['message']);
    $envios = esperarA(static function (): ?array {
        $lista = Api::$seatsyoo->webhooks->deliveries(Api::$webhook, limit: 200)['deliveries'];
        foreach ($lista as $envio) {
            if ($envio['status'] !== 'delivered') {
                return null;
            }
        }

        return $lista;
    }, 15.0, 'que todos los envíos consten como entregados');
    cierto(count($envios) >= 4, 'hay varios envíos');
    igual(200, $envios[0]['lastStatus']);
    igual(['ok' => true], Api::$seatsyoo->webhooks->retryDelivery(Api::$webhook, $envios[0]['id']));
    $error = lanza(SeatsyooException::class, fn () => Api::$seatsyoo->webhooks->retryDelivery(Api::$webhook, 999999));
    igual(404, $error->getStatus());
});

prueba('con el secreto cambiado, el receptor rechaza los avisos (400)', function (): void {
    $cambiado = Api::$seatsyoo->webhooks->update(Api::$webhook, ['rotateSecret' => true]);
    cierto($cambiado['secret'] !== Api::$secreto, 'secreto nuevo');
    Api::$seatsyoo->events->book(Api::$evento, ['Platea-J-1'], orderId: 'pedido-secreto-nuevo');
    $rechazado = esperarAviso(
        static fn (array $r): bool => (json_decode($r['cuerpo'], true)['data']['orderId'] ?? null) === 'pedido-secreto-nuevo',
        'el aviso firmado con el secreto nuevo',
    );
    igual(false, $rechazado['valido']);
    contiene('no coincide', $rechazado['error']);
    // Con el secreto nuevo sí se verifica.
    $aviso = Webhook::verify($rechazado['cuerpo'], $rechazado['firma'], $cambiado['secret'], 600);
    igual('pedido-secreto-nuevo', $aviso['data']['orderId']);
    $envio = esperarA(static function (): ?array {
        foreach (Api::$seatsyoo->webhooks->deliveries(Api::$webhook)['deliveries'] as $envio) {
            if (($envio['payload']['data']['orderId'] ?? null) === 'pedido-secreto-nuevo' && $envio['lastStatus'] === 400) {
                return $envio;
            }
        }

        return null;
    }, 15.0, 'que el envío conste con un 400');
    // Un 400 cuenta como fallo: queda pendiente para reintentarlo más tarde.
    igual('pending', $envio['status']);
    igual('HTTP 400', $envio['lastError']);
    igual(false, Api::$seatsyoo->webhooks->update(Api::$webhook, ['active' => false])['active']);
    igual(['ok' => true], Api::$seatsyoo->webhooks->delete(Api::$webhook));
    igual([], Api::$seatsyoo->webhooks->list()['webhooks']);
});
