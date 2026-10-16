<?php

declare(strict_types=1);

namespace Seatsyoo\Http;

/**
 * Lo que hace de verdad una petición HTTP. El SDK trae dos: CurlTransport (si está la extensión curl) y
 * StreamTransport (con los flujos de PHP). Puedes pasar el tuyo al cliente, por ejemplo para usar el cliente
 * HTTP de tu framework o para tus pruebas.
 *
 * Un transporte no sigue redirecciones ni reintenta: de eso se encarga el cliente.
 */
interface Transport
{
    /**
     * @param string                $method  GET, POST, PUT, PATCH o DELETE.
     * @param string                $url     La dirección completa.
     * @param array<string, string> $headers Cabeceras, nombre => valor.
     * @param string|null           $body    El cuerpo (JSON) o null si no lleva.
     * @param float                 $timeout Plazo de la petición, en segundos.
     *
     * @throws TransportException Si no hay respuesta: no se pudo conectar, se cortó o se agotó el plazo.
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
