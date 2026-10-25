<?php

declare(strict_types=1);

namespace Seatsyoo\Resources;

/**
 * Planos (se dibujan en el diseñador del panel o en el diseñador incrustado en tu web; por la API se crean, se
 * consultan, se publican, se duplican, se copian al entorno de pruebas y se abren sesiones de ese diseñador).
 */
class Charts extends ApiResource
{
    /**
     * Crea un plano, vacío (para dibujarlo en el diseñador) o con un documento. Sin $key, un UUID. No se reintenta.
     *
     * @param array<string, mixed>|null $document
     *
     * @return array<string, mixed> El plano.
     */
    public function create(string $name, ?string $key = null, ?array $document = null): array
    {
        return $this->http->json('POST', '/charts', self::fields([
            'name' => $name,
            'key' => $key,
            'document' => $document,
        ]));
    }

    /**
     * Publica el borrador como una versión nueva; sus eventos pasan a ella. 409 objects-in-use si quita o renombra
     * objetos con ventas o retenciones.
     *
     * @return array<string, mixed> El plano, con "version".
     */
    public function publish(string $chart): array
    {
        return $this->http->json('POST', '/charts/' . self::key($chart) . '/publish');
    }

    /**
     * Copia un plano en el mismo espacio, con otra clave (como borrador). No se reintenta: crearía dos copias.
     *
     * @return array<string, mixed> La copia.
     */
    public function duplicate(string $chart): array
    {
        return $this->http->json('POST', '/charts/' . self::key($chart) . '/duplicate');
    }

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

    /**
     * Abre una sesión del diseñador incrustado para el plano: su "token" (dsg_…) es lo que tu web pasa a
     * Seatsyoo.designer (o, sin el SDK del navegador, "url" en un iframe); nunca la clave secreta. Ábrela cuando
     * alguien con permiso para editar ese plano va a diseñar, no antes. No se reintenta: cada intento abriría otra.
     *
     * Errores: 404 si el plano no existe, 409 chart-archived, 409 too-many-designer-sessions (1000 a la vez por espacio)
     * y 400 con un expiresIn fuera de 300–86400.
     *
     * @param bool|null $publish   Si el diseñador puede publicar (por defecto, sí). Con false, lo dibujado queda como
     *                             borrador.
     * @param int|null  $expiresIn Segundos que vale el token, de 300 a 86400 (por defecto, 3600).
     *
     * @return array{token: string, url: string, expiresAt: string, chart: array{key: string, name: string}, canPublish: bool}
     */
    public function designerSession(string $chart, ?bool $publish = null, ?int $expiresIn = null): array
    {
        return $this->http->json('POST', '/charts/' . self::key($chart) . '/designer-sessions', self::fields([
            'publish' => $publish,
            'expiresIn' => $expiresIn,
        ]));
    }
}
