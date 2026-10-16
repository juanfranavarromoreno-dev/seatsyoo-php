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
     * Una lista de claves separadas por comas para la dirección (events=a,b).
     *
     * @param list<string>|null $keys
     */
    protected static function joinKeys(?array $keys): ?string
    {
        return $keys === null ? null : implode(',', $keys);
    }
}
