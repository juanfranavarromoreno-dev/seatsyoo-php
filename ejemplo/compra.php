<?php

/**
 * Una compra de principio a fin desde tu servidor con el SDK de Seatsyoo para PHP: retener mientras se paga,
 * reservar con tu número de pedido, consultar el pedido y devolverlo (una parte y después el resto).
 *
 *     SEATSYOO_SECRET_KEY=sk_… SEATSYOO_SERVER=https://tu-servidor-de-seatsyoo SEATSYOO_EVENT=hamlet-2026-11-20 \
 *         php ejemplo/compra.php [Platea-C-1 Platea-C-2]
 *
 * En una web de verdad, el comprador elige sus butacas en el visor, que las retiene con su token, y tu web te
 * manda el token y lo que eligió; aquí se hace todo desde el servidor para verlo de una vez.
 */

declare(strict_types=1);

// Con Composer: require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;

$claveSecreta = getenv('SEATSYOO_SECRET_KEY') ?: '';
$servidor = getenv('SEATSYOO_SERVER') ?: '';
$evento = getenv('SEATSYOO_EVENT') ?: '';
if ($claveSecreta === '' || $servidor === '' || $evento === '') {
    fwrite(STDERR, "Uso: SEATSYOO_SECRET_KEY=sk_… SEATSYOO_SERVER=https://… SEATSYOO_EVENT=<evento> php compra.php [butacas…]\n");
    exit(2);
}
$butacas = array_slice($argv, 1) ?: ['Platea-C-1', 'Platea-C-2'];

$seatsyoo = new Client(secretKey: $claveSecreta, server: $servidor);
$pedido = 'pedido-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

// 1. Retener mientras se paga. El token lo crearía el visor; aquí lo creamos nosotros.
$token = $seatsyoo->holdTokens->create()['token'];
try {
    $seatsyoo->events->hold($evento, $butacas, holdToken: $token);
} catch (SeatsyooException $e) {
    if ($e->getErrorCode() !== 'objects-not-available') {
        throw $e;
    }
    // Ninguna se ha retenido (todo o nada): dile al comprador cuáles ya no están.
    foreach ($e->getDetails() as $plaza) {
        echo "{$plaza['key']} no está disponible ({$plaza['reason']})\n";
    }
    exit(1);
}
echo 'Retenidas: ' . implode(', ', $butacas) . "\n";

// Antes de cobrar, amplía la retención para que no caduque a mitad del pago.
$seatsyoo->holdTokens->extend($token, seconds: 600);

// 2. Cobra con tu pasarela de pago…

// 3. Reserva con el número de pedido. Si la petición se corta, el SDK la repite sola: con el mismo pedido,
//    lo ya reservado no se vende dos veces.
$seatsyoo->events->book($evento, $butacas, holdToken: $token, orderId: $pedido);
echo "Reservadas con el pedido $pedido\n";

// 4. El pedido, con el importe de cada plaza si el evento tiene precios.
foreach ($seatsyoo->events->retrieveOrder($evento, $pedido)['objects'] as $objeto) {
    $importe = isset($objeto['amount']) ? ' por ' . $objeto['amount'] : '';
    echo "  {$objeto['key']}: {$objeto['quantity']} plaza(s), {$objeto['status']}$importe\n";
}

// 5. Devuelve una plaza y, después, el resto del pedido. Repetir la devolución entera es seguro.
$parcial = $seatsyoo->events->refund($evento, $pedido, [end($butacas)]);
echo 'Devuelta: ' . implode(', ', $parcial['objects']) . "\n";
$resto = $seatsyoo->events->refund($evento, $pedido);
echo 'Devuelto el resto: ' . implode(', ', $resto['objects']) . "\n";
echo 'Quedan en el pedido: ' . count($seatsyoo->events->retrieveOrder($evento, $pedido)['objects']) . "\n";
