<?php

/** Ayudas de las pruebas: un transporte falso, un entorno con el cliente y servidores PHP locales. */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Http\Requester;
use Seatsyoo\Http\Response;
use Seatsyoo\Http\Transport;
use Seatsyoo\Http\TransportException;
use Seatsyoo\Resources\Charts;
use Seatsyoo\Resources\Events;
use Seatsyoo\Resources\HoldTokens;
use Seatsyoo\Resources\Seasons;
use Seatsyoo\Resources\Webhooks;
use Seatsyoo\Resources\Workspace;

/**
 * Transporte que no sale a la red: devuelve, por orden, las respuestas (o lanza las excepciones) que se le
 * dan y apunta cada petición.
 */
final class TransporteFalso implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $peticiones = [];
    /** @var list<Response|\Throwable> */
    private array $cola;

    /** @param list<Response|\Throwable> $respuestas */
    public function __construct(array $respuestas)
    {
        $this->cola = $respuestas;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->peticiones[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $siguiente = array_shift($this->cola);
        if ($siguiente === null) {
            throw new Fallo('una petición de más: ' . $method . ' ' . $url);
        }
        if ($siguiente instanceof \Throwable) {
            throw $siguiente;
        }

        return $siguiente;
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float} */
    public function ultima(): array
    {
        $ultima = end($this->peticiones);
        if ($ultima === false) {
            throw new Fallo('no se hizo ninguna petición');
        }

        return $ultima;
    }

    /** La ruta y la consulta de la última petición, sin la dirección del servidor. */
    public function ruta(): string
    {
        return (string) substr($this->ultima()['url'], strlen(Entorno::SERVIDOR));
    }
}

/**
 * @param array<mixed>          $cuerpo
 * @param array<string, string> $cabeceras
 */
function json(int $status, array $cuerpo = [], array $cabeceras = []): Response
{
    return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'] + $cabeceras, (string) json_encode($cuerpo));
}

/** @param mixed $details */
function errorApi(int $status, string $code, string $message = 'Mensaje', $details = null, array $cabeceras = []): Response
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error['details'] = $details;
    }

    return json($status, ['error' => $error], $cabeceras);
}

function sinRed(): TransportException
{
    return new TransportException('Could not connect to server');
}

function agotado(): TransportException
{
    return new TransportException('Operation timed out', true);
}

/** El cliente por dentro, con el transporte falso, sin esperar de verdad y con el azar fijo. */
final class Entorno
{
    public const SERVIDOR = 'https://seatsyoo.example/api/v1';

    public TransporteFalso $transporte;
    /** @var list<float> Las esperas entre intentos, en segundos. */
    public array $esperas = [];
    public Requester $http;
    public Events $events;
    public Seasons $seasons;
    public HoldTokens $holdTokens;
    public Charts $charts;
    public Webhooks $webhooks;
    public Workspace $workspace;

    /** @param list<Response|\Throwable> $respuestas */
    public function __construct(array $respuestas, int $maxRetries = 2, float $azar = 1.0)
    {
        $this->transporte = new TransporteFalso($respuestas);
        $this->http = new Requester(
            'sk_prueba',
            'https://seatsyoo.example',
            30.0,
            $maxRetries,
            $this->transporte,
            function (float $segundos): void {
                $this->esperas[] = $segundos;
            },
            static function () use ($azar): float {
                return $azar;
            },
        );
        $this->events = new Events($this->http);
        $this->seasons = new Seasons($this->http);
        $this->holdTokens = new HoldTokens($this->http);
        $this->charts = new Charts($this->http);
        $this->webhooks = new Webhooks($this->http);
        $this->workspace = new Workspace($this->http);
    }

    public function peticiones(): int
    {
        return count($this->transporte->peticiones);
    }
}

/** Un puerto TCP libre de 127.0.0.1 (lo da el sistema). */
function puertoLibre(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new \RuntimeException("No hay puerto libre: $errstr");
    }
    $nombre = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($nombre, (int) strrpos($nombre, ':') + 1);
}

/** El servidor web de PHP (php -S) con un guion, en un puerto libre de 127.0.0.1. */
final class ServidorLocal
{
    public string $url;
    public int $puerto;
    /** @var resource */
    private $proceso;

    /**
     * @param array<string, string> $entorno Variables de entorno extra para el guion.
     */
    public function __construct(string $guion, array $entorno = [], ?int $puerto = null)
    {
        $this->puerto = $puerto ?? puertoLibre();
        $this->url = 'http://127.0.0.1:' . $this->puerto;
        $nulo = ['file', '/dev/null', 'w'];
        $variables = array_merge(getenv(), $entorno);
        $proceso = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $this->puerto, $guion],
            [0 => ['file', '/dev/null', 'r'], 1 => $nulo, 2 => $nulo],
            $tuberias,
            dirname($guion),
            $variables,
        );
        if (!is_resource($proceso)) {
            throw new \RuntimeException('No se pudo arrancar php -S');
        }
        $this->proceso = $proceso;
        alAcabar(function (): void {
            $this->parar();
        });
        // Espera a que acepte conexiones.
        $limite = microtime(true) + 10;
        while (microtime(true) < $limite) {
            $conexion = @fsockopen('127.0.0.1', $this->puerto, $errno, $errstr, 0.2);
            if (is_resource($conexion)) {
                fclose($conexion);

                return;
            }
            usleep(50000);
        }
        $this->parar();
        throw new \RuntimeException('php -S no arrancó en el puerto ' . $this->puerto);
    }

    public function parar(): void
    {
        if (is_resource($this->proceso)) {
            proc_terminate($this->proceso);
            proc_close($this->proceso);
        }
    }
}

/** Espera hasta que $fn devuelva algo distinto de null (o se acabe el plazo) y lo devuelve. */
function esperarA(callable $fn, float $segundos, string $que)
{
    $limite = microtime(true) + $segundos;
    do {
        $valor = $fn();
        if ($valor !== null) {
            return $valor;
        }
        usleep(100000);
    } while (microtime(true) < $limite);
    throw new Fallo("no llegó a tiempo: $que");
}
