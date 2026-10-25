<?php

/**
 * Una compra de principio a fin con el SDK de Seatsyoo para PHP, como en la guía de integración: el comprador
 * retiene en el visor; en la caja, tu servidor pregunta qué retiene su token, calcula el importe con tus precios y
 * guarda esa lista con el pedido; con el pago confirmado, reserva exactamente esa lista con tu número de pedido.
 * Después consulta el pedido y lo devuelve (una parte y luego el resto). La caja está en ejemplo/tienda.php.
 *
 *     SEATSYOO_SECRET_KEY=sk_test_… SEATSYOO_SERVER=https://tu-servidor-de-seatsyoo SEATSYOO_EVENT=hamlet-2026-11-20 \
 *         php ejemplo/compra.php [Platea-C-1 Platea-C-2]
 *
 * En una web de verdad, el visor retiene lo que elige el comprador con su token, y tu web solo te manda ese token
 * al pasar por caja; aquí el servidor hace también de visor, con las butacas que le pases, para verlo de una vez.
 * Reserva y devuelve de verdad: pruébalo con una clave de tu entorno de pruebas (sk_test_…).
 */

declare(strict_types=1);

// Con Composer: require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../autoload.php';
require __DIR__ . '/tienda.php';

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
// Tus precios por plaza, por clave de categoría de tu plano: los mismos que le pasas al visor con su opción pricing.
$tienda = new Tienda($seatsyoo, ['preferente' => 45, 'platea' => 30, 'anfiteatro' => 22, 'mesas' => 35, 'pista' => 18]);
// El número de pedido lo pone tu tienda, nunca el comprador.
$pedido = 'pedido-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

// 1. En el navegador, el visor retiene lo que elige el comprador con su token. Aquí lo hacemos nosotros.
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
echo 'Retenidas en el visor: ' . implode(', ', $butacas) . "\n";

// 2. En la caja, tu web te manda solo el token: lo que retiene ahora es lo que se cobra, y se guarda con el pedido.
$importe = $tienda->alPasarPorCaja($pedido, $evento, $token);
$enCaja = array_column($tienda->guardado($pedido)['objetos'], 'key');
echo "En la caja, el pedido $pedido: " . implode(', ', $enCaja) . ' por ' . number_format($importe, 2, ',', '.') . " €\n";

// Antes de mandar al comprador a pagar, amplía la retención para que no caduque a mitad del pago.
$seatsyoo->holdTokens->extend($token, seconds: 600);

// 3. Cobra el importe con tu pasarela de pago…

// 4. Con el pago confirmado, reserva la lista guardada en la caja. Si la petición se corta, el SDK la repite sola:
//    con el mismo pedido no se vende dos veces. Tras un reintento, $reserva['retried'] es true y
//    $reserva['objects'] sigue trayendo lo reservado.
try {
    $reserva = $tienda->alConfirmarElPago($pedido);
} catch (SeatsyooException $e) {
    if (!isset($tienda->guardado($pedido)['porDevolver'])) {
        // Sin respuesta (getStatus() 0) o un 5xx: no se sabe si se vendió; repítelo más tarde con el mismo pedido.
        // Otro 4xx (el evento ya no existe, la clave de producción bloqueada…): no se ha vendido nada; revísalo, o
        // devuelve el cobro.
        throw $e;
    }
    // Cobrado y sin vender nada (todo o nada): devuelve el cobro con tu pasarela y no emitas entradas.
    echo "No se ha podido reservar el pedido $pedido ({$e->getErrorCode()}): devuelve el cobro.\n";
    exit(1);
}
echo 'Reservadas con el pedido ' . $pedido . ': ' . implode(', ', $reserva['objects']) . (empty($reserva['retried']) ? '' : ' (tras repetir la petición)') . "\n";

// 5. El pedido en Seatsyoo y las cuentas: lo vendido ha de ser lo que se cobró.
foreach ($seatsyoo->events->retrieveOrder($evento, $pedido)['objects'] as $objeto) {
    echo "  {$objeto['key']}: {$objeto['quantity']} plaza(s), {$objeto['status']}\n";
}
echo $tienda->cuadra($pedido)
    ? "Lo vendido coincide con lo cobrado.\n"
    : "Lo vendido no coincide con lo cobrado: revísalo antes de emitir las entradas.\n";

// 6. Devuelve una plaza y, después, el resto del pedido. Repetir la devolución entera es seguro.
$parcial = $seatsyoo->events->refund($evento, $pedido, [end($enCaja)]);
echo 'Devuelta: ' . implode(', ', $parcial['objects']) . "\n";
$resto = $seatsyoo->events->refund($evento, $pedido);
echo 'Devuelto el resto: ' . implode(', ', $resto['objects']) . "\n";
echo 'Quedan en el pedido: ' . count($seatsyoo->events->retrieveOrder($evento, $pedido)['objects']) . "\n";
