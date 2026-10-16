<?php

declare(strict_types=1);

namespace Seatsyoo\Http;

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;

/**
 * Hace las peticiones a /api/v1: autenticación, JSON, errores y reintentos.
 *
 * Reintenta, con una espera que crece (y algo de azar para no sincronizar a muchos clientes), ante un error
 * de red, un tiempo agotado, un 429 (esperando lo que diga Retry-After, con un tope) y un 502, 503 o 504; pero
 * solo las peticiones que es seguro repetir: GET, PUT y DELETE, reservar con número de pedido y devolver un
 * pedido entero. Lo demás no se reintenta, porque repetirlo podría hacer dos veces lo mismo.
 *
 * @internal Úsalo a través de Seatsyoo\Client.
 */
final class Requester
{
    /** Espera máxima ante un 429, aunque Retry-After pida más (los límites son por minuto). */
    public const MAX_RETRY_AFTER = 60.0;
    /** Primera espera entre intentos, en segundos; se duplica en cada uno. */
    public const BASE_DELAY = 0.5;
    /** Espera máxima entre intentos (sin contar Retry-After), en segundos. */
    public const MAX_DELAY = 8.0;

    private const RETRY_STATUSES = [429, 502, 503, 504];

    private string $secretKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private Transport $transport;
    /** @var callable(float): void */
    private $sleep;
    /** @var callable(): float */
    private $random;

    /**
     * @param callable(float): void|null $sleep  Cómo esperar (segundos); para pruebas.
     * @param callable(): float|null     $random Número al azar entre 0 y 1; para pruebas.
     */
    public function __construct(
        string $secretKey,
        string $server,
        float $timeout,
        int $maxRetries,
        Transport $transport,
        ?callable $sleep = null,
        ?callable $random = null
    ) {
        if (trim($secretKey) === '') {
            throw new \InvalidArgumentException('Falta la clave secreta (sk_…)');
        }
        $server = rtrim(trim($server), '/');
        if (preg_match('#^https?://[^/\s?\#]+(/[^\s?\#]*)?$#i', $server) !== 1) {
            throw new \InvalidArgumentException('La dirección del servidor ha de empezar por https:// (o http://), como https://tu-dominio.com');
        }
        if (!($timeout > 0)) {
            throw new \InvalidArgumentException('El plazo (timeout) ha de ser mayor que 0 segundos');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries no puede ser negativo');
        }
        $this->secretKey = trim($secretKey);
        $this->baseUrl = preg_replace('#/api/v1$#', '', $server) . '/api/v1';
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->transport = $transport;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1000000));
            }
        };
        $this->random = $random ?? static function (): float {
            return mt_rand() / mt_getrandmax();
        };
    }

    /**
     * Hace una petición y devuelve la respuesta decodificada: un array con el JSON o, si la respuesta no es
     * JSON (los informes .csv), el texto.
     *
     * @param array<string, mixed>|null      $body  El cuerpo, que se envía como JSON; null para no enviar cuerpo.
     * @param array<string, scalar|null>     $query Parámetros de la dirección; los null no se envían.
     * @param bool|null                      $retry ¿Es seguro repetirla? Por defecto, solo GET, PUT y DELETE.
     *
     * @return array<mixed>|string
     *
     * @throws SeatsyooException
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?bool $retry = null)
    {
        return self::decode($this->send($method, $path, $body, $query, $retry));
    }

    /**
     * Como request(), para las rutas que responden JSON: siempre devuelve un array.
     *
     * @param array<string, mixed>|null  $body
     * @param array<string, scalar|null> $query
     *
     * @return array<mixed>
     *
     * @throws SeatsyooException Con código "invalid-response" si la respuesta no es JSON.
     */
    public function json(string $method, string $path, ?array $body = null, array $query = [], ?bool $retry = null): array
    {
        $response = $this->send($method, $path, $body, $query, $retry);
        if ($response->body !== '' && !self::isJson($response)) {
            throw new SeatsyooException(
                sprintf('La respuesta de Seatsyoo no es JSON (%s): revisa la dirección del servidor', $response->header('content-type') ?? 'sin tipo'),
                $response->status,
                'invalid-response',
            );
        }
        $decoded = self::decode($response);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * La petición con sus reintentos: devuelve la respuesta 2xx o lanza el error.
     *
     * @param array<string, mixed>|null  $body
     * @param array<string, scalar|null> $query
     */
    private function send(string $method, string $path, ?array $body, array $query, ?bool $retry): Response
    {
        $method = strtoupper($method);
        $url = $this->url($path, $query);
        $payload = $body === null ? null : self::encode($body);
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
            'User-Agent' => 'Seatsyoo-PHP/' . Client::VERSION . ' PHP/' . PHP_VERSION,
        ];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $retry = $retry ?? in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE'], true);

        for ($attempt = 0; ; $attempt++) {
            $canRetry = $retry && $attempt < $this->maxRetries;
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
            } catch (TransportException $e) {
                if ($canRetry) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }
                throw $e->isTimeout()
                    ? new SeatsyooException(sprintf('Sin respuesta de Seatsyoo en %s s', self::seconds($this->timeout)), 0, 'timeout', null, $e)
                    : new SeatsyooException('No se pudo conectar con Seatsyoo: ' . $e->getMessage(), 0, 'network-error', null, $e);
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }
            $error = self::error($response);
            if ($canRetry && in_array($response->status, self::RETRY_STATUSES, true) && $error->getErrorCode() !== 'quota-exceeded') {
                ($this->sleep)($this->delay($response, $attempt));
                continue;
            }
            throw $error;
        }
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function url(string $path, array $query): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        // Se admite la ruta con o sin el prefijo /api/v1.
        if (strncmp($path, '/api/v1/', 8) === 0 || $path === '/api/v1') {
            $path = (string) substr($path, 7);
        }
        $url = $this->baseUrl . $path;
        $params = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $params[$name] = (string) $value;
        }
        if ($params !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * El cuerpo en JSON. Un cuerpo vacío va como {} (la API espera siempre un objeto).
     *
     * @param array<string, mixed> $body
     */
    private static function encode(array $body): string
    {
        if ($body === []) {
            return '{}';
        }
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('No se puede convertir la petición a JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<mixed>|string
     */
    private static function decode(Response $response)
    {
        if ($response->body === '') {
            return [];
        }
        if (!self::isJson($response)) {
            return $response->body;
        }
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new SeatsyooException('La respuesta de Seatsyoo no es un JSON válido', $response->status, 'invalid-response', null, $e);
        }

        return is_array($decoded) ? $decoded : [$decoded];
    }

    private static function error(Response $response): SeatsyooException
    {
        $decoded = null;
        if (self::isJson($response) && $response->body !== '') {
            $decoded = json_decode($response->body, true, 512, JSON_BIGINT_AS_STRING);
        }
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : null;
        if ($error !== null && isset($error['code']) && is_string($error['code'])) {
            $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== ''
                ? $error['message']
                : sprintf('Error %d de Seatsyoo', $response->status);

            return new SeatsyooException($message, $response->status, $error['code'], $error['details'] ?? null);
        }

        // Sin el cuerpo de error de la API (un proxy, una redirección…).
        return new SeatsyooException(sprintf('Error %d de Seatsyoo', $response->status), $response->status, 'http-error');
    }

    private static function isJson(Response $response): bool
    {
        $type = strtolower($response->header('content-type') ?? '');

        return strpos($type, 'json') !== false;
    }

    /** Espera exponencial con azar: entre la mitad y el total de 0,5 s, 1 s, 2 s… (como mucho 8 s). */
    private function backoff(int $attempt): float
    {
        $delay = min(self::MAX_DELAY, self::BASE_DELAY * (2 ** $attempt));

        return $delay * (0.5 + 0.5 * ($this->random)());
    }

    private function delay(Response $response, int $attempt): float
    {
        if ($response->status === 429) {
            $retryAfter = self::retryAfter($response->header('retry-after'));
            if ($retryAfter !== null) {
                return min(self::MAX_RETRY_AFTER, $retryAfter);
            }
        }

        return $this->backoff($attempt);
    }

    /** Retry-After en segundos o como fecha HTTP. */
    private static function retryAfter(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $time = strtotime($value);

        return $time === false ? null : (float) max(0, $time - time());
    }

    private static function seconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 2, ',', ''), '0'), ',');
    }
}
