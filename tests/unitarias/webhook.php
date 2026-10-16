<?php

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\SeatsyooException;
use Seatsyoo\SignatureVerificationException;
use Seatsyoo\Webhook;

grupo('Webhook::verify');

// Un aviso firmado por el servidor de Seatsyoo (la firma se calculó aparte, con el HMAC de Node).
const CUERPO = '{"id":"evt_8ad83f30-3215-45a8-8289-7a0c81a67766","type":"objects.booked","createdAt":"2026-10-16T14:40:58.868Z","workspace":"pk_prueba","data":{"event":{"key":"hamlet","name":"Hamlet"},"objects":[{"key":"Platea-D-5","quantity":1,"price":30,"amount":30}],"status":"booked","orderId":"pedido-3001","origin":"api","seq":12,"total":30,"currency":"EUR","nota":"Función ñ €"}}';
const SECRETO = 'whsec_prueba_de_firma';
const HORA = 1792161658;
const FIRMA = '6fb02caa981a459700d6b66493e546b956448779c804e14772f51f691951cad1';

prueba('acepta un aviso bien firmado y lo devuelve decodificado', function (): void {
    $aviso = Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . FIRMA, SECRETO, 300, HORA + 5);
    igual('objects.booked', $aviso['type']);
    igual('pedido-3001', $aviso['data']['orderId']);
    igual('Función ñ €', $aviso['data']['nota']);
    igual(30, $aviso['data']['objects'][0]['amount']);
});

prueba('firma como el servidor (mismo HMAC que Node)', function (): void {
    igual('t=' . HORA . ',v1=' . FIRMA, Webhook::signatureHeader(CUERPO, SECRETO, HORA));
});

prueba('signatureHeader sin hora usa la de ahora y se verifica sin indicar now', function (): void {
    $aviso = Webhook::verify(CUERPO, Webhook::signatureHeader(CUERPO, SECRETO), SECRETO);
    igual('evt_8ad83f30-3215-45a8-8289-7a0c81a67766', $aviso['id']);
});

prueba('acepta varias firmas v1 si una coincide (en cualquier orden)', function (): void {
    $mala = str_repeat('0', 64);
    Webhook::verify(CUERPO, 't=' . HORA . ",v1=$mala,v1=" . FIRMA, SECRETO, 300, HORA);
    Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . FIRMA . ",v1=$mala", SECRETO, 300, HORA);
    Webhook::verify(CUERPO, 'v1=' . FIRMA . ',t=' . HORA, SECRETO, 300, HORA);
});

prueba('admite espacios alrededor de las partes e ignora otros esquemas', function (): void {
    Webhook::verify(CUERPO, ' t=' . HORA . ' , v0=abc , v1=' . FIRMA . ' ', SECRETO, 300, HORA);
});

prueba('rechaza un secreto equivocado', function (): void {
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . FIRMA, 'whsec_otro', 300, HORA));
    contiene('no coincide', $e->getMessage());
});

prueba('rechaza un cuerpo cambiado', function (): void {
    $cambiado = str_replace('"amount":30', '"amount":0', CUERPO);
    lanza(SignatureVerificationException::class, fn () => Webhook::verify($cambiado, 't=' . HORA . ',v1=' . FIRMA, SECRETO, 300, HORA));
});

prueba('rechaza el cuerpo vuelto a serializar (hay que usar el cuerpo sin procesar)', function (): void {
    $otraVez = json_encode(json_decode(CUERPO, true));
    cierto($otraVez !== CUERPO, 'json_encode cambia el texto (escapa los caracteres no ASCII)');
    lanza(SignatureVerificationException::class, fn () => Webhook::verify((string) $otraVez, 't=' . HORA . ',v1=' . FIRMA, SECRETO, 300, HORA));
});

prueba('rechaza una firma con la hora cambiada', function (): void {
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . (HORA + 1) . ',v1=' . FIRMA, SECRETO, 300, HORA));
});

prueba('rechaza sin cabecera o con la cabecera vacía', function (): void {
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, null, SECRETO));
    contiene('Falta la cabecera', $e->getMessage());
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, '', SECRETO));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, '   ', SECRETO));
});

prueba('rechaza una cabecera sin hora, sin v1 o mal escrita', function (): void {
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 'v1=' . FIRMA, SECRETO, 300, HORA));
    contiene('hora', $e->getMessage());
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA, SECRETO, 300, HORA));
    contiene('v1', $e->getMessage());
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=', SECRETO, 300, HORA));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=ayer,v1=' . FIRMA, SECRETO, 300, HORA));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=-5,v1=' . FIRMA, SECRETO, 300, HORA));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',t=' . HORA . ',v1=' . FIRMA, SECRETO, 300, HORA));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, FIRMA, SECRETO, 300, HORA));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't:' . HORA . ';v1:' . FIRMA, SECRETO, 300, HORA));
});

prueba('rechaza la firma en mayúsculas o recortada (comparación exacta)', function (): void {
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . strtoupper(FIRMA), SECRETO, 300, HORA));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . substr(FIRMA, 0, 63), SECRETO, 300, HORA));
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . FIRMA . '0', SECRETO, 300, HORA));
});

prueba('tolerancia: 300 s por defecto, en los dos sentidos', function (): void {
    $cabecera = 't=' . HORA . ',v1=' . FIRMA;
    Webhook::verify(CUERPO, $cabecera, SECRETO, 300, HORA + 300);
    Webhook::verify(CUERPO, $cabecera, SECRETO, 300, HORA - 300);
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, $cabecera, SECRETO, 300, HORA + 301));
    contiene('repetición', $e->getMessage());
    // Firmado «en el futuro» (relojes desajustados más de la cuenta).
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, $cabecera, SECRETO, 300, HORA - 301));
    // Sin indicar la tolerancia, también 300.
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, $cabecera, SECRETO, now: HORA + 301));
    Webhook::verify(CUERPO, $cabecera, SECRETO, now: HORA + 299);
});

prueba('tolerancia a medida', function (): void {
    $cabecera = 't=' . HORA . ',v1=' . FIRMA;
    Webhook::verify(CUERPO, $cabecera, SECRETO, tolerance: 10, now: HORA + 10);
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, $cabecera, SECRETO, tolerance: 10, now: HORA + 11));
    Webhook::verify(CUERPO, $cabecera, SECRETO, tolerance: 0, now: HORA);
    lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, $cabecera, SECRETO, tolerance: 0, now: HORA + 1));
    Webhook::verify(CUERPO, $cabecera, SECRETO, tolerance: 86400, now: HORA + 86400);
});

prueba('un aviso viejo con la firma de otro secreto dice que no coincide (no da pistas de la hora)', function (): void {
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . FIRMA, 'whsec_otro', 300, HORA + 9999));
    contiene('no coincide', $e->getMessage());
});

prueba('rechaza un cuerpo firmado que no es un objeto JSON', function (): void {
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify('no es json', Webhook::signatureHeader('no es json', SECRETO, HORA), SECRETO, 300, HORA));
    contiene('JSON', $e->getMessage());
    lanza(SignatureVerificationException::class, fn () => Webhook::verify('42', Webhook::signatureHeader('42', SECRETO, HORA), SECRETO, 300, HORA));
});

prueba('un secreto vacío o una tolerancia negativa son errores de programación', function (): void {
    lanza(\InvalidArgumentException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . FIRMA, '', 300, HORA));
    lanza(\InvalidArgumentException::class, fn () => Webhook::verify(CUERPO, 't=' . HORA . ',v1=' . FIRMA, SECRETO, -1, HORA));
});

prueba('compara las firmas con hash_equals (tiempo constante)', function (): void {
    $metodo = new \ReflectionMethod(Webhook::class, 'verify');
    $lineas = file((string) $metodo->getFileName()) ?: [];
    $codigo = implode('', array_slice($lineas, $metodo->getStartLine() - 1, $metodo->getEndLine() - $metodo->getStartLine() + 1));
    contiene('hash_equals($expected, $signature)', $codigo);
    cierto(preg_match('/(===?|!==?)\s*\$signature|\$signature\s*(===?|!==?)|strcmp\(/', $codigo) === 0, 'no compara las firmas con ==, === ni strcmp');
});

prueba('SignatureVerificationException no es un error de la API', function (): void {
    $e = lanza(SignatureVerificationException::class, fn () => Webhook::verify(CUERPO, null, SECRETO));
    cierto(!$e instanceof SeatsyooException, 'no hereda de SeatsyooException');
    cierto($e instanceof \RuntimeException, 'es una RuntimeException');
});
