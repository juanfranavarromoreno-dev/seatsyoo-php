<?php

declare(strict_types=1);

namespace Seatsyoo\Http;

/**
 * Transporte con los flujos de PHP (fopen sobre http:// y https://), para cuando no está la extensión curl.
 * Para https hace falta la extensión openssl.
 *
 * El plazo es el de la petición entera, como en curl: un servidor que suelta el cuerpo poco a poco no lo
 * alarga. (Lo único que los flujos de PHP no dejan acotar del todo es la espera de las cabeceras: ahí el plazo
 * cuenta para conectar y para cada línea que se lee.)
 */
final class StreamTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Connection: close';
        $http = [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
            'timeout' => $timeout,
            'protocol_version' => 1.1,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $context = stream_context_create(['http' => $http]);

        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        $started = microtime(true);
        $contents = false;
        $meta = null;
        $timedOut = false;
        try {
            $stream = fopen($url, 'rb', false, $context);
            if (is_resource($stream)) {
                $contents = self::read($stream, $started + $timeout, $timedOut);
                $meta = stream_get_meta_data($stream);
                fclose($stream);
            }
        } finally {
            restore_error_handler();
        }

        if ($meta === null) {
            $message = self::clean($error ?? 'No se pudo conectar');
            // Si el plazo se agota esperando la respuesta, PHP solo dice «HTTP request failed!»: lo delata el tiempo.
            $timedOut = self::looksLikeTimeout($message) || microtime(true) - $started >= $timeout * 0.95;
            throw new TransportException($timedOut ? 'Sin respuesta a tiempo' : $message, $timedOut);
        }
        if ($timedOut || !empty($meta['timed_out'])) {
            throw new TransportException('Sin respuesta a tiempo', true);
        }
        if ($contents === false) {
            throw new TransportException(self::clean($error ?? 'La respuesta se cortó'));
        }

        // wrapper_data: la línea de estado y las cabeceras (puede haber varias respuestas, como un 100 Continue).
        $status = 0;
        $responseHeaders = [];
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
            }
        }
        if ($status === 0) {
            throw new TransportException('Respuesta HTTP no válida');
        }

        return new Response($status, $responseHeaders, $contents);
    }

    /**
     * Lee el cuerpo entero sin pasar de $deadline: antes de cada lectura, el plazo del flujo es lo que queda.
     *
     * @param resource $stream
     *
     * @return string|false El cuerpo (o lo leído hasta agotar el plazo, con $timedOut a true), o false si falla.
     */
    private static function read($stream, float $deadline, bool &$timedOut)
    {
        $contents = '';
        while (!feof($stream)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;

                return $contents;
            }
            $seconds = (int) floor($remaining);
            stream_set_timeout($stream, $seconds, min(999999, max(1, (int) round(($remaining - $seconds) * 1000000))));
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                return false;
            }
            $contents .= $chunk;
            if (!empty(stream_get_meta_data($stream)['timed_out'])) {
                $timedOut = true;

                return $contents;
            }
        }

        return $contents;
    }

    /** Quita el prefijo «fopen(…): » que PHP pone a sus avisos. */
    private static function clean(string $message): string
    {
        return (string) preg_replace('/^fopen\([^)]*\):\s*/', '', $message);
    }

    private static function looksLikeTimeout(string $message): bool
    {
        $lower = strtolower($message);

        return strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false;
    }
}
