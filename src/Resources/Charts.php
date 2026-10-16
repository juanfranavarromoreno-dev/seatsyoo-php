<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/** Planos (se dibujan en el diseñador del panel; por la API se consultan). */
class Charts extends ApiResource
{
    /**
     * Los planos del espacio, los cambiados más recientemente primero.
     *
     * @param bool        $archived Los archivados, en lugar de los demás.
     * @param string|null $q        Texto que contiene el nombre.
     *
     * @return array{charts: list<array<string, mixed>>}
     */
    public function list(bool $archived = false, ?string $q = null): array
    {
        return $this->http->json('GET', '/charts', null, ['archived' => $archived ? '1' : null, 'q' => $q]);
    }

    /** @return array<string, mixed> El plano. */
    public function retrieve(string $chart): array
    {
        return $this->http->json('GET', '/charts/' . self::key($chart));
    }
}
