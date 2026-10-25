<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/** Planos (se dibujan en el diseñador del panel; por la API se consultan y se copian al entorno de pruebas). */
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

    /**
     * Copia un plano de producción a su entorno de pruebas con la misma clave (con una clave de producción): su
     * borrador y, si está publicado, su última versión, que se publica también en pruebas. Así tus eventos de
     * pruebas usan la misma clave de plano que los de verdad. Si la copia ya estaba, la sustituye (lo cambiado en
     * ella se pierde) y "replaced" es true.
     *
     * Errores: 409 test-environment-missing, 409 production-only (con una clave sk_test_…), 403 quota-exceeded y,
     * al sustituir, los de publicar (409 objects-in-use si quita objetos con ventas o retenciones en pruebas).
     *
     * @return array<string, mixed> El plano en pruebas, como retrieve(), con replaced.
     */
    public function copyToTest(string $chart): array
    {
        return $this->http->json('POST', '/charts/' . self::key($chart) . '/copy-to-test');
    }
}
