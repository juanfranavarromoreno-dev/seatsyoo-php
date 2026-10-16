<?php

declare(strict_types=1);

namespace Seatsyoo;

use Seatsyoo\Http\CurlTransport;
use Seatsyoo\Http\Requester;
use Seatsyoo\Http\StreamTransport;
use Seatsyoo\Http\Transport;
use Seatsyoo\Resources\Charts;
use Seatsyoo\Resources\Events;
use Seatsyoo\Resources\HoldTokens;
use Seatsyoo\Resources\Seasons;
use Seatsyoo\Resources\Webhooks;
use Seatsyoo\Resources\Workspace;

/**
 * Cliente de la API de Seatsyoo para tu servidor.
 *
 *     $seatsyoo = new \Seatsyoo\Client(secretKey: (string) getenv('SEATSYOO_SECRET_KEY'), server: 'https://tu-servidor-de-seatsyoo');
 *     $seatsyoo->events->book('hamlet', ['Platea-C-1'], holdToken: $token, orderId: 'pedido-2001');
 *
 * Cada método devuelve la respuesta de la API como array asociativo, tal cual la describe la referencia, y
 * lanza una SeatsyooException si la API contesta con un error o no contesta.
 *
 * La clave secreta da acceso de administración a tu espacio: úsala solo en tu servidor, nunca en tu web.
 */
class Client
{
    public const VERSION = '1.0.0';

    /** Eventos y la venta: retener, reservar, devolver, mejor disponible, estados y pedidos. */
    public readonly Events $events;
    /** Tokens de retención. */
    public readonly HoldTokens $holdTokens;
    /** Temporadas (abonos) y sus acciones. */
    public readonly Seasons $seasons;
    /** Planos. */
    public readonly Charts $charts;
    /** Webhooks: altas, cambios, pruebas y envíos. */
    public readonly Webhooks $webhooks;
    /** El espacio de la clave. */
    public readonly Workspace $workspace;

    private Requester $http;

    /**
     * @param string         $secretKey  La clave secreta del espacio (sk_…).
     * @param string         $server     La dirección de Seatsyoo, como https://tu-dominio-de-seatsyoo (sin /api/v1).
     * @param float          $timeout    Plazo de cada intento, en segundos: de la petición entera, con el cuerpo.
     * @param int            $maxRetries Reintentos como mucho en las peticiones que es seguro repetir (0 para ninguno).
     * @param Transport|null $transport  Cómo hacer las peticiones; por defecto, curl si está y, si no, los flujos de PHP.
     */
    public function __construct(
        string $secretKey,
        string $server,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?Transport $transport = null
    ) {
        $this->http = new Requester(
            $secretKey,
            $server,
            $timeout,
            $maxRetries,
            $transport ?? (extension_loaded('curl') ? new CurlTransport() : new StreamTransport()),
        );
        $this->events = new Events($this->http);
        $this->holdTokens = new HoldTokens($this->http);
        $this->seasons = new Seasons($this->http);
        $this->charts = new Charts($this->http);
        $this->webhooks = new Webhooks($this->http);
        $this->workspace = new Workspace($this->http);
    }

    /**
     * Cualquier otra ruta de /api/v1 (informes, canales, planos…), con la misma autenticación, errores y
     * reintentos. Solo se reintentan GET, PUT y DELETE; aquí la respuesta va tal cual, así que un DELETE
     * repetido tras perder la respuesta del primer intento puede dar 404 aunque ese intento sí borrara.
     *
     * Un campo que la API espera como objeto ({"clave": valor}) y está vacío ha de ir como (object) [] o
     * new \stdClass(): un array de PHP vacío sale como lista ([]) y la API responde 400.
     *
     *     $resumen = $seatsyoo->request('GET', '/events/hamlet/report/summary');
     *     $csv = $seatsyoo->request('GET', '/events/hamlet/report/orders.csv', query: ['sep' => ';']);
     *
     * @param string                     $method GET, POST, PUT, PATCH o DELETE.
     * @param string                     $path   La ruta, con o sin /api/v1 delante. Codifica tú las claves (rawurlencode).
     * @param array<string, mixed>|null  $body   El cuerpo, que se envía como JSON.
     * @param array<string, scalar|null> $query  Parámetros de la dirección.
     *
     * @return array<mixed>|string El JSON decodificado o, si la respuesta no es JSON (los .csv), el texto.
     *
     * @throws SeatsyooException
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [])
    {
        return $this->http->request($method, $path, $body, $query);
    }
}
