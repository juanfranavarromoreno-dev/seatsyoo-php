<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * El espacio de la clave secreta y su entorno de pruebas.
 *
 * Cada espacio tiene un entorno de pruebas, con sus propias claves (pk_test_… y sk_test_…): con una clave
 * sk_test_…, el cliente funciona igual pero sobre él, y nada se factura.
 */
class Workspace extends ApiResource
{
    /**
     * Los datos del espacio: name, publicKey, holdPeriodSeconds, maxHoldPerToken (las plazas que un comprador
     * puede tener retenidas a la vez en un evento desde el visor), allowedOrigins, test (si es un entorno de
     * pruebas), testEnvironment (en producción, el suyo, o null) o production (en pruebas, su espacio de
     * producción), productionLocked y appearance. secretKeys sale vacía con una clave secreta.
     *
     * @return array<string, mixed>
     */
    public function retrieve(): array
    {
        return $this->http->json('GET', '/workspace');
    }

    /**
     * Cambia el espacio: name, holdPeriodSeconds (de 60 a 86.400), maxHoldPerToken (plazas por comprador en el
     * visor, de 1 a 500; 10 si no se cambia), allowedOrigins (sustituye a la lista; [] admite cualquier web) o
     * appearance (['color' => '#rrggbb', 'icon' => …]). En un entorno de pruebas, name y appearance dan
     * 409 production-only.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> El espacio, como retrieve().
     */
    public function update(array $changes): array
    {
        return $this->http->json('PATCH', '/workspace', self::maps($changes, ['appearance']));
    }

    /**
     * Crea el entorno de pruebas del espacio (con una clave de producción). Como todo espacio nace con el suyo,
     * lo normal es que ya exista: entonces responde ['publicKey' => 'pk_test_…', 'createdAt' => …], sin clave
     * secreta (crea una en el panel, dentro de pruebas). Si lo crea, trae además secretKey (sk_test_…), que solo
     * se ve ahora. Con una clave sk_test_…, 409 production-only.
     *
     * No se reintenta: si se perdiera la respuesta que lo crea, la repetición vendría sin la clave secreta.
     *
     * @return array{publicKey: string, createdAt: string, secretKey?: string}
     */
    public function createTestEnvironment(): array
    {
        return $this->http->json('POST', '/workspace/test-environment');
    }

    /**
     * Vacía el entorno de pruebas (con su clave sk_test_…): borra sus eventos y temporadas, con sus ventas,
     * retenciones e historial, y sus tokens de retención; conserva los planos, las claves, los webhooks y los
     * ajustes. No manda avisos. Con una clave de producción, 409 not-test-environment: así no se borra nada de
     * verdad.
     *
     * @return array{ok: bool, deleted: array{events: int, seasons: int}}
     */
    public function reset(): array
    {
        return $this->http->json('POST', '/workspace/reset');
    }
}
