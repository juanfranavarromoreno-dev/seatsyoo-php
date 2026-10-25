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
     * Sin precios: con pricing lanza InvalidArgumentException antes de llamar (el servidor lo descartaría sin
     * avisar). Los precios de un abono los pone tu web.
     *
     * @param array<string, mixed> $params chartKey (obligatorio); name (sin él, «Temporada N» con la clave
     *                                     «temporada-N»), key, eventKeys y pick (abono parcial).
     *
     * @return array<string, mixed> La temporada, con parentKey (la madre si es parcial) y partialSeasons.
     */
    public function create(array $params): array
    {
        return $this->http->json('POST', '/seasons', self::withoutPricing($params, 'la temporada'));
    }

    /** @return array<string, mixed> La temporada, con sus eventos por fecha, parentKey y partialSeasons. */
    public function retrieve(string $season): array
    {
        return $this->http->json('GET', $this->path($season));
    }

    /**
     * Cambia name, key o pick (null la vuelve completa). Con pricing lanza InvalidArgumentException antes de llamar.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> La temporada, con parentKey (la madre si es parcial) y partialSeasons.
     */
    public function update(string $season, array $changes): array
    {
        return $this->http->json('PATCH', $this->path($season), self::withoutPricing($changes, 'los cambios de la temporada'));
    }

    /**
     * Borra la temporada y sus temporadas parciales; sus eventos, con sus ventas, siguen ahí. Se reintenta solo, como Events::delete():
     * si hubo que repetirlo, "retried" => true, y el 404 de un reintento (ya estaba borrada) responde ok.
     *
     * @return array{ok: bool, retried?: true}
     */
    public function delete(string $season): array
    {
        return $this->http->delete($this->path($season));
    }

    /**
     * Añade eventos que ya existen ($eventKeys) o crea otros nuevos del mismo plano y los añade
     * ($events: [['key' => …, 'name' => …, 'date' => …]]), todo o nada. Un evento nuevo con pricing lanza
     * InvalidArgumentException antes de llamar, como Events::create().
     *
     * @param list<string>|null              $eventKeys
     * @param list<array<string, mixed>>|null $events
     *
     * @return array<string, mixed> La temporada, con parentKey (la madre si es parcial) y partialSeasons.
     */
    public function addEvents(string $season, ?array $eventKeys = null, ?array $events = null): array
    {
        foreach ($events ?? [] as $event) {
            if (is_array($event)) {
                self::withoutPricing($event, 'los eventos nuevos de la temporada');
            }
        }

        return $this->http->json('POST', $this->path($season) . '/events', self::fields([
            'eventKeys' => $eventKeys,
            'events' => $events,
        ]));
    }

    /**
     * Quita un evento de la temporada (el evento sigue existiendo) y, si no es parcial, también de sus
     * temporadas parciales. Repetirlo no da error: responde la
     * temporada igual.
     *
     * @return array<string, mixed> La temporada, con parentKey (la madre si es parcial) y partialSeasons.
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
     * Reserva en todos los eventos de la temporada. Con $orderId es idempotente y se reintenta sola; como en
     * Events::book(), tras un reintento la respuesta lleva "retried" => true y, si el intento que se cortó ya
     * había reservado, "objects" son las claves pedidas y "seq" 0. El número de pedido no puede ser ''.
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $channelKeys
     * @param list<string>|null                               $events
     *
     * @return array{objects: list<string>, seq: int, events: int, retried?: true}
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
        $result = $this->http->json('POST', $this->action($season, 'book'), self::fields([
            'objects' => self::objects($objects),
            'holdToken' => $holdToken,
            'orderId' => self::orderId($orderId),
            'channelKeys' => $channelKeys,
            'ignoreChannels' => $ignoreChannels,
            'events' => $events,
        ]), [], $orderId !== null, $retried);

        return self::afterRetry($result, $retried, $objects);
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
     * devolución entera se reintenta sola (tras un reintento, "retried" => true, como en Events::refund());
     * la parcial, no.
     *
     * @param list<string|array{key: string, quantity?: int}>|null $objects
     * @param list<string>|null                                    $events
     *
     * @return array{objects: list<string>, seq: int, events: int, retried?: true}
     */
    public function refund(string $season, string $orderId, ?array $objects = null, ?array $events = null): array
    {
        $result = $this->http->json('POST', $this->action($season, 'release'), self::fields([
            'orderId' => self::orderId($orderId),
            'objects' => $objects === null ? null : self::objects($objects),
            'events' => $events,
        ]), [], $objects === null, $retried);

        return self::afterRetry($result, $retried);
    }

    /**
     * Pone un estado en todos los eventos de la temporada, como Events::changeStatus(): también las marcas de los
     * estados propios con base free, que se leen evento a evento con Events::marks().
     *
     * Con $expected y $channelKeys, como en Events::changeStatus(): en todos los eventos a la vez o en ninguno.
     *
     * @param list<string|array{key: string, quantity?: int}> $objects
     * @param list<string>|null                               $events
     * @param list<string>|null                               $expected
     * @param list<string>|null                               $channelKeys
     *
     * @return array{objects: list<string>, seq: int, events: int}
     */
    public function changeStatus(
        string $season,
        array $objects,
        string $status,
        ?string $holdToken = null,
        ?string $orderId = null,
        ?array $events = null,
        ?array $expected = null,
        ?array $channelKeys = null
    ): array {
        return $this->http->json('POST', $this->action($season, 'change-status'), self::fields([
            'objects' => self::objects($objects),
            'status' => $status,
            'holdToken' => $holdToken,
            'orderId' => self::orderId($orderId),
            'events' => $events,
            'expected' => $expected,
            'channelKeys' => $channelKeys,
        ]));
    }

    /**
     * Crea una temporada parcial (un abono de algunos de sus eventos) dentro de esta. No se reintenta: sin clave
     * crearía dos.
     *
     * @param list<string> $events Las claves de sus eventos, todos de la temporada.
     *
     * @return array<string, mixed> La temporada parcial.
     */
    public function createPartial(string $season, array $events, ?string $key = null, ?string $name = null): array
    {
        return $this->http->json('POST', '/seasons/' . self::key($season) . '/partial-seasons', self::fields([
            'key' => $key,
            'name' => $name,
            'events' => array_values($events),
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
            'orderId' => self::orderId($orderId),
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
