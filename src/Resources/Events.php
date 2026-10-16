<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * Eventos y la venta: retener, reservar, liberar, devolver, mejor disponible, estados y pedidos.
 *
 * Los objetos se indican con su clave («Platea-C-1») o, en una zona de pie, con su clave y cantidad
 * (['key' => 'Pista', 'quantity' => 3]). Las acciones son todo o nada: si un objeto no se puede, no se toca
 * ninguno y se lanza una SeatsyooException 409 objects-not-available con el motivo de cada uno en getDetails().
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
     * @param array<string, mixed> $params chartKey (obligatorio), key, name, date, tableModes, categoryOverrides.
     *
     * @return array<string, mixed> El evento.
     */
    public function create(array $params): array
    {
        return $this->http->json('POST', '/events', $params);
    }

    /** @return array<string, mixed> El evento. */
    public function retrieve(string $event): array
    {
        return $this->http->json('GET', '/events/' . self::key($event));
    }

    /**
     * Cambia un evento: key, name, date (null la quita), tableModes, tableModesChanges, categoryOverrides,
     * categoryOverridesChanges o pricing (null quita los precios).
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> El evento.
     */
    public function update(string $event, array $changes): array
    {
        return $this->http->json('PATCH', '/events/' . self::key($event), $changes);
    }

    /**
     * Borra un evento con sus retenciones. Si tiene algo vendido da 409 event-has-sales, salvo con $force,
     * que lo borra con sus ventas y su historial.
     *
     * @return array{ok: bool}
     */
    public function delete(string $event, bool $force = false): array
    {
        return $this->http->json('DELETE', '/events/' . self::key($event), null, ['force' => $force ? '1' : null]);
    }

    /**
     * Retiene objetos con un token de retención.
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
     * token haya caducado), así que el cliente la reintenta sola si se corta. Sin pedido no se reintenta.
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $channelKeys
     *
     * @return array{objects: list<string>, seq: int}
     */
    public function book(
        string $event,
        array $objects,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $channelKeys = null,
        ?bool $ignoreChannels = null
    ): array {
        return $this->http->json('POST', $this->action($event, 'book'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
            'orderId' => $orderId,
            'channelKeys' => $channelKeys,
            'ignoreChannels' => $ignoreChannels,
        ]), [], $orderId !== null);
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
     * @param list<string|array{key: string, quantity?: int}>|null $objects
     *
     * @return array{objects: list<string>, seq: int}
     */
    public function refund(string $event, string $orderId, ?array $objects = null): array
    {
        return $this->http->json('POST', $this->action($event, 'release'), self::fields([
            'orderId' => $orderId,
            'objects' => $objects === null ? null : self::objects($objects),
        ]), [], $objects === null);
    }

    /**
     * Pone un estado: "free" libera, "held" retiene (con $holdToken), "booked" o un estado propio
     * (invitacion, prensa…) reserva con ese estado.
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     *
     * @return array{objects: list<string>, seq: int}
     */
    public function changeStatus(string $event, array $objects, string $status, ?string $holdToken = null, ?string $orderId = null): array
    {
        return $this->http->json('POST', $this->action($event, 'change-status'), self::fields([
            'objects' => self::objects($objects),
            'status' => $status,
            'holdToken' => $holdToken,
            'orderId' => $orderId,
        ]));
    }

    /**
     * Busca las mejores butacas libres juntas y las retiene (con $holdToken) o las reserva (sin él).
     *
     * @param int               $number     Cuántas, de 1 a 100.
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
            'orderId' => $orderId,
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
     * Todo sobre un objeto en el evento: tipo, categoría, estado, precio y cada asignación con su pedido.
     *
     * @return array<string, mixed>
     */
    public function retrieveObject(string $event, string $object): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/objects/' . self::key($object, 'La clave del objeto'));
    }

    /**
     * Lo vendido con un número de pedido (con el importe de cada objeto si el evento tiene precios). Si no hay
     * nada (no existe o ya se devolvió), "objects" vacío.
     *
     * @return array{orderId: string, objects: list<array<string, mixed>>}
     */
    public function retrieveOrder(string $event, string $orderId): array
    {
        return $this->http->json('GET', '/events/' . self::key($event) . '/orders/' . self::key($orderId, 'El número de pedido'));
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

    private function action(string $event, string $action): string
    {
        return '/events/' . self::key($event) . '/actions/' . $action;
    }
}
