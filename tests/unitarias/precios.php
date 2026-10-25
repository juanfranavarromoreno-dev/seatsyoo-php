<?php

/**
 * Seatsyoo no guarda precios: «pricing» al crear un evento o una temporada es un error antes de llamar (el servidor
 * lo descartaría sin avisar), y el cálculo del importe del README funciona sin avisos de PHP.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;

grupo('Precios');

prueba('pricing al crear un evento o una temporada, o al cambiarla, es un error antes de llamar (el servidor lo descartaría sin avisar)', function (): void {
    $e = new Entorno([]);
    $casos = [
        'events->create' => fn () => $e->events->create(['chartKey' => 'teatro', 'key' => 'e1', 'pricing' => ['currency' => 'EUR', 'categories' => ['platea' => 30]]]),
        'events->create con pricing null' => fn () => $e->events->create(['chartKey' => 'teatro', 'pricing' => null]),
        'seasons->create' => fn () => $e->seasons->create(['chartKey' => 'teatro', 'key' => 'otono', 'pricing' => []]),
        'seasons->update' => fn () => $e->seasons->update('otono', ['name' => 'Otoño', 'pricing' => ['currency' => 'EUR']]),
        'seasons->addEvents' => fn () => $e->seasons->addEvents('otono', events: [['key' => 'e2'], ['key' => 'e3', 'pricing' => []]]),
    ];
    foreach ($casos as $que => $llamada) {
        $error = lanza(\InvalidArgumentException::class, $llamada);
        contiene('Seatsyoo no guarda precios: quita «pricing»', $error->getMessage(), $que);
    }
    igual(0, $e->peticiones(), 'no llega al servidor');
});

prueba('sin pricing, crear y cambiar eventos y temporadas sale igual que antes', function (): void {
    $e = new Entorno([json(201), json(201), json(200), json(200)]);
    $e->events->create(['chartKey' => 'teatro', 'key' => 'e1', 'name' => 'Función']);
    igual('{"chartKey":"teatro","key":"e1","name":"Función"}', $e->transporte->ultima()['body']);
    $e->seasons->create(['chartKey' => 'teatro', 'key' => 'otono', 'eventKeys' => ['e1']]);
    igual('{"chartKey":"teatro","key":"otono","eventKeys":["e1"]}', $e->transporte->ultima()['body']);
    $e->seasons->update('otono', ['pick' => null]);
    igual('{"pick":null}', $e->transporte->ultima()['body']);
    $e->seasons->addEvents('otono', eventKeys: ['e1'], events: [['key' => 'e2', 'date' => null]]);
    igual('{"eventKeys":["e1"],"events":[{"key":"e2","date":null}]}', $e->transporte->ultima()['body']);
});

/** El primer bloque de PHP de una sección del README («## Título»). */
function fragmentoDelReadme(string $seccion): string
{
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    $inicio = strpos($readme, "\n## $seccion\n");
    if ($inicio === false) {
        throw new Fallo("el README no tiene la sección «$seccion»");
    }
    if (preg_match('/```php\n(.*?)```/s', $readme, $bloque, 0, $inicio) !== 1) {
        throw new Fallo("la sección «$seccion» del README no tiene PHP");
    }

    return $bloque[1];
}

prueba('el fragmento de Precios del README: por plaza, y sin avisos de PHP con un objeto sin categoría', function (): void {
    // Lo que da retrieveObject() de una mesa entera (6 sillas), de la pista y de una butaca sin categoría (null).
    $seatsyoo = new Client(secretKey: 'sk_prueba', server: 'https://seatsyoo.example', maxRetries: 0, transport: new TransporteFalso([
        json(200, ['key' => 'M1', 'kind' => 'table', 'category' => 'mesas', 'capacity' => 6]),
        json(200, ['key' => 'Pista', 'kind' => 'area', 'category' => 'pista', 'capacity' => 500]),
        json(200, ['key' => 'Pasillo-1', 'kind' => 'seat', 'category' => null, 'capacity' => 1]),
    ]));
    $evento = 'hamlet';
    $objetos = [['key' => 'M1', 'quantity' => 1], ['key' => 'Pista', 'quantity' => 3], ['key' => 'Pasillo-1', 'quantity' => 1]];
    $importe = null;
    // Cualquier aviso de PHP (en 8.5, «Using null as an array offset is deprecated») hace fallar la prueba.
    eval(fragmentoDelReadme('Precios'));
    igual(35 * 6 + 18 * 3, $importe, 'la mesa cuenta sus seis sillas y la butaca sin categoría, 0');
});
