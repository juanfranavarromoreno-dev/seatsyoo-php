<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

use Seatsyoo\Http\Requester;

/**
 * Base de los grupos de rutas ($seatsyoo->events, ->seasons…).
 *
 * @internal
 */
abstract class ApiResource
{
    protected Requester $http;

    public function __construct(Requester $http)
    {
        $this->http = $http;
    }

    /**
     * Una clave dentro de la ruta, codificada para URL («Palco 1» → «Palco%201»).
     */
    protected static function key(string $value, string $what = 'La clave'): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException($what . ' no puede estar vacía');
        }
        // «.» y «..» se codifican del todo: si no, el cliente HTTP los trataría como «este directorio» y «el de arriba».
        if ($value === '.' || $value === '..') {
            return str_replace('.', '%2E', $value);
        }

        return rawurlencode($value);
    }

    /**
     * Los campos de un cuerpo sin los que no se han indicado (null).
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected static function fields(array $fields): array
    {
        return array_filter($fields, static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * Una lista de objetos: claves («Platea-A-1») o, en una zona de pie, ['key' => 'Pista', 'quantity' => 3].
     *
     * @param array<int|string, string|array{key: string, quantity?: int}> $objects
     *
     * @return list<string|array{key: string, quantity?: int}>
     */
    protected static function objects(array $objects): array
    {
        if ($objects === []) {
            throw new \InvalidArgumentException('Indica al menos un objeto');
        }

        return array_values($objects);
    }

    /**
     * Un número de pedido, si lo hay. Vacío es un error: la API lo tomaría como «sin pedido», así que la reserva
     * dejaría de ser idempotente y repetirla vendería otra vez las plazas de una zona de pie.
     */
    protected static function orderId(?string $orderId): ?string
    {
        if ($orderId === '') {
            throw new \InvalidArgumentException('El número de pedido (orderId) no puede estar vacío: pasa null si no hay pedido');
        }

        return $orderId;
    }

    /**
     * La respuesta de una reserva con pedido o de una devolución entera, que el cliente reintenta solo.
     *
     * Si un intento llegó al servidor pero su respuesta se perdió (un corte, un plazo agotado, un 502 de un
     * proxy), el servidor contesta al reintento como a una repetición: «sin cambios», "objects" vacío y "seq" 0.
     * Para que no parezca una venta vacía, cuando hubo que repetir la petición la respuesta lleva
     * "retried" => true y, en una reserva que vuelve sin objetos, "objects" son las claves pedidas: con un
     * reintento sin cambios, el servidor garantiza que todas están reservadas con ese pedido. "seq" se queda en
     * 0 (el cambio se hizo en el intento perdido y su número no se sabe; 0 nunca hace descartar uno posterior).
     *
     * @param array<mixed>                                         $result
     * @param list<string|array{key: string, quantity?: int}>|null $objects Lo pedido, en una reserva.
     *
     * @return array<mixed>
     */
    protected static function afterRetry(array $result, bool $retried, ?array $objects = null): array
    {
        if (!$retried) {
            return $result;
        }
        if ($objects !== null && ($result['objects'] ?? null) === []) {
            $result['objects'] = self::keysOf($objects);
        }
        $result['retried'] = true;

        return $result;
    }

    /**
     * Las claves de una lista de objetos, sin repetir y en su orden (como las devuelve la API).
     *
     * @param array<int|string, string|array{key: string, quantity?: int}> $objects
     *
     * @return list<string>
     */
    protected static function keysOf(array $objects): array
    {
        $keys = [];
        foreach ($objects as $object) {
            $key = is_array($object) ? ($object['key'] ?? null) : $object;
            if (is_string($key)) {
                $keys[$key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * Los campos que la API espera como objeto JSON ({"clave": valor}) van como {} cuando están vacíos: un
     * array de PHP vacío saldría como lista ([]) y la API lo rechazaría con un 400.
     *
     * @param array<string, mixed> $fields
     * @param list<string>         $maps   Los campos que son objetos.
     *
     * @return array<string, mixed>
     */
    protected static function maps(array $fields, array $maps): array
    {
        foreach ($maps as $name) {
            if (array_key_exists($name, $fields) && $fields[$name] === []) {
                $fields[$name] = new \stdClass();
            }
        }

        return $fields;
    }

    /**
     * Una lista de claves separadas por comas para la dirección (events=a,b).
     *
     * @param list<string>|null $keys
     */
    protected static function joinKeys(?array $keys): ?string
    {
        return $keys === null ? null : implode(',', $keys);
    }
}
