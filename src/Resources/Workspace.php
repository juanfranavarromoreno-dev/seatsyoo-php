<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/** El espacio de la clave secreta. */
class Workspace extends ApiResource
{
    /**
     * Los datos del espacio: nombre, clave pública, duración de las retenciones y webs autorizadas.
     *
     * @return array<string, mixed>
     */
    public function retrieve(): array
    {
        return $this->http->json('GET', '/workspace');
    }
}
