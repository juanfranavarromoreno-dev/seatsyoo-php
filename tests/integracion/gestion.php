<?php

/**
 * Lo que usa Tikyoo para gestionar sus eventos en Seatsyoo, contra un servidor de verdad (tests/ejecutar.mjs): estados
 * con punto de partida, canales, categorías propias del evento, informes, planos, temporadas parciales y la clave de
 * cuenta. Con un servidor que aún no tiene las claves de cuenta (SEATSYOO_TEST_ACCOUNT_KEY vacía), se saltan.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;

grupo('Integración: gestión de eventos y cuentas');

final class Gestion
{
    public static Client $seatsyoo;
    public static string $server;
    public static string $cuenta;
}

Gestion::$server = (string) getenv('SEATSYOO_TEST_SERVER');
Gestion::$seatsyoo = new Client(secretKey: (string) getenv('SEATSYOO_TEST_SECRET_KEY'), server: Gestion::$server);
Gestion::$cuenta = (string) getenv('SEATSYOO_TEST_ACCOUNT_KEY');

function conGestion(): Client
{
    if (Gestion::$cuenta === '') {
        saltar('el servidor de BUTACAS_DIR aún no tiene la integración con Tikyoo (claves de cuenta)');
    }

    return Gestion::$seatsyoo;
}

prueba('un evento propio para estas pruebas, con un plano creado y publicado por la API', function (): void {
    $s = conGestion();
    // El documento publicado del plano de ejemplo (GET /charts/:clave/versions/:n da el documento tal cual).
    $documento = $s->request('GET', '/charts/teatro/versions/1');
    $plano = $s->charts->create('Copia del teatro', 'gestion-teatro', $documento);
    igual('gestion-teatro', $plano['key']);
    igual(1, $s->charts->publish('gestion-teatro')['version']);
    $copia = $s->charts->duplicate('gestion-teatro');
    cierto($copia['key'] !== 'gestion-teatro', 'la copia lleva otra clave');
    $s->events->create(['chartKey' => 'gestion-teatro', 'key' => 'gestion', 'name' => 'Gestión']);
});

prueba('changeStatus con expected: de vendida a sentado y de vuelta, sin perder el pedido; si no cuadra, 409', function (): void {
    $s = conGestion();
    $s->events->book('gestion', ['Platea-A-1'], orderId: 'P-1');
    igual(['Platea-A-1'], $s->events->changeStatus('gestion', ['Platea-A-1'], 'sentado', expected: ['booked'])['objects']);
    igual('sentado', $s->events->retrieveOrder('gestion', 'P-1')['objects'][0]['status']);
    $error = lanza(SeatsyooException::class, fn () => $s->events->changeStatus('gestion', ['Platea-A-1'], 'invitacion', expected: ['free']));
    igual(409, $error->getStatus());
    igual('unexpected-status', $error->getDetails()[0]['reason'] ?? null);
    $s->events->changeStatus('gestion', ['Platea-A-1'], 'booked', expected: ['sentado']);
    igual('booked', $s->events->retrieveOrder('gestion', 'P-1')['objects'][0]['status']);
});

prueba('canales: crear, asignar, sacar, informe por canal y borrar', function (): void {
    $s = conGestion();
    $s->events->createChannel('gestion', 'Taquilla', '#f97316', 'taquilla');
    $evento = $s->events->assignChannel('gestion', 'taquilla', ['Platea-B-1', 'Platea-B-2']);
    igual(['Platea-B-1', 'Platea-B-2'], $evento['settings']['channels'][0]['objects']);
    $grupos = $s->events->reportBy('gestion', 'channel')['groups'];
    cierto(count(array_filter($grupos, fn ($g) => $g['group'] === 'taquilla')) === 1, 'el informe tiene el canal');
    $evento = $s->events->assignChannel('gestion', null, ['Platea-B-2']);
    igual(['Platea-B-1'], $evento['settings']['channels'][0]['objects']);
    $s->events->updateChannel('gestion', 'taquilla', ['name' => 'Taquilla central']);
    igual([], $s->events->deleteChannel('gestion', 'taquilla')['settings']['channels']);
});

prueba('categorías propias del evento: con update, en el resumen', function (): void {
    $s = conGestion();
    $s->events->update('gestion', [
        'categories' => [['key' => 'vip', 'label' => 'VIP', 'color' => '#aa00ff']],
        'categoryOverridesChanges' => ['Platea-C-1' => 'vip'],
    ]);
    $s->events->book('gestion', ['Platea-C-1'], orderId: 'P-2');
    $vip = array_values(array_filter($s->events->summary('gestion')['byCategory'], fn ($c) => $c['key'] === 'vip'));
    igual('VIP', $vip[0]['label'] ?? null);
    igual(1, $vip[0]['booked'] ?? null);
    cierto(isset($s->events->reportOrders('gestion')['orders']), 'el informe de pedidos responde');
});

prueba('temporadas parciales y changeStatus de temporada con expected', function (): void {
    $s = conGestion();
    $s->events->create(['chartKey' => 'gestion-teatro', 'key' => 'gestion-2', 'name' => 'Gestión 2']);
    $s->seasons->create(['key' => 'gestion-abono', 'chartKey' => 'gestion-teatro', 'eventKeys' => ['gestion', 'gestion-2']]);
    $parcial = $s->seasons->createPartial('gestion-abono', ['gestion-2'], 'gestion-parcial', 'Solo el segundo');
    igual('gestion-parcial', $parcial['key']);
    $s->seasons->book('gestion-abono', ['Platea-D-1'], orderId: 'A-1');
    $s->seasons->changeStatus('gestion-abono', ['Platea-D-1'], 'invitacion', expected: ['booked']);
    igual('invitacion', $s->events->retrieveObject('gestion-2', 'Platea-D-1')['status']);
});

prueba('clave de cuenta: espacios de clientes con sus claves, renombrar y uso; no entra en ningún espacio', function (): void {
    conGestion();
    $cuenta = new Client(secretKey: Gestion::$cuenta, server: Gestion::$server);
    $nuevo = $cuenta->account->createWorkspace('Cofradía de pruebas');
    contiene('sk_', $nuevo['secretKey']);
    contiene('sk_test_', $nuevo['testSecretKey']);
    // El espacio principal no tiene datos fiscales: su producción aún no responde; las pruebas, sí.
    cierto($nuevo['productionLocked'] === true, 'nace bloqueado sin los datos fiscales de la cuenta');
    $pruebas = new Client(secretKey: $nuevo['testSecretKey'], server: Gestion::$server);
    cierto(isset($pruebas->charts->list()['charts']), 'con la clave de pruebas se trabaja');
    igual(403, lanza(SeatsyooException::class, fn () => (new Client(secretKey: $nuevo['secretKey'], server: Gestion::$server))->charts->list())->getStatus());

    igual('Cofradía renombrada', $cuenta->account->renameWorkspace($nuevo['publicKey'], 'Cofradía renombrada')['name']);
    igual(['Cofradía renombrada'], array_column($cuenta->account->workspaces()['workspaces'], 'name'));
    igual(0, $cuenta->account->usage()['total']);
    igual(401, lanza(SeatsyooException::class, fn () => $cuenta->charts->list())->getStatus());
});
