<?php

declare(strict_types=1);

namespace Seatsyoo\Http;

/** Una respuesta HTTP: código, cabeceras (con el nombre en minúsculas) y cuerpo. */
final class Response
{
    public int $status;
    /** @var array<string, string> */
    public array $headers;
    public string $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
