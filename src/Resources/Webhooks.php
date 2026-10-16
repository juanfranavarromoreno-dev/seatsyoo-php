<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * Webhooks: las URL de tu servidor que reciben los avisos firmados. Para verificarlos, Seatsyoo\Webhook::verify().
 */
class Webhooks extends ApiResource
{
    /**
     * Los webhooks del espacio y los tipos de aviso que existen.
     *
     * @return array{types: list<string>, webhooks: list<array<string, mixed>>}
     */
    public function list(): array
    {
        return $this->http->json('GET', '/webhooks');
    }

    /**
     * Da de alta un webhook. La respuesta trae su secret (whsec_…): guárdalo para verificar los avisos.
     *
     * @param array<string, mixed> $params url (obligatoria), types (si no, objects.booked y objects.released) y description.
     *
     * @return array<string, mixed> El webhook.
     */
    public function create(array $params): array
    {
        return $this->http->json('POST', '/webhooks', $params);
    }

    /**
     * Cambia url, types, description o active (false lo pausa); con 'rotateSecret' => true, le da un secreto nuevo.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> El webhook.
     */
    public function update(string $webhook, array $changes): array
    {
        return $this->http->json('PATCH', $this->path($webhook), $changes);
    }

    /**
     * Da de baja un webhook. Se reintenta solo, como Events::delete(): si hubo que repetirlo, "retried" => true,
     * y el 404 de un reintento (ya estaba dado de baja) responde ok.
     *
     * @return array{ok: bool, retried?: true}
     */
    public function delete(string $webhook): array
    {
        return $this->http->delete($this->path($webhook));
    }

    /**
     * Envía un aviso de prueba (ping), aunque el webhook esté pausado.
     *
     * @return array{ok: bool}
     */
    public function test(string $webhook): array
    {
        return $this->http->json('POST', $this->path($webhook) . '/test');
    }

    /**
     * Los últimos envíos, el más reciente primero, con el aviso tal cual se envió.
     *
     * @param int|null $limit Cuántos (50 si no se indica; hasta 200).
     *
     * @return array{deliveries: list<array<string, mixed>>}
     */
    public function deliveries(string $webhook, ?int $limit = null): array
    {
        return $this->http->json('GET', $this->path($webhook) . '/deliveries', null, ['limit' => $limit]);
    }

    /**
     * Vuelve a enviar un aviso ya: lo pone pendiente con los intentos a cero.
     *
     * @return array{ok: bool}
     */
    public function retryDelivery(string $webhook, int $deliveryId): array
    {
        return $this->http->json('POST', $this->path($webhook) . '/deliveries/' . $deliveryId . '/retry');
    }

    private function path(string $webhook): string
    {
        return '/webhooks/' . self::key($webhook);
    }
}
