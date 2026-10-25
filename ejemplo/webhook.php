<?php

/**
 * Receptor de webhooks de Seatsyoo en PHP plano: verifica la firma, contesta enseguida y después trabaja.
 *
 *     SEATSYOO_WEBHOOK_SECRET=whsec_… php -S 0.0.0.0:3000 ejemplo/webhook.php
 *
 * (o ponlo detrás de tu servidor web). Da de alta su URL con $seatsyoo->webhooks->create(['url' => …]) y
 * guarda el secret que te devuelve.
 */

declare(strict_types=1);

// Con Composer: require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Seatsyoo\SignatureVerificationException;
use Seatsyoo\Webhook;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);

    return;
}

// El cuerpo tal cual llega: si lo decodificas y lo vuelves a codificar, la firma no coincidirá.
$cuerpo = (string) file_get_contents('php://input');
$firma = $_SERVER['HTTP_SEATSYOO_SIGNATURE'] ?? null;

try {
    $aviso = Webhook::verify($cuerpo, $firma, getenv('SEATSYOO_WEBHOOK_SECRET') ?: '');
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    echo $e->getMessage();

    return;
}

// Contesta con un 2xx en menos de 10 segundos y trabaja después: si no, Seatsyoo lo reintenta.
http_response_code(200);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Un aviso puede llegar repetido (si se pierde tu respuesta): no proceses dos veces el mismo id. En
// producción, guárdalos en tu base de datos; y para ordenar los avisos de un evento, usa data.seq. Los avisos
// de tu entorno de pruebas llevan además 'test' => true.
$data = $aviso['data'];
switch ($aviso['type']) {
    case 'objects.booked':
        // Una venta: emite las entradas del pedido $data['orderId']. El aviso no trae importes: lo cobrado lo
        // guardaste tú con el pedido (compáralo con $data['objects'] antes de emitir).
        error_log(sprintf('[%s] Vendido en %s: %s (pedido %s)', $aviso['id'], $data['event']['key'], implode(', ', array_column($data['objects'], 'key')), $data['orderId'] ?? '-'));
        break;
    case 'objects.released':
        // Una devolución (con orderId) o una retención que se suelta (con holdToken).
        error_log(sprintf('[%s] Liberado en %s: %s', $aviso['id'], $data['event']['key'], implode(', ', array_column($data['objects'], 'key'))));
        break;
    case 'ping':
        error_log('Prueba recibida: ' . $data['message']);
        break;
    default:
        error_log('Aviso ' . $aviso['type']);
}
