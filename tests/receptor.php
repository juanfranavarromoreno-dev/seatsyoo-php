<?php

/**
 * Servidor local de las pruebas de integración (php -S 127.0.0.1:<puerto> tests/receptor.php):
 *
 * - POST /aviso: recibe un aviso de webhook de verdad, lo verifica con Webhook::verify y apunta en el archivo
 *   SEATSYOO_AVISOS lo que llegó (cuerpo, cabeceras y si la firma era buena). Contesta 200 o 400.
 * - /lento…: tarda SEATSYOO_LENTO_MS milisegundos en contestar (para probar el plazo de los transportes).
 * - /goteo…: contesta enseguida, pero suelta el cuerpo de byte en byte, uno cada SEATSYOO_GOTEO_MS
 *   milisegundos, SEATSYOO_GOTEO_VECES veces (para probar que el plazo es el de la petición entera).
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Seatsyoo\SignatureVerificationException;
use Seatsyoo\Webhook;

$ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (strncmp($ruta, '/lento', 6) === 0) {
    usleep((int) (getenv('SEATSYOO_LENTO_MS') ?: '3000') * 1000);
    header('Content-Type: application/json');
    echo '{"tarde":true}';

    return;
}

if (strncmp($ruta, '/goteo', 6) === 0) {
    header('Content-Type: application/json');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    echo '{"goteo":"';
    flush();
    $veces = (int) (getenv('SEATSYOO_GOTEO_VECES') ?: '10');
    for ($i = 0; $i < $veces; $i++) {
        usleep((int) (getenv('SEATSYOO_GOTEO_MS') ?: '300') * 1000);
        echo 'x';
        flush();
    }
    echo '"}';

    return;
}

if ($ruta === '/aviso' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cuerpo = (string) file_get_contents('php://input');
    $firma = $_SERVER['HTTP_SEATSYOO_SIGNATURE'] ?? null;
    $registro = [
        'cuerpo' => $cuerpo,
        'firma' => $firma,
        'tipo' => $_SERVER['HTTP_SEATSYOO_EVENT'] ?? null,
        'envio' => $_SERVER['HTTP_SEATSYOO_DELIVERY'] ?? null,
        'agente' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'contenido' => $_SERVER['CONTENT_TYPE'] ?? null,
    ];
    try {
        $aviso = Webhook::verify($cuerpo, $firma, (string) getenv('SEATSYOO_WEBHOOK_SECRET'));
        $registro['valido'] = true;
        $registro['aviso'] = $aviso;
        http_response_code(200);
    } catch (SignatureVerificationException $e) {
        $registro['valido'] = false;
        $registro['error'] = $e->getMessage();
        http_response_code(400);
    }
    file_put_contents(
        (string) getenv('SEATSYOO_AVISOS'),
        json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX,
    );

    return;
}

http_response_code(404);
