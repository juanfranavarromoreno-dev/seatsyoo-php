<?php

declare(strict_types=1);

namespace Seatsyoo;

/**
 * Verificación de los avisos de webhook de Seatsyoo.
 *
 * Cada aviso llega con la cabecera `Seatsyoo-Signature: t=<segundos>,v1=<hex>`, donde v1 es el HMAC-SHA256
 * en hexadecimal, con el secreto del webhook (whsec_…) como clave, del texto "<t>.<cuerpo>". El cuerpo ha de
 * ser el que llega, sin procesar: si lo decodificas y lo vuelves a codificar, la firma no coincidirá.
 */
final class Webhook
{
    /** Margen por defecto, en segundos, entre la hora de la firma y la de tu servidor. */
    public const DEFAULT_TOLERANCE = 300;

    /** Nombre de la cabecera con la firma. En PHP llega en $_SERVER['HTTP_SEATSYOO_SIGNATURE']. */
    public const SIGNATURE_HEADER = 'Seatsyoo-Signature';

    private function __construct()
    {
    }

    /**
     * Comprueba la firma de un aviso y lo devuelve ya decodificado.
     *
     * Acepta varias firmas v1 en la cabecera (vale con que coincida una), las compara en tiempo constante
     * y rechaza los avisos firmados hace más de $tolerance segundos (o con la hora adelantada en más de eso),
     * para que nadie pueda repetir uno antiguo. Cada reintento de Seatsyoo se firma de nuevo con su hora.
     *
     * @param string      $payload   El cuerpo de la petición tal cual llega (file_get_contents('php://input')).
     * @param string|null $header    El valor de la cabecera Seatsyoo-Signature.
     * @param string      $secret    El secreto del webhook (whsec_…).
     * @param int         $tolerance Segundos de diferencia admitidos con la hora de la firma.
     * @param int|null    $now       La hora actual en segundos Unix (para pruebas); por defecto, time().
     *
     * @return array<string, mixed> El aviso: id, type, createdAt, workspace y data.
     *
     * @throws SignatureVerificationException Si la firma falta, está mal escrita, no coincide o es vieja.
     * @throws \InvalidArgumentException      Si el secreto está vacío o la tolerancia es negativa.
     */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Falta el secreto del webhook (whsec_…)');
        }
        if ($tolerance < 0) {
            throw new \InvalidArgumentException('La tolerancia no puede ser negativa');
        }
        if ($header === null || trim($header) === '') {
            throw new SignatureVerificationException('Falta la cabecera Seatsyoo-Signature');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pieces = explode('=', trim($part), 2);
            if (count($pieces) !== 2) {
                continue;
            }
            [$name, $value] = $pieces;
            if ($name === 't') {
                if ($timestamp !== null || preg_match('/^\d{1,12}$/', $value) !== 1) {
                    throw new SignatureVerificationException('La cabecera Seatsyoo-Signature no tiene una hora válida');
                }
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
            // Otros esquemas (versiones futuras de la firma) se ignoran.
        }
        if ($timestamp === null) {
            throw new SignatureVerificationException('La cabecera Seatsyoo-Signature no tiene la hora (t=…)');
        }
        if ($signatures === []) {
            throw new SignatureVerificationException('La cabecera Seatsyoo-Signature no tiene ninguna firma v1');
        }

        $expected = self::computeSignature($payload, $secret, $timestamp);
        $valid = false;
        foreach ($signatures as $signature) {
            // hash_equals compara en tiempo constante: no revela cuántos caracteres coinciden.
            if (hash_equals($expected, $signature)) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw new SignatureVerificationException('La firma del aviso no coincide: revisa el secreto y usa el cuerpo sin procesar');
        }

        $current = $now ?? time();
        if (abs($current - $timestamp) > $tolerance) {
            throw new SignatureVerificationException(sprintf(
                'El aviso se firmó hace %d s, fuera del margen de %d s: puede ser una repetición',
                $current - $timestamp,
                $tolerance,
            ));
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('El aviso no es un JSON válido', 0, $e);
        }
        if (!is_array($event)) {
            throw new SignatureVerificationException('El aviso no es un objeto JSON');
        }

        return $event;
    }

    /**
     * Firma un cuerpo como lo hace Seatsyoo y devuelve el valor de la cabecera Seatsyoo-Signature.
     * Sirve para probar tu receptor sin esperar un aviso de verdad.
     */
    public static function signatureHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return sprintf('t=%d,v1=%s', $t, self::computeSignature($payload, $secret, $t));
    }

    private static function computeSignature(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
