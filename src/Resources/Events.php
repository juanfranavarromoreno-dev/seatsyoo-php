<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * Eventos y la venta: retener, reservar, liberar, devolver, mejor disponible, estados y pedidos.
 *
 * Los objetos se indican con su clave («Platea-C-1») o, en una zona de pie, con su clave y cantidad
 * (['key' => 'Pista', 'quantity' => 3]). Las acciones son todo o nada: si un objeto no se puede, no se toca
 * ninguno y se lanza una SeatsyooException 409 objects-not-available con el motivo de cada uno en getDetails().
 *
 * Los eventos no tienen precios: los pone tu web, que se los pasa al visor (opción pricing) y cobra con ellos.
 * Para calcular un importe, retrieveObject() da la categoría y las plazas de cada objeto.
 */
class Events extends ApiResource
{
    /**
     * Los eventos del espacio, por orden de creación.
     *
     * @param string|null $chart Solo los de este plano.
     *
     * @return array{events: list<array<string, mixed>>}
     */
    public function list(?string $chart = null): array
    {
        return $this->http->json('GET', '/events', null, ['chart' => $chart]);
    }

    /**
     * Crea un evento con la versión publicada de un plano.
     *
     * Sin precios: con pricing lanza InvalidArgumentException antes de llamar (el servidor lo descartaría sin
     * avisar). Los precios los pone tu web, que se los pasa al visor con su opción pricing.
     *
     * @param array<string, mixed> $params chartKey (obligatorio), key, name, date, tableModes, categoryOverrides
     *                                     (estos dos, mapas «clave => valor»; vacíos, se envían como {}).
     *
     * @return array<string, mixed> El evento.
     */
    public function create(array $params): array
    {
        $params = self::withoutPricing($params, 'el evento');

        return $this->http->json('POST', '/events', self::maps($params, ['tableModes', 'categoryOverrides']));
    }

    /** @return array<string, mixed> El evento. */
    public function retrieve(string $event): array
    {
        return $this->http->json('GET', '/events/' . self::key($event));
    }

    /**
     * Cambia un evento: key, name, date (null la quita), tableModes, tableModesChanges, categoryOverrides o
     * categoryOverridesChanges. Los mapas «clave => valor» vacíos se envían como {}.
     *
     * Los eventos no guardan precios: un cambio con pricing da 400 pricing-not-supported.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> El evento.
     */
    public function update(string $event, array $changes): array
    {
        $changes = self::maps($changes, ['tableModes', 'tableModesChanges', 'categoryOverrides', 'categoryOverridesChanges']);

        return $this->http->json('PATCH', '/events/' . self::key($event), $changes);
    }

    /**
     * Borra un evento con sus retenciones. Si tiene algo vendido da 409 event-has-sales, salvo con $force,
     * que lo borra con sus ventas y su historial.
     *
     * Se reintenta solo. Si hubo que repetirlo, la respuesta lleva "retried" => true; y si el reintento da 404
     * (el intento cuya respuesta se perdió ya lo había borrado), no lanza: responde ok.
     *
     * @return array{ok: bool, retried?: true}
     */
    public function delete(string $event, bool $force = false): array
    {
        return $this->http->delete('/events/' . self::key($event), ['force' => $force ? '1' : null]);
    }

    /**
     * Retiene objetos con un token de retención.
     *
     * Con la clave secreta no hay tope de plazas por token, pero lo que retengas con un token cuenta para el del
     * visor (las plazas por comprador del espacio, maxHoldPerToken) si después se lo pasas: el visor no podrá
     * retener más allá de ese tope (409 hold-limit).
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $channelKeys Canales desde los que se compra.
     * @param bool|null                                       $ignoreChannels true para no tener en cuenta los canales.
     *
     * @return array{objects: list<string>, seq: int}
     */
    public function hold(string $event, array $objects, string $holdToken, ?array $channelKeys = null, ?bool $ignoreChannels = null): array
    {
        return $this->http->json('POST', $this->action($event, 'hold'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
            'channelKeys' => $channelKeys,
            'ignoreChannels' => $ignoreChannels,
        ]));
    }

    /**
     * Reserva (vende) objetos: los retenidos con el token y también los libres.
     *
     * Con $orderId la reserva es idempotente: repetirla con el mismo pedido responde sin cambios (aunque el
     * token haya caducado), así que el cliente la reintenta sola si se corta. Sin pedido no se reintenta. El
     * número de pedido no puede ser '' (InvalidArgumentException).
     *
     * Si hubo que repetirla, la respuesta lleva "retried" => true. Cuando el intento que se cortó ya había
     * reservado, el servidor responde al reintento «sin cambios»; aun así, "objects" trae las claves pedidas
     * (están reservadas con ese pedido) y "seq" es 0, porque el número del cambio no se sabe. Para el detalle,
     * retrieveOrder().
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $channelKeys
     *
     * @return array{objects: list<string>, seq: int, retried?: true}
     */
    public function book(
        string $event,
        array $objects,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $channelKeys = null,
        ?bool $ignoreChannels = null
    ): array {
        $result = $this->http->json('POST', $this->action($event, 'book'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
            'orderId' => self::orderId($orderId),
            'channelKeys' => $channelKeys,
            'ignoreChannels' => $ignoreChannels,
        ]), [], $orderId !== null, $retried);

        return self::afterRetry($result, $retried, $objects);
    }

    /**
     * Libera objetos: con $holdToken, solo lo retenido con ese token; sin él, todo lo de esos objetos,
     * retenido o vendido. Para devolver lo vendido con un pedido, usa refund().
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     *
     * @return array{objects: list<string>, seq: int}
     */
    public function release(string $event, array $objects, ?string $holdToken = null): array
    {
        return $this->http->json('POST', $this->action($event, 'release'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
        ]));
    }

    /**
     * Devuelve lo vendido con un número de pedido: entero (sin $objects) o solo esos objetos (en una zona de
     * pie, con cantidad). Nunca toca otros pedidos ni retenciones. Si el pedido ya no tiene nada, responde con
     * "objects" vacío.
     *
     * La devolución entera se reintenta sola si se corta; la parcial no (repetirla devolvería otra vez): si no
     * sabes si llegó, mira antes qué queda con retrieveOrder().
     *
     * Si hubo que repetir la devolución entera, la respuesta lleva "retried" => true. Con "objects" vacío, la
     * devolución ya la había hecho el intento que se cortó (o el pedido ya estaba vacío): el pedido no tiene
     * nada, pero qué se devolvió no se sabe por esta respuesta (míralo en statusChanges() o en tus datos).
     *
     * @param list<string|array{key: string, quantity?: int}>|null $objects
     *
     * @return array{objects: list<string>, seq: int, retried?: true}
     */
    public function refund(string $event, string $orderId, ?array $objects = null): array
    {
        $result = $this->http->json('POST', $this->action($event, 'release'), self::fields([
            'orderId' => self::orderId($orderId),
            'objects' => $objects === null ? null : self::objects($objects),
        ]), [], $objects === null, $retried);

        return self::afterRetry($result, $retried);
    }

    /**
     * Pone un estado a unos objetos. Lo que hace depende del estado:
     *
     * - "free" libera. Sin $holdToken, todo lo de esos objetos (retenido o vendido, como release()) y además les
     *   quita las marcas; con $holdToken, solo lo retenido con ese token, y las marcas se quedan.
     * - "held" retiene con $holdToken (obligatorio).
     * - "booked" reserva, como book() (con $orderId, con ese pedido).
     * - Un estado propio (los del catálogo del espacio: /statuses, con Client::request()) hace lo que diga el estado
     *   de base del que cuelga:
     *   - base free (visibilidad reducida, junto al pasillo…): marca la plaza sin ocuparla; se sigue reteniendo y
     *     vendiendo, y la marca se lee con marks();
     *   - base blocked: bloquea la plaza (no se vende ni cuenta para la ocupación);
     *   - base occupied (invitación, prensa…): la ocupa como una reservada, con ese estado y con $orderId si lo das.
     *   Un estado que no está en el catálogo cuenta como occupied.
     *
     * Al retener u ocupar no mira si el objeto está a la venta ni sus canales: es para tu taquilla, no para el
     * comprador.
     *
     * Con $expected (los estados de los que se puede partir; "free" es sin estado), cada objeto cambia solo si tiene
     * ahora uno de ellos, y todos a la vez o ninguno (409 objects-not-available con unexpected-status). Sirve para
     * pasar una plaza vendida a otro estado sin liberarla antes (en el control de acceso, de "booked" a "sentado" y
     * de vuelta): conserva su pedido y no se factura otra vez. Lo que ya tiene el estado pedido no cambia (un
     * reintento). Con $channelKeys, lo de otros canales no cambia (not-in-channel).
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $expected
     * @param list<string>|null                               $channelKeys
     *
     * @return array{objects: list<string>, seq: int}
     */
    public function changeStatus(
        string $event,
        array $objects,
        string $status,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $expected = null,
        ?array $channelKeys = null
    ): array {
        return $this->http->json('POST', $this->action($event, 'change-status'), self::fields([
            'objects' => self::objects($objects),
            'status' => $status,
            'holdToken' => $holdToken,
            'orderId' => self::orderId($orderId),
            'expected' => $expected,
            'channelKeys' => $channelKeys,
        ]));
    }

    /**
     * Busca las mejores butacas libres juntas y las retiene (con $holdToken) o las reserva (sin él).
     *
     * @param int               $number     Cuántas, de 1 a 100 (el visor, además, nunca más que maxHoldPerToken; la
     *                                      clave secreta no tiene ese tope).
     * @param list<string>|null $categories Solo de estas categorías.
     * @param bool|null         $accessible true: solo butacas accesibles.
     * @param bool|null         $allowSplit true: si no hay tantas juntas, las más cercanas aunque estén separadas.
     * @param list<string>|null $channelKeys
     *
     * @return array{objects: list<string>, seq: int, contiguous: bool}
     */
    public function bestAvailable(
        string $event,
        int $number,
        ?array $categories = null,
        ?bool $accessible = null,
        ?bool $allowSplit = null,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $channelKeys = null
    ): array {
        return $this->http->json('POST', $this->action($event, 'best-available'), self::fields([
            'number' => $number,
            'categories' => $categories,
            'accessible' => $accessible,
            'allowSplit' => $allowSplit,
            'holdToken' => $holdToken,
            'orderId' => self::orderId($orderId),
            'channelKeys' => $channelKeys,
        ]));
    }

    /**
     * El estado de los objetos ocupados (los libres no salen). Con $holdToken, marca con "mine" lo suyo.
     *
     * @return array{seq: int, objects: array<string, array<string, mixed>>}
     */
    public function statuses(string $event, ?string $holdToken = null): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/statuses', null, ['holdToken' => $holdToken]);
    }

    /**
     * Todo sobre un objeto en el evento: tipo (kind), rótulos, categoría, plazas (capacity), estado, plazas
     * ocupadas, si está a la venta, su canal y cada asignación con su pedido. Con la categoría y las plazas
     * calculas el importe con tus precios (una mesa que se vende entera cuenta todas sus sillas).
     *
     * @return array<string, mixed>
     */
    public function retrieveObject(string $event, string $object): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/objects/' . self::key($object, 'La clave del objeto'));
    }

    /**
     * Lo vendido con un número de pedido: clave, plazas (quantity) y estado de cada objeto. Si no hay nada (no
     * existe o ya se devolvió), "objects" vacío. Sin importes: lo cobrado lo tienes tú con el pedido.
     *
     * @return array{orderId: string, objects: list<array{key: string, quantity: int, status: string}>}
     */
    public function retrieveOrder(string $event, string $orderId): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/orders/' . self::key($orderId, 'El número de pedido'));
    }

    /**
     * Las marcas del evento: plazas con un estado propio que cuelga de «libre» (visibilidad reducida, junto al
     * pasillo…). Se ponen con changeStatus() y no ocupan la plaza: se sigue reteniendo y vendiendo.
     *
     * La marca sigue aunque la plaza se retenga, se venda o se devuelva, así que también trae plazas vendidas. Solo
     * la quita changeStatus(…, "free") sin token, que además libera la plaza del todo (lo retenido y lo vendido);
     * otro estado con base free la sustituye. Borrar del catálogo un estado que tienen plazas da 409 status-in-use,
     * salvo con force=1 (Client::request('DELETE', '/statuses/…', query: ['force' => '1'])), que quita sus marcas.
     *
     * Para un visor en modo «manager» (tu taquilla o tu panel, que pinta cada plaza por su estado), "marks" es su
     * opción marks; el nombre, el color y el icono de cada estado van en su opción statusStyles, sacados del
     * catálogo (GET /statuses con Client::request()). El visor del comprador no usa ninguna de las dos.
     *
     * @return array{marks: array<string, string>} Clave del objeto => clave del estado.
     */
    public function marks(string $event): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/marks');
    }

    /**
     * El historial de cambios, del más reciente al más antiguo.
     *
     * @param int|null    $limit  De 1 a 1.000 (100 si no se indica).
     * @param int|null    $before El id a partir del cual seguir (para paginar).
     * @param string|null $object Solo los cambios de este objeto.
     *
     * @return array{changes: list<array<string, mixed>>}
     */
    public function statusChanges(string $event, ?int $limit = null, ?int $before = null, ?string $object = null): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/status-changes', null, [
            'limit' => $limit,
            'before' => $before,
            'object' => $object,
        ]);
    }

    /**
     * Saca de la venta objetos o categorías (no toca lo ya vendido).
     *
     * @param list<string>|null $keys
     * @param list<string>|null $categories
     *
     * @return array<string, mixed> El evento.
     */
    public function markNotForSale(string $event, ?array $keys = null, ?array $categories = null): array
    {
        return $this->http->json('POST', '/events/' . self::key($event) . '/not-for-sale', self::fields([
            'keys' => $keys,
            'categories' => $categories,
        ]));
    }

    /**
     * Vuelve a poner a la venta objetos o categorías; sin ninguno de los dos, todo.
     *
     * @param list<string>|null $keys
     * @param list<string>|null $categories
     *
     * @return array<string, mixed> El evento.
     */
    public function markForSale(string $event, ?array $keys = null, ?array $categories = null): array
    {
        return $this->http->json('POST', '/events/' . self::key($event) . '/for-sale', self::fields([
            'keys' => $keys,
            'categories' => $categories,
        ]));
    }

    /**
     * Sustituye todos los canales de venta del evento, con sus objetos. Una butaca solo puede estar en un canal.
     *
     * @param list<array{key: string, name: string, color: string, objects?: list<string>}> $channels
     *
     * @return array<string, mixed> El evento.
     */
    public function setChannels(string $event, array $channels): array
    {
        return $this->http->json('PUT', '/events/' . self::key($event) . '/channels', ['channels' => array_values($channels)]);
    }

    /**
     * Crea un canal vacío (sin $channel, la clave sale del nombre). No se reintenta: sin clave crearía dos.
     *
     * @return array<string, mixed> El evento.
     */
    public function createChannel(string $event, string $name, string $color, ?string $channel = null): array
    {
        return $this->http->json('POST', '/events/' . self::key($event) . '/channels', self::fields([
            'key' => $channel,
            'name' => $name,
            'color' => $color,
        ]));
    }

    /**
     * Cambia el nombre o el color de un canal (sus objetos no cambian).
     *
     * @param array{name?: string, color?: string} $changes
     *
     * @return array<string, mixed> El evento.
     */
    public function updateChannel(string $event, string $channel, array $changes): array
    {
        return $this->http->json('PATCH', '/events/' . self::key($event) . '/channels/' . self::key($channel, 'La clave del canal'), $changes);
    }

    /**
     * Borra un canal: sus objetos vuelven a la venta general. No se reintenta (un reintento daría 404 aunque el
     * primero lo hubiera borrado).
     *
     * @return array<string, mixed> El evento.
     */
    public function deleteChannel(string $event, string $channel): array
    {
        return $this->http->json('DELETE', '/events/' . self::key($event) . '/channels/' . self::key($channel, 'La clave del canal'), null, [], false);
    }

    /**
     * Pasa objetos a un canal o, con $channel null, los saca del suyo (vuelven a la venta general). Repetirlo da lo
     * mismo, así que se reintenta.
     *
     * @param list<string> $objects
     *
     * @return array<string, mixed> El evento.
     */
    public function assignChannel(string $event, ?string $channel, array $objects): array
    {
        return $this->http->json('POST', '/events/' . self::key($event) . '/channels/assign', [
            'channel' => $channel,
            'objects' => self::objects($objects),
        ], [], true);
    }

    /**
     * Resumen del evento: plazas por estado, por categoría (también las propias del evento) y por canal.
     *
     * @return array<string, mixed>
     */
    public function summary(string $event): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/report/summary');
    }

    /**
     * Plazas por estado agrupadas por "section", "category" o "channel".
     *
     * @return array{by: string, groups: list<array<string, mixed>>}
     */
    public function reportBy(string $event, string $by): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/report/by', null, ['by' => $by]);
    }

    /**
     * Lo vendido de cada pedido del evento.
     *
     * @return array<string, mixed>
     */
    public function reportOrders(string $event): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/report/orders');
    }

    private function action(string $event, string $action): string
    {
        return '/events/' . self::key($event) . '/actions/' . $action;
    }
}
