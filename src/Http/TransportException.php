<?php

declare(strict_types=1);

namespace Seatsyoo\Http;

/**
 * Un transporte no ha obtenido respuesta. El cliente la convierte en una SeatsyooException con estado 0 y
 * código "timeout" o "network-error".
 */
class TransportException extends \RuntimeException
{
    private bool $timeout;

    public function __construct(string $message, bool $timeout = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->timeout = $timeout;
    }

    /** ¿Se agotó el plazo (en lugar de no poder conectar o cortarse)? */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
