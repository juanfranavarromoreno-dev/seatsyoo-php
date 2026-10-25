<?php

/** El receptor de avisos de ejemplo/webhook.php, servido por un servidor local de PHP: solo necesita PHP. */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Http\StreamTransport;
use Seatsyoo\Webhook;

grupo('Ejemplo: el receptor de avisos');

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
