<?php

/** Los ejemplos de ejemplo/ funcionan de verdad: la compra contra el servidor de pruebas y el receptor de webhooks. */

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

prueba('ejemplo/compra.php: retiene, reserva, consulta y devuelve', function (): void {
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
    contiene('Retenidas: Platea-C-1, Platea-C-2', $r['salida']);
    contiene('Platea-C-1: 1 plaza(s), booked', $r['salida']);
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
