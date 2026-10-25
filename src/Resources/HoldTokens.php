<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * Tokens de retención. El visor crea el suyo solo; estas rutas son para cuando quieres controlarlo desde tu
 * servidor (créalo aquí y pásaselo al visor con la opción holdToken).
 */
class HoldTokens extends ApiResource
{
    /**
     * Crea un token que caduca a los holdPeriodSeconds del espacio.
     *
     * Si se lo pasas al visor, no cuenta para los topes por conexión (IP) del visor (409 hold-limit-ip), que
     * son para los tokens que crea el propio visor; el de plazas por comprador (maxHoldPerToken, 409
     * hold-limit) sí.
     *
     * @return array{token: string, expiresAt: string, expiresInSeconds: int}
     */
    public function create(): array
    {
        return $this->http->json('POST', '/hold-tokens');
    }

    /**
     * Cuándo caduca un token (expiresInSeconds 0 si ya caducó).
     *
     * @return array{token: string, expiresAt: string, expiresInSeconds: int}
     */
    public function retrieve(string $holdToken): array
    {
        return $this->http->json('GET', '/hold-tokens/' . self::key($holdToken, 'El token'));
    }

    /**
     * Amplía el token y todo lo que retiene: la nueva caducidad es «ahora + $seconds», entre 60 segundos y
     * 24 horas (sin $seconds, la duración de las retenciones del espacio). Un token caducado ya no se amplía.
     *
     * @return array{token: string, expiresAt: string, expiresInSeconds: int}
     */
    public function extend(string $holdToken, ?int $seconds = null): array
    {
        return $this->http->json('POST', '/hold-tokens/' . self::key($holdToken, 'El token') . '/extend', self::fields([
            'seconds' => $seconds,
        ]));
    }
}
