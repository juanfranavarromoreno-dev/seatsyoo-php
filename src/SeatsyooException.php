<?php

declare(strict_types=1);

namespace Seatsyoo;

/**
 * Error de una petición a la API de Seatsyoo.
 *
 * Si el servidor contesta con un error, lleva su código HTTP y el cuerpo
 * `{ "error": { "code", "message", "details" } }`: programa contra getErrorCode(), que es estable; el mensaje
 * es para personas y puede cambiar. Si no hubo respuesta, getStatus() es 0 y getErrorCode() es
 * "network-error" (no se pudo conectar o se cortó) o "timeout" (no contestó a tiempo).
 *
 * getCode() devuelve también el código HTTP (0 sin respuesta).
 */
class SeatsyooException extends \RuntimeException
{
    private int $status;
    private string $errorCode;
    /** @var mixed */
    private $details;

    /**
     * @param mixed $details
     */
    public function __construct(string $message, int $status, string $errorCode, $details = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    /** Código HTTP de la respuesta, o 0 si no la hubo (error de red o tiempo agotado). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Código del error, como "objects-not-available", "not-found", "network-error" o "timeout". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Detalles del error, si el servidor los da: en objects-not-available, una lista de
     * `['key' => …, 'reason' => …]`; en bad-request, la lista de problemas. null si no hay.
     *
     * @return mixed
     */
    public function getDetails()
    {
        return $this->details;
    }
}
