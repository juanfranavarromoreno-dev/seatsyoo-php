# Seatsyoo para PHP

SDK de servidor de Seatsyoo para PHP: crea y consulta eventos, retén y reserva butacas con tu número de
pedido, devuélvelas, busca las mejores disponibles, vende abonos de temporada y verifica los webhooks.

- PHP 8.1 o posterior, sin dependencias. Usa la extensión curl si está; si no, los flujos de PHP (para
  https sin curl hace falta openssl).
- Cada método devuelve la respuesta de la API como array asociativo, tal cual la describe la referencia de
  la API (`/docs/api` en la documentación de Seatsyoo).
- Los errores son excepciones con el código estable de la API; las peticiones que es seguro repetir se
  reintentan solas.

*English summary below.*

## Instalación

```sh
composer require seatsyoo/seatsyoo-php
```

Sin Composer, copia la carpeta del paquete y carga sus clases con `require 'seatsyoo-php/autoload.php';`.

## Empezar

```php
use Seatsyoo\Client;

$seatsyoo = new Client(
    secretKey: (string) getenv('SEATSYOO_SECRET_KEY'),   // sk_…: en las variables de entorno de tu servidor
    server: 'https://tu-servidor-de-seatsyoo',           // la dirección de Seatsyoo, sin /api/v1
);

$evento = $seatsyoo->events->retrieve('hamlet-2026-11-20');
```

La clave secreta da acceso de administración a tu espacio: úsala solo en tu servidor, nunca en tu web ni en
tu repositorio. Opciones del cliente: `timeout` (segundos por intento, 30 por defecto), `maxRetries`
(reintentos, 2 por defecto; 0 para ninguno) y `transport` (ver [Transporte propio](#transporte-propio)).

## Vender

El comprador elige sus butacas en el visor, que las retiene con su token de retención; tu web te manda el
token y lo que eligió, cobras y reservas desde tu servidor con tu número de pedido:

```php
use Seatsyoo\SeatsyooException;

$objetos = ['Platea-C-1', 'Platea-C-2', ['key' => 'Pista', 'quantity' => 3]];   // en una zona de pie, con cantidad

// Antes de cobrar, amplía la retención para que no caduque a mitad del pago.
$seatsyoo->holdTokens->extend($holdToken, seconds: 600);

// … cobra …

try {
    $seatsyoo->events->book('hamlet-2026-11-20', $objetos, holdToken: $holdToken, orderId: 'pedido-2001');
} catch (SeatsyooException $e) {
    if ($e->getErrorCode() !== 'objects-not-available') {
        throw $e;
    }
    foreach ($e->getDetails() as $plaza) {
        // $plaza['key'] ya no está disponible: $plaza['reason'] es held, booked, not-for-sale…
    }
}

// Lo vendido con ese pedido, con el importe de cada objeto si el evento tiene precios.
$pedido = $seatsyoo->events->retrieveOrder('hamlet-2026-11-20', 'pedido-2001');

// Devolver una parte del pedido o el pedido entero.
$seatsyoo->events->refund('hamlet-2026-11-20', 'pedido-2001', ['Platea-C-2', ['key' => 'Pista', 'quantity' => 1]]);
$seatsyoo->events->refund('hamlet-2026-11-20', 'pedido-2001');
```

Las acciones son todo o nada: si un objeto no se puede, no se toca ninguno. Con `orderId`, repetir la
reserva no vende otra vez (responde `"objects": []`), aunque el token haya caducado entre medias.

Las mejores butacas libres juntas, retenidas con un token o reservadas directamente:

```php
$r = $seatsyoo->events->bestAvailable('hamlet-2026-11-20', 2, categories: ['platea'], holdToken: $holdToken);
// $r['objects'] = ['Platea-F-7', 'Platea-F-8'], $r['contiguous'] = true
```

## Errores

Todo lo que falla en una petición lanza `Seatsyoo\SeatsyooException`:

| Método | Qué da |
| --- | --- |
| `getStatus()` | El código HTTP; 0 si no hubo respuesta. |
| `getErrorCode()` | El código del error, estable: `objects-not-available`, `not-found`, `hold-token-expired`…; sin respuesta, `network-error` o `timeout`. |
| `getMessage()` | Una explicación para personas, en español. Puede cambiar: no la compares. |
| `getDetails()` | Los detalles, si los hay: en `objects-not-available`, `[['key' => …, 'reason' => …]]`. |

Los códigos están en la página de errores de la documentación (`/docs/errores`). Un error de programación (una clave vacía, una
lista de objetos vacía) lanza `InvalidArgumentException` antes de llamar a la API.

## Reintentos

Ante un error de red, un tiempo agotado, un `429` (esperando lo que diga `Retry-After`, como mucho 60 s) o un
`502`, `503` o `504`, el cliente repite la petición hasta `maxRetries` veces, con una espera que crece (0,5 s,
1 s, 2 s…, con algo de azar), pero solo cuando repetirla es seguro:

- las lecturas (`GET`), `PUT` y `DELETE`;
- reservar con número de pedido (`book(..., orderId: …)`, también en temporadas);
- devolver un pedido entero (`refund()` sin objetos).

Lo demás (retener, reservar sin pedido, devolver una parte, mejor disponible…) no se reintenta: repetirlo
podría hacerlo dos veces. La cuota diaria agotada (`429 quota-exceeded`) tampoco.

## Webhooks

Verifica cada aviso antes de hacer nada con él. `Webhook::verify()` comprueba la firma de la cabecera
`Seatsyoo-Signature` en tiempo constante, rechaza los avisos de hace más de 5 minutos (para que nadie repita
uno antiguo) y devuelve el aviso ya decodificado:

```php
use Seatsyoo\SignatureVerificationException;
use Seatsyoo\Webhook;

$cuerpo = file_get_contents('php://input');                 // el cuerpo tal cual llega, sin decodificarlo
$firma = $_SERVER['HTTP_SEATSYOO_SIGNATURE'] ?? null;

try {
    $aviso = Webhook::verify($cuerpo, $firma, (string) getenv('SEATSYOO_WEBHOOK_SECRET'));   // tolerance: 300
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

http_response_code(200);   // contesta enseguida y trabaja después
// $aviso['id'], $aviso['type'] (objects.booked, objects.released…), $aviso['data']
```

En Laravel, el cuerpo es `$request->getContent()` y la firma `$request->header('Seatsyoo-Signature')`; en
Symfony, `$request->getContent()` y `$request->headers->get('Seatsyoo-Signature')`. El mismo aviso puede llegar
dos veces: guarda los `id` que ya has procesado. Para probar tu receptor sin esperar un aviso de verdad,
`Webhook::signatureHeader($cuerpo, $secreto)` firma un cuerpo como lo hace Seatsyoo.

## Referencia

```php
$seatsyoo->events->list(chart: null)
$seatsyoo->events->create(['chartKey' => …, 'key' => …, 'name' => …, 'date' => …])
$seatsyoo->events->retrieve($evento)
$seatsyoo->events->update($evento, ['name' => …, 'date' => null, 'pricing' => …])
$seatsyoo->events->delete($evento, force: false)
$seatsyoo->events->hold($evento, $objetos, holdToken: …, channelKeys: null, ignoreChannels: null)
$seatsyoo->events->book($evento, $objetos, holdToken: null, orderId: null, channelKeys: null, ignoreChannels: null)
$seatsyoo->events->release($evento, $objetos, holdToken: null)
$seatsyoo->events->refund($evento, $orderId, objects: null)
$seatsyoo->events->changeStatus($evento, $objetos, $estado, holdToken: null, orderId: null)
$seatsyoo->events->bestAvailable($evento, $numero, categories: null, accessible: null, allowSplit: null, holdToken: null, orderId: null, channelKeys: null)
$seatsyoo->events->statuses($evento, holdToken: null)
$seatsyoo->events->retrieveObject($evento, $objeto)
$seatsyoo->events->retrieveOrder($evento, $orderId)
$seatsyoo->events->statusChanges($evento, limit: null, before: null, object: null)
$seatsyoo->events->markNotForSale($evento, keys: null, categories: null)
$seatsyoo->events->markForSale($evento, keys: null, categories: null)

$seatsyoo->holdTokens->create()
$seatsyoo->holdTokens->retrieve($token)
$seatsyoo->holdTokens->extend($token, seconds: null)

$seatsyoo->seasons->list(chart: null) · create([…]) · retrieve($temporada) · update($temporada, […]) · delete($temporada)
$seatsyoo->seasons->addEvents($temporada, eventKeys: null, events: null) · removeEvent($temporada, $evento)
$seatsyoo->seasons->statuses($temporada, holdToken: null, events: null)
$seatsyoo->seasons->hold / book / release / refund / changeStatus / bestAvailable   // como en events, con events: [...] para un abono parcial

$seatsyoo->charts->list(archived: false, q: null) · retrieve($plano)

$seatsyoo->webhooks->list() · create(['url' => …, 'types' => […]]) · update($webhook, […]) · delete($webhook)
$seatsyoo->webhooks->test($webhook) · deliveries($webhook, limit: null) · retryDelivery($webhook, $id)

$seatsyoo->workspace->retrieve()

\Seatsyoo\Webhook::verify($cuerpo, $firma, $secreto, tolerance: 300)
\Seatsyoo\Webhook::signatureHeader($cuerpo, $secreto, timestamp: null)
```

Las claves que van en la ruta (evento, objeto, pedido…) se codifican para URL solas: `'Palco 1'` va como
`Palco%201`.

## Otras rutas

Para cualquier otra ruta de `/api/v1` (informes, canales de venta, planos…), `request()`, con la misma
autenticación, errores y reintentos (solo de `GET`, `PUT` y `DELETE`). Devuelve el JSON como array o, si la
respuesta no es JSON (los `.csv`), el texto:

```php
$resumen = $seatsyoo->request('GET', '/events/hamlet-2026-11-20/report/summary');
$csv = $seatsyoo->request('GET', '/events/hamlet-2026-11-20/report/orders.csv', query: ['sep' => ';']);
$seatsyoo->request('POST', '/events/hamlet-2026-11-20/channels', ['name' => 'Taquilla', 'color' => '#e0a526']);
```

Aquí las claves de la ruta las codificas tú, con `rawurlencode()`.

## Transporte propio

El cliente hace las peticiones con un `Seatsyoo\Http\Transport`: `CurlTransport` o `StreamTransport`. Puedes
pasarle otro (el cliente HTTP de tu framework, o uno falso en tus pruebas) implementando su único método:

```php
final class MiTransporte implements \Seatsyoo\Http\Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): \Seatsyoo\Http\Response
    {
        // … devuelve new Response($status, $cabeceras, $cuerpo), o lanza TransportException si no hay respuesta.
    }
}

$seatsyoo = new Client(secretKey: …, server: …, transport: new MiTransporte());
```

## Ejemplos

- [`ejemplo/compra.php`](ejemplo/compra.php): una compra de principio a fin (retener, reservar con número de
  pedido, consultar y devolver).
- [`ejemplo/webhook.php`](ejemplo/webhook.php): un receptor de webhooks en PHP plano.

## Pruebas

Desde el repositorio de Seatsyoo, `pnpm test` en `sdk/php` lanza las pruebas unitarias y las de integración
contra un servidor de Seatsyoo de verdad levantado en memoria. Solo las unitarias: `php tests/ejecutar.php unitarias`.

## Licencia

MIT.

---

## English summary

Server-side SDK for Seatsyoo in PHP 8.1+, with no dependencies (uses ext-curl when available, PHP streams
otherwise). Install with `composer require seatsyoo/seatsyoo-php` (or `require 'autoload.php'` without
Composer).

```php
$seatsyoo = new \Seatsyoo\Client(secretKey: (string) getenv('SEATSYOO_SECRET_KEY'), server: 'https://your-seatsyoo-server');
$seatsyoo->events->book('hamlet', ['Platea-C-1'], holdToken: $token, orderId: 'order-2001');
$seatsyoo->events->retrieveOrder('hamlet', 'order-2001');
$seatsyoo->events->refund('hamlet', 'order-2001');               // whole order; pass objects for a partial refund
$event = \Seatsyoo\Webhook::verify($rawBody, $_SERVER['HTTP_SEATSYOO_SIGNATURE'] ?? null, $secret, tolerance: 300);
```

- Every method returns the API response as an associative array, exactly as documented in the API reference.
- Resources: `events`, `holdTokens`, `seasons`, `charts`, `webhooks`, `workspace`, plus `request($method, $path,
  $body, $query)` for any other `/api/v1` route. Keys in paths are URL-encoded for you.
- Errors throw `Seatsyoo\SeatsyooException` with `getStatus()` (0 when there was no response),
  `getErrorCode()` (stable API code, or `network-error` / `timeout`) and `getDetails()`.
- Retries (network errors, timeouts, 429 honouring `Retry-After` up to 60 s, 502/503/504, exponential backoff
  with jitter) only for requests that are safe to repeat: GET/PUT/DELETE, booking with an `orderId` and
  refunding a whole order.
- `Webhook::verify()` checks the `Seatsyoo-Signature` header (`t=<seconds>,v1=<hex>`, HMAC-SHA256 of
  `"<t>.<raw body>"`), accepts several `v1` values, compares in constant time, rejects anything outside the
  tolerance and returns the decoded event, or throws `Seatsyoo\SignatureVerificationException`.
- Examples in `ejemplo/` (a full purchase and a plain-PHP webhook receiver). License: MIT.
