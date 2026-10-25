# Seatsyoo para PHP

SDK de servidor de Seatsyoo para PHP: crea y consulta eventos, retén y reserva butacas con tu número de
pedido, devuélvelas, busca las mejores disponibles, vende abonos de temporada, prepara y vacía tu entorno de
pruebas y verifica los webhooks.

- PHP 8.1 o posterior, sin dependencias. Usa la extensión curl si está; si no, los flujos de PHP (para
  https sin curl hace falta openssl).
- Cada método devuelve la respuesta de la API como array asociativo, tal cual la describe la referencia de
  la API (`/docs/api` en la documentación de Seatsyoo).
- Los errores son excepciones con el código estable de la API; las peticiones que es seguro repetir se
  reintentan solas.
- Seatsyoo no guarda precios: los pones tú, en tu web, por categoría. Se los pasas al visor (opción `pricing`)
  para que los enseñe y con ellos calculas lo que cobras ([Precios](#precios)).

*English summary below.*

## Instalación

Con Composer:

```sh
composer require seatsyoo/seatsyoo-php
```

Las clases se cargan con el `vendor/autoload.php` de tu proyecto, como las de cualquier otro paquete.

Sin Composer, descarga el zip de la versión que quieras (la etiqueta `vX.Y.Z` del repositorio, que lleva solo el
SDK: `src/`, `autoload.php`, `ejemplo/`, este README y la licencia), copia su carpeta en tu proyecto y carga sus
clases con su `autoload.php`, que solo registra el cargador de las clases `Seatsyoo\…`:

```php
require __DIR__ . '/seatsyoo-php/autoload.php';
```

El zip se descomprime en una carpeta con la versión en el nombre (por ejemplo `seatsyoo-php-1.0.0`): renómbrala a
`seatsyoo-php` o ajusta la ruta del `require`.

Las extensiones que usa (json, hash y pcre) vienen siempre con PHP; curl es opcional.

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
tu repositorio. Con una clave de tu entorno de pruebas (`sk_test_…`) todo funciona igual, pero sobre él
([Entorno de pruebas](#entorno-de-pruebas)). Opciones del cliente: `timeout` (segundos por intento, con el
cuerpo entero; 30 por defecto), `maxRetries` (reintentos, 2 por defecto; 0 para ninguno) y `transport` (ver
[Transporte propio](#transporte-propio)).

## Vender

El comprador elige sus butacas en el visor, que las retiene con su token de retención. La venta se hace en dos
momentos:

1. **En la caja**, tu web te manda solo el token. Tu servidor pregunta qué retiene ese token
   (`events->statuses()` con `holdToken`: lo suyo viene con `mine`), calcula el importe con tus precios y
   guarda esa lista con el pedido. Es lo que cobras.
2. **Con el pago confirmado**, reserva exactamente la lista guardada, con tu número de pedido, sin volver a
   preguntar.

```php
use Seatsyoo\SeatsyooException;

// 1. En la caja: lo que retiene el token es lo que se cobra.
$objetos = [];
foreach ($seatsyoo->events->statuses($evento, holdToken: $holdToken)['objects'] as $clave => $estado) {
    if (($estado['mine'] ?? false) === true) {
        // Siempre con la cantidad (mq: las plazas del token en una zona de pie; si no viene, 1).
        $objetos[] = ['key' => (string) $clave, 'quantity' => $estado['mq'] ?? 1];
    }
}
$importe = …;   // con tus precios (ver Precios)
// Guarda $objetos y $importe con tu pedido, y amplía la retención para que no caduque a mitad del pago.
$seatsyoo->holdTokens->extend($holdToken, seconds: 600);

// … cobra …

// 2. Con el pago confirmado: la lista guardada, tal cual. Apunta en el pedido que ya está reservado: si el aviso del
//    pago llega otra vez, no vuelvas a reservar.
try {
    $seatsyoo->events->book($evento, $objetos, holdToken: $holdToken, orderId: 'pedido-2001');
} catch (SeatsyooException $e) {
    if ($e->getStatus() < 400 || $e->getStatus() >= 500) {
        // Sin respuesta (0), un 5xx o una respuesta que no se entiende (un 2xx con invalid-response, un 3xx con
        // http-error): no se sabe si se vendió. Repítelo con el mismo pedido, que no vende dos veces.
        throw $e;
    }
    // Un 4xx (entre 400 y 499): esta reserva no ha vendido nada (es todo o nada).
    if (!in_array($e->getErrorCode(), ['objects-not-available', 'hold-token-expired', 'invalid-hold-token'], true)) {
        throw $e;   // Otro 4xx (el evento ya no existe, la clave de producción bloqueada…): revísalo, o devuelve el cobro.
    }
    // Repetirla no lo arregla. Mira antes retrieveOrder(): si trae plazas, las vendió un intento anterior cuya
    // respuesta no llegó, y no hay que devolver el cobro sino revisarlo. Si no trae nada, está cobrado y sin vender:
    // devuelve el cobro, u ofrece otras plazas en un pedido nuevo, y no emitas entradas ni vuelvas a reservar este
    // pedido. En objects-not-available, getDetails() dice qué plazas y por qué:
    // [['key' => 'Platea-C-2', 'reason' => 'held']] (held, booked, not-for-sale…).
}

// Lo vendido con ese pedido: clave, plazas y estado de cada objeto. Compáralo con lo que cobraste.
$pedido = $seatsyoo->events->retrieveOrder($evento, 'pedido-2001');

// Devolver una parte del pedido o el pedido entero.
$seatsyoo->events->refund($evento, 'pedido-2001', [['key' => 'Platea-C-2', 'quantity' => 1]]);
$seatsyoo->events->refund($evento, 'pedido-2001');
```

Por qué así: `book()` reserva los objetos que le pidas, los retenidos con el token y también los libres, así
que si reservaras la lista que manda el navegador, alguien podría añadir butacas que no ha elegido. Y entre la
caja y el pago el comprador puede seguir reteniendo con el mismo token: si volvieras a preguntar al confirmarse
el pago, reservarías más de lo cobrado (lo que retenga de más caduca solo). Por eso cada objeto va con su
cantidad: en una zona de pie, reservar sin cantidad reserva lo que el token tenga allí en ese momento. La caja
entera, con el importe, la comprobación de que lo vendido es lo cobrado y qué hacer si la reserva falla con el pago
ya cobrado (el pedido queda por devolver y no se reserva otra vez), está en [`ejemplo/tienda.php`](ejemplo/tienda.php).
Allí, un pedido ya reservado tampoco se reserva otra vez: un aviso del pago repetido recibe lo que respondió la reserva.

Las acciones son todo o nada: si un objeto no se puede, no se toca ninguno. Con `orderId`, repetir la
reserva no vende otra vez (responde `"objects": []`), aunque el token haya caducado entre medias, mientras el pedido
siga como lo dejó la reserva: si después devolviste una parte, repetirla vuelve a vender lo devuelto (si sigue libre y
el token vive). El número de pedido lo pone tu tienda, nunca el comprador, y no puede ser `''` (lanza
`InvalidArgumentException`): para «sin pedido», `null`.

Las mejores butacas libres juntas, retenidas con un token o reservadas directamente (de 1 a 100):

```php
$r = $seatsyoo->events->bestAvailable('hamlet-2026-11-20', 2, categories: ['platea'], holdToken: $holdToken);
// $r['objects'] = ['Platea-F-7', 'Platea-F-8'], $r['contiguous'] = true
```

## Precios

Seatsyoo no guarda precios: los pone la web que vende. Los eventos, los pedidos y los avisos no llevan
importes, y `events->update()` con `pricing` da `400 pricing-not-supported`. Al crear un evento
(`events->create()`) o una temporada, al cambiarla o al añadirle eventos nuevos, el servidor descartaría `pricing`
sin avisar, así que el SDK lo rechaza antes de llamar con `InvalidArgumentException`. Ten tus precios por clave de
categoría (las de tu plano), pásaselos al visor con su opción `pricing` para que los enseñe y calcula lo que
cobras en tu servidor, con la categoría y las plazas de cada objeto:

```php
$precios = ['preferente' => 45, 'platea' => 30, 'mesas' => 35, 'pista' => 18];
$importe = 0;
foreach ($objetos as $objeto) {
    $info = $seatsyoo->events->retrieveObject($evento, $objeto['key']);
    // Por plaza: una mesa que se vende entera cuenta todas sus sillas.
    $plazas = $info['kind'] === 'area' ? $objeto['quantity'] : $objeto['quantity'] * $info['capacity'];
    // Un objeto sin categoría trae null: (string) evita el aviso de PHP 8.5 por usar null como índice.
    $importe += ($precios[(string) ($info['category'] ?? '')] ?? 0) * $plazas;
}
```

## Plazas por comprador

Desde el visor, cada comprador (cada token) retiene a la vez como mucho las «plazas por comprador» del espacio
en cada evento: `maxHoldPerToken`, 10 si no lo cambias (de 1 a 500; una mesa o un palco enteros cuentan todas
sus plazas). Lo da `workspace->retrieve()` y se cambia con `workspace->update(['maxHoldPerToken' => 20])` o en
el panel. Esos topes son del visor, que usa la clave pública, y los errores que dan solo le llegan a él (a tu
web, en `onError` del visor), nunca a este SDK:

| Código | Qué pasa |
| --- | --- |
| `409 hold-limit` | El token ya tiene las plazas que admite el espacio en ese evento (en «mejor disponible», pedir más que ese tope). |
| `409 hold-limit-ip` | Los tokens creados por el visor desde esa conexión (IP) ya retienen lo que admite una conexión en ese evento: el 10 % del aforo, hasta 200 y nunca menos que `maxHoldPerToken`. |
| `429 hold-rate-limited` | Desde esa conexión (IP) se han retenido demasiadas plazas en poco tiempo: 600 cada 10 minutos (con `Retry-After`). |

Con la clave secreta no hay ninguno de estos topes: tu servidor retiene y reserva lo que necesite, y pide «mejor
disponible» hasta 100. Si creas el token en tu servidor (`holdTokens->create()`) y se lo pasas al visor, ese token
no lleva la conexión (IP) del comprador, así que no cuenta para `409 hold-limit-ip`. Los otros dos sí le afectan
cuando el visor retiene con él: `409 hold-limit` (las plazas por comprador, en las que cuenta también lo que
retengas con él desde tu servidor) y `429 hold-rate-limited` (las plazas que se retienen desde la IP del
comprador).

## Estados propios y marcas

Los estados propios del espacio (invitación, prensa, visibilidad reducida…) están en su catálogo, que se gestiona
con `request()` en `/statuses` ([Otras rutas](#otras-rutas)). Cada uno cuelga de un estado de base, y
`events->changeStatus()` hace con él lo que diga su base:

- `occupied` (invitación, prensa…): ocupa la plaza como una reservada, con ese estado (y con tu `orderId` si lo das).
- `blocked`: bloquea la plaza; no se vende ni cuenta para la ocupación.
- `free` (visibilidad reducida, junto al pasillo…): marca la plaza sin ocuparla; se sigue reteniendo y vendiendo.

```php
$seatsyoo->events->changeStatus($evento, ['Platea-G-3'], 'visibilidad-reducida');   // un estado con base free
$seatsyoo->events->marks($evento);   // ['marks' => ['Platea-G-3' => 'visibilidad-reducida']]
```

La marca sigue aunque la plaza se retenga, se venda o se devuelva, así que `marks()` también trae plazas
vendidas. Solo la quita `changeStatus($evento, $objetos, 'free')` sin token, que además libera la plaza del todo
(lo retenido y lo vendido, como `release()` sin token), y otro estado con base `free` la sustituye. Borrar del
catálogo un estado que tienen plazas (marcas incluidas) da `409 status-in-use`, salvo con `force=1`
(`request('DELETE', '/statuses/visibilidad-reducida', query: ['force' => '1'])`): entonces se quitan sus marcas, y
las plazas que tenía ocupadas o bloqueadas se quedan con su clave, que pasa a contar como ocupada.

Esto es lo que alimenta un visor en modo «manager» (tu taquilla o tu panel, que pinta cada plaza por su estado):
`events->marks($evento)['marks']` va a su opción `marks`, y el catálogo
(`request('GET', '/statuses', query: ['event' => $evento])['statuses']`), por clave de estado, a `statusStyles`:
`name` y `color` tal cual; el icono cambia de forma (`['type' => 'text', 'text' => …]` va como `['text' => …]`, y
`['type' => 'image', 'src' => …]` como `['image' => …]`; los de la colección del panel no tienen equivalente y el
visor pinta solo el color). El visor del comprador no usa ninguna de las dos.

## Errores

Todo lo que falla en una petición lanza `Seatsyoo\SeatsyooException`:

| Método | Qué da |
| --- | --- |
| `getStatus()` | El código HTTP; 0 si no hubo respuesta. |
| `getErrorCode()` | El código del error, estable: `objects-not-available`, `not-found`, `hold-token-expired`, `pricing-not-supported`…; sin respuesta, `network-error` o `timeout`; sin el error de la API (un proxy, una redirección), `http-error`; si una respuesta buena (2xx) no es JSON, `invalid-response`, con ese código. |
| `getMessage()` | Una explicación para personas, en español. Puede cambiar: no la compares. |
| `getDetails()` | Los detalles, si los hay: en `objects-not-available`, `[['key' => …, 'reason' => …]]`. |

Los códigos están en la página de errores de la documentación (`/docs/errores`). Algunos que conviene tener a mano:

- `400 pricing-not-supported`: has mandado `pricing` al cambiar un evento; Seatsyoo no guarda precios
  ([Precios](#precios)).
- `403 production-locked`: la producción del espacio aún no está activa (faltan los datos fiscales en el
  panel); mientras, usa las claves del entorno de pruebas.
- `409 production-only`: eso se hace con la clave de producción, no con una `sk_test_…`.
- `409 not-test-environment`: `workspace->reset()` con la clave de producción; solo se vacía un entorno de
  pruebas.

Un error de programación (una clave vacía, una lista de objetos vacía, `pricing` al crear un evento o una
temporada) lanza `InvalidArgumentException` antes de llamar a la API.

## Reintentos

Ante un error de red, un tiempo agotado, un `429` (esperando lo que diga `Retry-After`, como mucho 60 s) o un
`502`, `503` o `504`, el cliente repite la petición hasta `maxRetries` veces, con una espera que crece (0,5 s,
1 s, 2 s…, con algo de azar), pero solo cuando repetirla es seguro:

- las lecturas (`GET`), `PUT` y `DELETE`;
- reservar con número de pedido (`book(..., orderId: …)`, también en temporadas);
- devolver un pedido entero (`refund()` sin objetos).

Lo demás (retener, reservar sin pedido, devolver una parte, mejor disponible…) no se reintenta: repetirlo
podría hacerlo dos veces. La cuota diaria agotada (`429 quota-exceeded`) tampoco.

Un intento cuya respuesta se pierde (un corte, un plazo agotado, un `502` de un proxy) puede haber llegado:
entonces el servidor contesta al reintento como a una repetición. Para que eso no confunda:

- Si hubo que repetir `book()`, `refund()` o `delete()`, la respuesta lleva `'retried' => true`.
- Una reserva repetida que vuelve «sin cambios» trae igualmente en `objects` las claves pedidas (están
  reservadas con ese pedido); `seq` es `0`, porque el número de ese cambio no se sabe.
- Una devolución entera repetida con `objects` vacío ya estaba hecha: el pedido no tiene nada, pero qué se
  devolvió no lo dice esta respuesta (míralo en `statusChanges()` o en tus datos).
- Un borrado (`events`, `seasons` o `webhooks->delete()`) cuyo reintento da `404 not-found` ya estaba hecho:
  responde `['ok' => true, 'retried' => true]` en lugar de lanzar el 404.

```php
$r = $seatsyoo->events->book('hamlet-2026-11-20', $objetos, holdToken: $holdToken, orderId: 'pedido-2001');
// $r['objects']: lo reservado, también tras un reintento; !empty($r['retried']) si hubo que repetirla.
```

## Entorno de pruebas

Cada espacio tiene su entorno de pruebas, con sus propias claves (`pk_test_…` y `sk_test_…`): con una
`sk_test_…`, el cliente funciona igual pero sobre él, nada se factura y sus datos se quedan hasta que lo vacías.

```php
// Con la clave de producción: su clave pública y, al copiarle un plano, la misma clave de plano en pruebas.
$entorno = $seatsyoo->workspace->createTestEnvironment();   // ['publicKey' => 'pk_test_…', 'createdAt' => …]
$seatsyoo->charts->copyToTest('teatro-ejemplo');            // la última versión publicada, publicada también en pruebas

// Con la clave de pruebas: vende como en producción y, al acabar tus pruebas, vacíalo.
$pruebas = new Client(secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'), server: 'https://tu-servidor-de-seatsyoo');
$pruebas->events->create(['chartKey' => 'teatro-ejemplo', 'key' => 'ensayo']);
$pruebas->workspace->reset();   // ['ok' => true, 'deleted' => ['events' => 1, 'seasons' => 0]]
```

Todo espacio nace con su entorno de pruebas, así que `createTestEnvironment()` suele responder sin clave
secreta: créala en el panel, dentro de pruebas (Ajustes → Integración). `reset()` borra sus eventos y temporadas
con sus ventas, retenciones e historial, y sus tokens; conserva los planos, las claves, los webhooks y los
ajustes, y no manda avisos. Con la clave de producción da `409 not-test-environment`: así no se borra nada de
verdad. Los avisos de los webhooks del entorno de pruebas llevan `'test' => true`.

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
// $aviso['id'], $aviso['type'] (objects.booked, objects.released…), $aviso['data'] (sin importes)
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
$seatsyoo->events->update($evento, ['name' => …, 'date' => null, 'categoryOverridesChanges' => […]])
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
$seatsyoo->events->marks($evento)
$seatsyoo->events->markNotForSale($evento, keys: null, categories: null)
$seatsyoo->events->markForSale($evento, keys: null, categories: null)

$seatsyoo->holdTokens->create()
$seatsyoo->holdTokens->retrieve($token)
$seatsyoo->holdTokens->extend($token, seconds: null)

$seatsyoo->seasons->list(chart: null) · create([…]) · retrieve($temporada) · update($temporada, […]) · delete($temporada)
$seatsyoo->seasons->addEvents($temporada, eventKeys: null, events: null) · removeEvent($temporada, $evento)
$seatsyoo->seasons->statuses($temporada, holdToken: null, events: null)
$seatsyoo->seasons->hold / book / release / refund / changeStatus / bestAvailable   // como en events, con events: [...] para un abono parcial

$seatsyoo->charts->list(archived: false, q: null) · retrieve($plano) · copyToTest($plano)

$seatsyoo->webhooks->list() · create(['url' => …, 'types' => […]]) · update($webhook, […]) · delete($webhook)
$seatsyoo->webhooks->test($webhook) · deliveries($webhook, limit: null) · retryDelivery($webhook, $id)

$seatsyoo->workspace->retrieve() · update(['maxHoldPerToken' => …, 'holdPeriodSeconds' => …, 'allowedOrigins' => […]])
$seatsyoo->workspace->createTestEnvironment() · reset()

\Seatsyoo\Webhook::verify($cuerpo, $firma, $secreto, tolerance: 300)
\Seatsyoo\Webhook::signatureHeader($cuerpo, $secreto, timestamp: null)
```

Las claves que van en la ruta (evento, objeto, pedido…) se codifican para URL solas: `'Palco 1'` va como
`Palco%201`.

## Otras rutas

Para cualquier otra ruta de `/api/v1` (informes, canales de venta, estados propios, planos…), `request()`, con la misma
autenticación, errores y reintentos (solo de `GET`, `PUT` y `DELETE`). Devuelve el JSON como array o, si la
respuesta no es JSON (los `.csv`), el texto:

```php
$resumen = $seatsyoo->request('GET', '/events/hamlet-2026-11-20/report/summary');
$csv = $seatsyoo->request('GET', '/events/hamlet-2026-11-20/report/orders.csv', query: ['sep' => ';']);
$seatsyoo->request('POST', '/events/hamlet-2026-11-20/channels', ['name' => 'Taquilla', 'color' => '#e0a526']);
// Un estado propio que marca plazas que siguen libres (base free); las marcas se leen con events->marks().
$seatsyoo->request('POST', '/statuses', ['name' => 'Visibilidad reducida', 'color' => '#f59e0b', 'base' => 'free']);
```

Aquí las claves de la ruta las codificas tú, con `rawurlencode()`. Y un campo que la API espera como objeto
(`{"clave": valor}`) y está vacío va como `(object) []` o `new \stdClass()`: un array de PHP vacío sale como
lista (`[]`) y la API responde `400`. (En `events->create()` y `events->update()` el SDK ya lo hace por ti con
`tableModes`, `categoryOverrides` y sus `…Changes`, y en `workspace->update()` con `appearance`.) La respuesta de
`request()` va tal cual:
un `DELETE` repetido puede dar `404` aunque el primer intento borrara.

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

- [`ejemplo/tienda.php`](ejemplo/tienda.php): la caja de una tienda. En la caja, lo que retiene el token, el
  importe con tus precios y la lista guardada con el pedido; con el pago confirmado, la reserva de esa lista (y,
  si falla con el pago ya cobrado, el pedido por devolver); y la comprobación de que lo vendido es lo cobrado.
- [`ejemplo/compra.php`](ejemplo/compra.php): una compra de principio a fin con esa caja (retener, cobrar lo
  retenido, reservar con número de pedido, consultar y devolver).
- [`ejemplo/webhook.php`](ejemplo/webhook.php): un receptor de webhooks en PHP plano.

## Pruebas

Las unitarias solo necesitan PHP 8.1 o posterior, sin Composer ni nada más:

```sh
php tests/ejecutar.php unitarias     # o «composer test»
php tests/revisar.php                # sintaxis de cada .php, nada que no exista en PHP 8.1, PSR-4, composer.json…
```

Las de integración van contra un servidor de Seatsyoo de verdad, que no está en este repositorio sino en el del
servidor (privado). `tests/ejecutar.mjs`, con Node 24, levanta uno en memoria con el código de la copia de ese
repositorio a la que apunte `BUTACAS_DIR`, que ha de tener sus dependencias instaladas (`pnpm install
--frozen-lockfile` en ella), y lanza las unitarias y las de integración:

```sh
BUTACAS_DIR=/ruta/a/la/copia/del/servidor node tests/ejecutar.mjs
BUTACAS_DIR=/ruta/a/la/copia/del/servidor node tests/ejecutar.mjs integracion   # solo las de integración
```

Sin `BUTACAS_DIR`, las de integración se saltan con un aviso y las demás pasan igual; con `BUTACAS_DIR` mal puesta
(sin el servidor o sin sus dependencias), falla. Para probar con otro PHP: `PHP_BINARY=/ruta/a/php`.

En GitHub Actions ([`comprobar.yml`](.github/workflows/comprobar.yml)), en cada push y pull request: la revisión
y las unitarias con PHP 8.1, 8.2, 8.3, 8.4 y 8.5 (y con la nightly, solo para avisar), `composer validate
--strict` y el autoload de Composer. Las de integración corren solo si el repositorio tiene el secreto
`BUTACAS_TOKEN`, un token de solo lectura del repositorio del servidor: hacen checkout de su rama `main`, lo
instalan con pnpm y las lanzan con `BUTACAS_DIR`. Sin el secreto, ese trabajo sale como saltado.

## Publicar una versión

Las versiones siguen [SemVer](https://semver.org/lang/es/) y salen de las etiquetas `vX.Y.Z` del repositorio, que
Packagist lee solo: `composer.json` no lleva `version` y no hay nada que subir a mano. El código lleva su versión
en `Client::VERSION` (`src/Client.php`; va en el User-Agent de cada petición), que tiene que cuadrar con la
etiqueta.

1. Sube `Client::VERSION` (por ejemplo, de `1.0.0` a `1.1.0`): el último número para correcciones, el del medio
   para lo nuevo que no rompe nada y el primero si algo deja de funcionar como antes.
2. Pasa la revisión y las pruebas (y las de integración, si tienes el servidor), haz el commit en `main`, súbelo
   y espera a que «Comprobar» acabe en verde.
3. Comprueba que la etiqueta cuadra con el código, créala sobre ese commit y súbela:

   ```sh
   php tests/version.php v1.1.0     # «La etiqueta v1.1.0 cuadra con Client::VERSION (1.1.0)»
   git tag -a v1.1.0 -m "Versión 1.1.0"
   git push origin v1.1.0
   ```

4. Con la etiqueta, «Comprobar» vuelve a pasarlo todo y comprueba otra vez que cuadra con `Client::VERSION`.
   Packagist la publica en unos minutos (si no, «Update» en la página del paquete). Si quieres, crea también la
   versión en GitHub (Releases) con lo que trae.

Una etiqueta publicada no se mueve, que quien la haya instalado ya la tiene: si algo salió mal, se saca la
siguiente versión.

La primera vez, cuando el repositorio sea público: en [packagist.org](https://packagist.org), «Submit» con la
dirección del repositorio en GitHub, con la cuenta que vaya a mantener el paquete (la primera que publica bajo
`seatsyoo/` se queda con ese nombre), y enlaza Packagist con GitHub (su integración o su webhook) para que lea
cada etiqueta en cuanto se sube.

## Licencia

MIT: [LICENSE](LICENSE).

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
- Seatsyoo stores no prices: your website sets them per category, passes them to the viewer (`pricing` option)
  and computes the amount from each object's category and capacity (`events->retrieveObject()`; cast a `null`
  category to string, as in the README snippet, to avoid PHP 8.5's deprecation notice). Events, orders and
  webhooks carry no amounts, and `events->update()` with `pricing` fails with `400 pricing-not-supported`. The
  server would silently drop `pricing` when creating an event or a season (or updating a season, or adding new
  events to it), so the SDK rejects it beforehand with `InvalidArgumentException`.
- Selling in two steps: at checkout, ask what the hold token holds (`events->statuses()` with `holdToken`, the
  `mine` entries, always with a quantity: `mq` or 1), charge for that list and store it with the order; once paid,
  book exactly the stored list with your `orderId`. Never book the list the browser sends. If booking fails after
  payment (`409 objects-not-available`, `400 hold-token-expired` or `400 invalid-hold-token`), nothing was sold
  (actions are all or nothing): if `retrieveOrder()` shows nothing sold by an earlier attempt, refund the payment (or
  offer other seats in a new order) and never book that order again. With no response (`getStatus()` 0), a 5xx or
  a response that can't be read (a 2xx with `invalid-response`, a 3xx with `http-error`), you can't tell whether it
  sold: repeat it with the same `orderId`, which never sells twice. Any other 4xx (`getStatus()` from 400 to 499)
  sold nothing either: check it, or refund. Once booked, don't book the order again: after a partial refund, a
  repeat sells the refunded places again (if still free and the token is alive). See `ejemplo/tienda.php`.
- The viewer's caps apply to the public API only, never to the secret key: `409 hold-limit` (per token and event,
  `maxHoldPerToken`, 10 by default; read it with `workspace->retrieve()` and change it with `workspace->update()`),
  `409 hold-limit-ip` (per IP and event: 10 % of capacity, up to 200, never below `maxHoldPerToken`) and
  `429 hold-rate-limited` (600 places held per IP every 10 minutes). A token created on your server with
  `holdTokens->create()` carries no IP, so it does not count towards `409 hold-limit-ip`; when the viewer holds with
  it, `409 hold-limit` (which also counts what you hold with it from your server) and `429 hold-rate-limited`
  (places held from the buyer's IP) still apply.
- Custom statuses hang from a base status, and `events->changeStatus()` follows it: `occupied` takes the place like
  a booking, `blocked` blocks it, and `free` marks it while it stays on sale. `events->marks($event)` reads the marks;
  a mark survives holding, booking and refunding (so it may list sold places). Only `changeStatus(…, 'free')`
  without a token removes it from a place, and that also releases the place entirely (held and sold); another
  free-based status replaces it. Deleting a status that places have (marks included) fails with `409 status-in-use`
  unless you pass `force=1`, which removes its marks. For a viewer in `manager` mode, `marks()` feeds
  its `marks` option and the status catalog (`request('GET', '/statuses')`) its `statusStyles` (name and color as
  is; text and image icons change shape). The buyer's viewer uses neither.
- Test environment: `sk_test_…` keys work the same on it; `workspace->createTestEnvironment()`,
  `charts->copyToTest($chart)` and `workspace->reset()` (test keys only; `409 not-test-environment` with a
  production key).
- Errors throw `Seatsyoo\SeatsyooException` with `getStatus()` (0 when there was no response),
  `getErrorCode()` (stable API code, or `network-error` / `timeout`; `http-error` without the API's error body, such
  as a proxy or a redirect; `invalid-response` when a successful response (2xx) is not JSON, with that status) and
  `getDetails()`.
- Retries (network errors, timeouts, 429 honouring `Retry-After` up to 60 s, 502/503/504, exponential backoff
  with jitter) only for requests that are safe to repeat: GET/PUT/DELETE, booking with an `orderId` and
  refunding a whole order. When `book()`, `refund()` or `delete()` had to be repeated, the result carries
  `'retried' => true`; a repeated booking that comes back unchanged still lists the requested keys in
  `objects` (with `seq` 0), and a repeated delete answered with `404 not-found` counts as done. An empty
  `orderId` (`''`) throws `InvalidArgumentException`, like `pricing` when creating an event or a season.
  `timeout` covers the whole request, body included.
- Empty JSON objects: `events->create()`/`update()` and `workspace->update()` send empty maps as `{}`; with
  `request()`, pass `(object) []` for an empty object.
- `Webhook::verify()` checks the `Seatsyoo-Signature` header (`t=<seconds>,v1=<hex>`, HMAC-SHA256 of
  `"<t>.<raw body>"`), accepts several `v1` values, compares in constant time, rejects anything outside the
  tolerance and returns the decoded event, or throws `Seatsyoo\SignatureVerificationException`.
- Examples in `ejemplo/` (a checkout that books what it charged and flags the order for a refund if booking fails
  after payment, a full purchase and a plain-PHP webhook receiver). License: MIT.
- Testing: unit tests need PHP only (`php tests/ejecutar.php unitarias` or `composer test`; static checks with
  `php tests/revisar.php`). Integration tests run against a real Seatsyoo server taken from a checkout of the
  (private) server repository with its dependencies installed: `BUTACAS_DIR=/path/to/server node tests/ejecutar.mjs`
  (Node 24). Without `BUTACAS_DIR` they are skipped with a notice. CI runs PHP 8.1 to 8.5 (plus nightly, informative
  only) and `composer validate --strict`; the integration job runs only when the `BUTACAS_TOKEN` secret exists.
- Releasing: bump `Client::VERSION` in `src/Client.php`, check it with `php tests/version.php vX.Y.Z`, then push an
  annotated `vX.Y.Z` tag; Packagist picks it up (there is no `version` in `composer.json`). CI checks the tag
  against `Client::VERSION` again.
