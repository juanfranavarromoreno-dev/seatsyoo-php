<?php

declare(strict_types=1);

namespace Seatsyoo\Http;

/** Transporte con la extensión curl (el que usa el cliente si está disponible). */
final class CurlTransport implements Transport
{
    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new \LogicException('La extensión curl de PHP no está disponible: usa StreamTransport');
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // Sin «Expect: 100-continue»: el cuerpo va en la misma petición.
        $lines[] = 'Expect:';

        $responseHeaders = [];
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('No se pudo preparar la petición con curl');
        }
        $milliseconds = max(1, (int) ceil($timeout * 1000));
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // La ruta tal cual: sin esto, curl quita «/.» y «/..» aunque vayan codificados (%2E), y una clave «.»
            // o «..» acabaría en otra ruta.
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_CONNECTTIMEOUT_MS => min($milliseconds, 10000),
            // Necesario para plazos de menos de un segundo en algunos sistemas.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (strncmp($trimmed, 'HTTP/', 5) === 0) {
                    // Una línea de estado nueva (tras un 100 Continue): empiezan sus cabeceras.
                    $responseHeaders = [];
                } elseif (strpos($trimmed, ':') !== false) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);
        if (!is_string($result)) {
            $errno = curl_errno($handle);
            $message = curl_error($handle);
            throw new TransportException(
                $message !== '' ? $message : 'Error de curl ' . $errno,
                $errno === CURLE_OPERATION_TIMEDOUT,
            );
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new Response($status, $responseHeaders, $result);
    }
}
