<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * Temporadas (abonos): un grupo de eventos del mismo plano que se venden a la vez.
 *
 * Las acciones son las mismas que las de un evento y se aplican a todos sus eventos de golpe (o en todos, o
 * en ninguno). En un abono parcial, $events dice qué eventos ha elegido el comprador.
 */
class Seasons extends ApiResource
{
    /**
     * @param string|null $chart Solo las de este plano.
     *
     * @return array{seasons: list<array<string, mixed>>}
     */
    public function list(?string $chart = null): array
    {
        return $this->http->json('GET', '/seasons', null, ['chart' => $chart]);
    }

    /**
     * Crea una temporada.
     *
     * @param array<string, mixed> $params name y chartKey (obligatorios), key, eventKeys y pick (abono parcial).
     *
     * @return array<string, mixed> La temporada.
     */
    public function create(array $params): array
    {
        return $this->http->json('POST', '/seasons', $params);
    }

    /** @return array<string, mixed> La temporada, con sus eventos ordenados por fecha. */
    public function retrieve(string $season): array
    {
        return $this->http->json('GET', $this->path($season));
    }

    /**
     * Cambia name, key o pick (null la vuelve completa).
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> La temporada.
     */
    public function update(string $season, array $changes): array
    {
        return $this->http->json('PATCH', $this->path($season), $changes);
    }

    /**
     * Borra la temporada; sus eventos, con sus ventas, siguen ahí.
     *
     * @return array{ok: bool}
     */
    public function delete(string $season): array
    {
        return $this->http->json('DELETE', $this->path($season));
    }

    /**
     * Añade eventos que ya existen ($eventKeys) o crea otros nuevos del mismo plano y los añade
     * ($events: [['key' => …, 'name' => …, 'date' => …]]), todo o nada.
     *
     * @param list<string>|null              $eventKeys
     * @param list<array<string, mixed>>|null $events
     *
     * @return array<string, mixed> La temporada.
     */
    public function addEvents(string $season, ?array $eventKeys = null, ?array $events = null): array
    {
        return $this->http->json('POST', $this->path($season) . '/events', self::fields([
            'eventKeys' => $eventKeys,
            'events' => $events,
        ]));
    }

    /**
     * Quita un evento de la temporada (el evento sigue existiendo).
     *
     * @return array<string, mixed> La temporada.
     */
    public function removeEvent(string $season, string $event): array
    {
        return $this->http->json('DELETE', $this->path($season) . '/events/' . self::key($event));
    }

    /**
     * Los estados combinados: un objeto ocupado en cualquiera de sus eventos sale ocupado.
     *
     * @param list<string>|null $events Los eventos elegidos, en un abono parcial.
     *
     * @return array{seq: int, objects: array<string, array<string, mixed>>}
     */
    public function statuses(string $season, ?string $holdToken = null, ?array $events = null): array
    {
        return $this->http->json('GET', $this->path($season) . '/statuses', null, [
            'holdToken' => $holdToken,
            'events' => self::joinKeys($events),
        ]);
    }

    /**
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $channelKeys
     * @param list<string>|null                               $events
     *
     * @return array{objects: list<string>, seq: int, events: int}
     */
    public function hold(
        string $season,
        array $objects,
        string $holdToken,
        ?array $channelKeys = null,
        ?bool $ignoreChannels = null,
        ?array $events = null
    ): array {
        return $this->http->json('POST', $this->action($season, 'hold'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
            'channelKeys' => $channelKeys,
            'ignoreChannels' => $ignoreChannels,
            'events' => $events,
        ]));
    }

    /**
     * Reserva en todos los eventos de la temporada. Con $orderId es idempotente y se reintenta sola.
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $channelKeys
     * @param list<string>|null                               $events
     *
     * @return array{objects: list<string>, seq: int, events: int}
     */
    public function book(
        string $season,
        array $objects,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $channelKeys = null,
        ?bool $ignoreChannels = null,
        ?array $events = null
    ): array {
        return $this->http->json('POST', $this->action($season, 'book'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
            'orderId' => $orderId,
            'channelKeys' => $channelKeys,
            'ignoreChannels' => $ignoreChannels,
            'events' => $events,
        ]), [], $orderId !== null);
    }

    /**
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $events
     *
     * @return array{objects: list<string>, seq: int, events: int}
     */
    public function release(string $season, array $objects, ?string $holdToken = null, ?array $events = null): array
    {
        return $this->http->json('POST', $this->action($season, 'release'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
            'events' => $events,
        ]));
    }

    /**
     * Devuelve un abono: lo vendido con ese pedido en todos los eventos, entero o solo esos objetos. La
     * devolución entera se reintenta sola; la parcial, no.
     *
     * @param list<string|array{key: string, quantity?: int}>|null $objects
     * @param list<string>|null                                    $events
     *
     * @return array{objects: list<string>, seq: int, events: int}
     */
    public function refund(string $season, string $orderId, ?array $objects = null, ?array $events = null): array
    {
        return $this->http->json('POST', $this->action($season, 'release'), self::fields([
            'orderId' => $orderId,
            'objects' => $objects === null ? null : self::objects($objects),
            'events' => $events,
        ]), [], $objects === null);
    }

    /**
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $events
     *
     * @return array{objects: list<string>, seq: int, events: int}
     */
    public function changeStatus(
        string $season,
        array $objects,
        string $status,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $events = null
    ): array {
        return $this->http->json('POST', $this->action($season, 'change-status'), self::fields([
            'objects' => self::objects($objects),
            'status' => $status,
            'holdToken' => $holdToken,
            'orderId' => $orderId,
            'events' => $events,
        ]));
    }

    /**
     * @param list<string>|null $categories
     * @param list<string>|null $channelKeys
     * @param list<string>|null $events
     *
     * @return array{objects: list<string>, seq: int, contiguous: bool, events: int}
     */
    public function bestAvailable(
        string $season,
        int $number,
        ?array $categories = null,
        ?bool $accessible = null,
        ?bool $allowSplit = null,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $channelKeys = null,
        ?array $events = null
    ): array {
        return $this->http->json('POST', $this->action($season, 'best-available'), self::fields([
            'number' => $number,
            'categories' => $categories,
            'accessible' => $accessible,
            'allowSplit' => $allowSplit,
            'holdToken' => $holdToken,
            'orderId' => $orderId,
            'channelKeys' => $channelKeys,
            'events' => $events,
        ]));
    }

    private function path(string $season): string
    {
        return '/seasons/' . self::key($season);
    }

    private function action(string $season, string $action): string
    {
        return $this->path($season) . '/actions/' . $action;
    }
}
