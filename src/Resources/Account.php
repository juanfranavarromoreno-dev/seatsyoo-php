<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * Con una clave de cuenta (ak_…, la da Seatsyoo para tu espacio principal), los espacios de tus clientes: crearlos,
 * renombrarlos, listarlos y ver lo que emite cada uno. Tú eres el cliente de Seatsyoo: tus datos fiscales son los de tu
 * espacio principal y se te factura lo de todos. Para trabajar en uno de ellos, crea otro Client con su clave secreta.
 */
class Account extends ApiResource
{
    /**
     * Crea el espacio de un cliente, con su entorno de pruebas. Sus claves secretas ("secretKey", "testSecretKey")
     * solo vienen ahora: guárdalas. No se reintenta: crearía dos espacios. Mientras tu espacio principal no tenga sus
     * datos fiscales, "productionLocked" es true y su producción no responde (las pruebas, sí).
     *
     * @return array{name: string, publicKey: string, testPublicKey: string|null, suspended: bool, productionLocked: bool, createdAt: string, secretKey: string, testSecretKey: string}
     */
    public function createWorkspace(string $name): array
    {
        return $this->http->json('POST', '/account/workspaces', ['name' => $name]);
    }

    /**
     * Los espacios de tus clientes, por orden de alta, sin claves secretas.
     *
     * @return array{workspaces: list<array{name: string, publicKey: string, testPublicKey: string|null, suspended: bool, productionLocked: bool, createdAt: string}>}
     */
    public function workspaces(): array
    {
        return $this->http->json('GET', '/account/workspaces');
    }

    /**
     * Cambia el nombre de uno (su entorno de pruebas lo sigue). 404 si no es de tus clientes.
     *
     * @return array{name: string, publicKey: string, testPublicKey: string|null, suspended: bool, productionLocked: bool, createdAt: string}
     */
    public function renameWorkspace(string $publicKey, string $name): array
    {
        return $this->http->json('PATCH', '/account/workspaces/' . self::key($publicKey, 'La clave pública'), ['name' => $name], [], true);
    }

    /**
     * Lo que ha emitido cada espacio en un mes (AAAA-MM; sin él, el actual en hora de España), que es lo que se te
     * factura.
     *
     * @return array{month: string, timeZone: string, total: int, workspaces: list<array<string, mixed>>}
     */
    public function usage(?string $month = null): array
    {
        return $this->http->json('GET', '/account/usage', null, ['month' => $month]);
    }
}
