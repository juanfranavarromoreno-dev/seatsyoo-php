<?php

/**
 * Los ejemplos de ejemplo/ funcionan de verdad: la compra y la caja de la tienda contra el servidor de pruebas, y
 * el receptor de webhooks.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\Http\StreamTransport;
use Seatsyoo\Webhook;

grupo('Integración: los ejemplos');

/**
 * Ejecuta un guion PHP y devuelve su código de salida, lo que escribió y sus errores.
 *
 * @param list<string>          $argumentos
 * @param array<string, string> $entorno
 *
 * @return array{codigo: int, salida: string, errores: string}
 */
function ejecutarGuion(string $guion, array $argumentos, array $entorno): array
{
    $proceso = proc_open(
        array_merge([PHP_BINARY, $guion], $argumentos),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $tuberias,
        null,
        array_merge(getenv(), $entorno),
    );
    if (!is_resource($proceso)) {
        throw new Fallo('no se pudo ejecutar ' . $guion);
    }
    $salida = (string) stream_get_contents($tuberias[1]);
    $errores = (string) stream_get_contents($tuberias[2]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);

    return ['codigo' => proc_close($proceso), 'salida' => $salida, 'errores' => $errores];
}

prueba('ejemplo/compra.php: retiene, cobra lo retenido, reserva, cuadra y devuelve', function (): void {
    $seatsyoo = new Client(secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'), server: (string) getenv('SEATSYOO_TEST_SERVER'));
    $seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'ejemplo-compra', 'name' => 'Ejemplo']);
    $entorno = [
        'SEATSYOO_SECRET_KEY' => (string) getenv('SEATSYOO_TEST_SECRET_KEY'),
        'SEATSYOO_SERVER' => (string) getenv('SEATSYOO_TEST_SERVER'),
        'SEATSYOO_EVENT' => 'ejemplo-compra',
    ];
    $r = ejecutarGuion(__DIR__ . '/../../ejemplo/compra.php', [], $entorno);
    igual(0, $r['codigo'], 'termina bien (' . $r['errores'] . ')');
    igual('', $r['errores']);
    contiene('Retenidas en el visor: Platea-C-1, Platea-C-2', $r['salida']);
    // En la caja, lo que retiene el token con los precios del ejemplo (la fila C es preferente: 45 por plaza).
    cierto(preg_match('/En la caja, el pedido pedido-\S+: Platea-C-1, Platea-C-2 por 90,00 €\n/', $r['salida']) === 1, 'dice lo cobrado');
    cierto(preg_match('/Reservadas con el pedido pedido-\S+: Platea-C-1, Platea-C-2\n/', $r['salida']) === 1, 'dice lo reservado');
    contiene('Platea-C-1: 1 plaza(s), booked', $r['salida']);
    contiene('Lo vendido coincide con lo cobrado.', $r['salida']);
    contiene('Devuelta: Platea-C-2', $r['salida']);
    contiene('Devuelto el resto: Platea-C-1', $r['salida']);
    contiene('Quedan en el pedido: 0', $r['salida']);
    // En el servidor, el historial de la butaca: retenida, reservada y libre otra vez.
    $cambios = $seatsyoo->events->statusChanges('ejemplo-compra', object: 'Platea-C-1')['changes'];
    igual(['free', 'booked', 'held'], array_column($cambios, 'status'));

    // Con una butaca ocupada, dice cuál y sale con 1 sin tocar nada.
    $seatsyoo->events->book('ejemplo-compra', ['Platea-D-2']);
    $r = ejecutarGuion(__DIR__ . '/../../ejemplo/compra.php', ['Platea-D-1', 'Platea-D-2'], $entorno);
    igual(1, $r['codigo']);
    contiene('Platea-D-2 no está disponible (booked)', $r['salida']);
    cierto(!isset($seatsyoo->events->statuses('ejemplo-compra')['objects']['Platea-D-1']), 'Platea-D-1 sigue libre');

    // Sin configurar, explica cómo se usa.
    $r = ejecutarGuion(__DIR__ . '/../../ejemplo/compra.php', [], ['SEATSYOO_SECRET_KEY' => '', 'SEATSYOO_SERVER' => '', 'SEATSYOO_EVENT' => '']);
    igual(2, $r['codigo']);
    contiene('Uso:', $r['errores']);
});

prueba('ejemplo/tienda.php: reserva lo guardado en la caja, no lo que el token retenga después', function (): void {
    require_once __DIR__ . '/../../ejemplo/tienda.php';
    $seatsyoo = new Client(secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'), server: (string) getenv('SEATSYOO_TEST_SERVER'));
    $seatsyoo->events->create(['chartKey' => 'teatro', 'key' => 'ejemplo-tienda', 'name' => 'Tienda', 'tableModes' => ['M1' => 'whole']]);
    $tienda = new \Tienda($seatsyoo, ['platea' => 30, 'pista' => 18.5, 'preferente' => 45]);
    $token = $seatsyoo->holdTokens->create()['token'];

    // El comprador retiene en el visor dos butacas y una plaza de pie (la pista, sola, no trae mq en los estados).
    $seatsyoo->events->hold('ejemplo-tienda', ['Platea-D-1', 'Platea-D-2', ['key' => 'Pista', 'quantity' => 1]], holdToken: $token);
    // Lo de otro comprador no cuenta.
    $seatsyoo->events->hold('ejemplo-tienda', ['Platea-D-9'], holdToken: $seatsyoo->holdTokens->create()['token']);
    igual(78.5, $tienda->alPasarPorCaja('pedido-tienda', 'ejemplo-tienda', $token));
    $guardado = $tienda->guardado('pedido-tienda');
    igual(
        [['key' => 'Pista', 'quantity' => 1], ['key' => 'Platea-D-1', 'quantity' => 1], ['key' => 'Platea-D-2', 'quantity' => 1]],
        (static function (array $objetos): array {
            usort($objetos, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

            return $objetos;
        })($guardado['objetos']),
    );
    igual(78.5, $guardado['importe']);

    // Entre la caja y el pago, el comprador sigue reteniendo con el mismo token: otra butaca y dos plazas de pie más.
    $seatsyoo->events->hold('ejemplo-tienda', ['Platea-D-3', ['key' => 'Pista', 'quantity' => 2]], holdToken: $token);
    $reserva = $tienda->alConfirmarElPago('pedido-tienda');
    igual(['Pista', 'Platea-D-1', 'Platea-D-2'], ordenadas($reserva['objects']));
    $vendido = array_column($seatsyoo->events->retrieveOrder('ejemplo-tienda', 'pedido-tienda')['objects'], 'quantity', 'key');
    ksort($vendido);
    igual(['Pista' => 1, 'Platea-D-1' => 1, 'Platea-D-2' => 1], $vendido, 'solo lo cobrado, con la pista en 1 plaza');
    igual(true, $tienda->cuadra('pedido-tienda'));
    // Lo retenido de más sigue retenido (caduca solo), sin vender.
    $estados = $seatsyoo->events->statuses('ejemplo-tienda', holdToken: $token)['objects'];
    igual(['s' => 'held', 'mine' => true], $estados['Platea-D-3']);
    igual(2, $estados['Pista']['mq']);

    // Repetir la confirmación (un aviso de la pasarela que llega dos veces) no vende otra vez.
    igual(['objects' => [], 'seq' => 0], $tienda->alConfirmarElPago('pedido-tienda'));
    igual(true, $tienda->cuadra('pedido-tienda'));
    // Si algo cambia en el pedido, no cuadra.
    $seatsyoo->events->refund('ejemplo-tienda', 'pedido-tienda', ['Platea-D-2']);
    igual(false, $tienda->cuadra('pedido-tienda'));

    // Sin nada retenido, la caja no cobra.
    $vacio = $seatsyoo->holdTokens->create()['token'];
    lanza(\RuntimeException::class, fn () => $tienda->alPasarPorCaja('pedido-vacio', 'ejemplo-tienda', $vacio));
    lanza(\RuntimeException::class, fn () => $tienda->alConfirmarElPago('pedido-vacio'));
});

prueba('ejemplo/tienda.php: el importe cuenta las plazas de una mesa entera, un palco y una zona de pie', function (): void {
    require_once __DIR__ . '/../../ejemplo/tienda.php';
    $seatsyoo = new Client(secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'), server: (string) getenv('SEATSYOO_TEST_SERVER'));
    $palco = $seatsyoo->events->retrieveObject('ejemplo-tienda', 'Palco 1');
    $mesa = $seatsyoo->events->retrieveObject('ejemplo-tienda', 'M1');
    igual(['booth', 'table', 'mesas'], [$palco['kind'], $mesa['kind'], $mesa['category']]);
    igual([1, 6], [$palco['capacity'], $mesa['capacity']], 'la mesa entera cuenta sus seis sillas');
    $tienda = new \Tienda($seatsyoo, [$palco['category'] => 50, 'mesas' => 35, 'pista' => 18]);
    $esperado = 50 * $palco['capacity'] + 35 * $mesa['capacity'] + 18 * 4;
    $objetos = [['key' => 'Palco 1', 'quantity' => 1], ['key' => 'M1', 'quantity' => 1], ['key' => 'Pista', 'quantity' => 4]];
    igual((float) $esperado, $tienda->importeDe('ejemplo-tienda', $objetos));
    // Una categoría sin precio cuenta 0.
    igual(0.0, $tienda->importeDe('ejemplo-tienda', [['key' => 'Anfiteatro-A-1', 'quantity' => 1]]));
});

prueba('ejemplo/webhook.php: 200 con la firma buena, 400 sin ella', function (): void {
    $secreto = 'whsec_del_ejemplo';
    $receptor = new ServidorLocal(__DIR__ . '/../../ejemplo/webhook.php', ['SEATSYOO_WEBHOOK_SECRET' => $secreto]);
    $http = new StreamTransport();
    $cuerpo = '{"id":"evt_1","type":"ping","createdAt":"2026-10-16T14:40:58.868Z","workspace":"pk_x","data":{"message":"Hola"}}';
    $enviar = static function (?string $firma) use ($http, $receptor, $cuerpo): int {
        $cabeceras = ['Content-Type' => 'application/json'];
        if ($firma !== null) {
            $cabeceras['Seatsyoo-Signature'] = $firma;
        }

        return $http->send('POST', $receptor->url . '/avisos/seatsyoo', $cabeceras, $cuerpo, 5.0)->status;
    };
    igual(200, $enviar(Webhook::signatureHeader($cuerpo, $secreto)));
    igual(400, $enviar(Webhook::signatureHeader($cuerpo, 'whsec_otro')));
    igual(400, $enviar(Webhook::signatureHeader($cuerpo, $secreto, time() - 3600)));
    igual(400, $enviar(null));
    igual(405, $http->send('GET', $receptor->url . '/', [], null, 5.0)->status);
    $receptor->parar();
});
