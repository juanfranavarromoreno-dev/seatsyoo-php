<?php

/**
 * La caja de una tienda con el SDK de Seatsyoo para PHP, como en la guía de integración («Reservar desde tu
 * servidor»). La venta se hace en dos momentos:
 *
 * 1. En la caja, tu web te manda solo el token de retención del visor. Tu servidor le pregunta a Seatsyoo qué
 *    retiene ese token, calcula el importe con tus precios y guarda esa lista con el pedido: es lo que cobras.
 * 2. Cuando el pago se confirma, reserva exactamente la lista guardada, con tu número de pedido, sin volver a
 *    preguntar.
 *
 * Por qué así: book() reserva los objetos que le pidas, los retenidos con el token y también los libres, así que
 * si reservaras la lista que manda el navegador, alguien podría añadir butacas que no ha elegido en el plano. Y
 * entre la caja y el pago el comprador puede seguir reteniendo con el mismo token: si volvieras a preguntar al
 * confirmarse el pago, reservarías más de lo que has cobrado. Lo que el token retenga de más caduca solo.
 *
 * Lo usa ejemplo/compra.php. Cópialo a tu proyecto y guarda los pedidos en tu base de datos, no en memoria.
 */

declare(strict_types=1);

use Seatsyoo\Client;

final class Tienda
{
    /**
     * Los pedidos guardados en la caja. En tu tienda, tu base de datos de pedidos.
     *
     * @var array<string, array{evento: string, holdToken: string, objetos: list<array{key: string, quantity: int}>, importe: float}>
     */
    private array $pedidos = [];

    /**
     * @param array<string, int|float> $precios Tus precios por plaza, por clave de categoría de tu plano: los mismos
     *                                          que le pasas al visor con su opción pricing. Seatsyoo no guarda precios.
     */
    public function __construct(private readonly Client $seatsyoo, private readonly array $precios)
    {
    }

    /**
     * 1. En la caja: lo que retiene ahora el token es lo que se cobra, y se guarda con el pedido. El número de
     * pedido lo pone tu tienda, nunca el comprador (repetir una reserva con un pedido que ya existe responde bien
     * sin vender nada, así que con el de otra persona vería su compra).
     *
     * @return float El importe que cobras.
     */
    public function alPasarPorCaja(string $pedido, string $evento, string $holdToken): float
    {
        $objetos = $this->loRetenido($evento, $holdToken);
        if ($objetos === []) {
            throw new \RuntimeException('El token ya no retiene nada: caducó o ya se reservó');
        }
        $importe = $this->importeDe($evento, $objetos);
        $this->pedidos[$pedido] = ['evento' => $evento, 'holdToken' => $holdToken, 'objetos' => $objetos, 'importe' => $importe];

        return $importe;
    }

    /**
     * 2. Con el pago confirmado: la lista guardada en la caja, tal cual, con el número de pedido. Repetirlo (un
     * reintento, un aviso de la pasarela que llega dos veces) no vende dos veces.
     *
     * @return array{objects: list<string>, seq: int, retried?: true} La respuesta de book().
     */
    public function alConfirmarElPago(string $pedido): array
    {
        $guardado = $this->guardado($pedido);

        return $this->seatsyoo->events->book($guardado['evento'], $guardado['objetos'], holdToken: $guardado['holdToken'], orderId: $pedido);
    }

    /**
     * Para cuadrar las cuentas: si lo vendido con el pedido es lo que se guardó (y se cobró) en la caja. Si no
     * coincide, algo cambió entre la caja y la reserva: revísalo antes de emitir las entradas.
     */
    public function cuadra(string $pedido): bool
    {
        $guardado = $this->guardado($pedido);
        $cobrado = array_column($guardado['objetos'], 'quantity', 'key');
        $vendido = array_column($this->seatsyoo->events->retrieveOrder($guardado['evento'], $pedido)['objects'], 'quantity', 'key');
        ksort($cobrado);
        ksort($vendido);

        return $cobrado === $vendido;
    }

    /**
     * Lo guardado en la caja para un pedido.
     *
     * @return array{evento: string, holdToken: string, objetos: list<array{key: string, quantity: int}>, importe: float}
     */
    public function guardado(string $pedido): array
    {
        if (!isset($this->pedidos[$pedido])) {
            throw new \RuntimeException("No hay ningún pedido $pedido guardado en la caja");
        }

        return $this->pedidos[$pedido];
    }

    /**
     * Lo que tiene retenido de verdad ese token: no te fíes de la lista que manda el navegador.
     *
     * Cada objeto va con su cantidad, también las butacas (1): en una zona de pie, reservar sin cantidad reservaría
     * lo que el token tenga allí al confirmarse el pago, que puede ser más de lo cobrado. En los estados, mq son
     * las plazas del token en una zona de pie; no viene cuando solo hay una plaza ocupada, que es la suya.
     *
     * @return list<array{key: string, quantity: int}>
     */
    public function loRetenido(string $evento, string $holdToken): array
    {
        $objetos = [];
        foreach ($this->seatsyoo->events->statuses($evento, holdToken: $holdToken)['objects'] as $clave => $estado) {
            if (($estado['mine'] ?? false) === true) {
                // (string): PHP convierte en número una clave como «12».
                $objetos[] = ['key' => (string) $clave, 'quantity' => (int) ($estado['mq'] ?? 1)];
            }
        }

        return $objetos;
    }

    /**
     * El importe con tus precios: Seatsyoo te dice la categoría y las plazas de cada objeto.
     *
     * @param list<array{key: string, quantity: int}> $objetos
     */
    public function importeDe(string $evento, array $objetos): float
    {
        $total = 0.0;
        foreach ($objetos as $objeto) {
            $info = $this->seatsyoo->events->retrieveObject($evento, $objeto['key']);
            // El precio es por plaza: una mesa que se vende entera o un palco cuentan todas sus plazas.
            $plazas = $info['kind'] === 'area' ? $objeto['quantity'] : $objeto['quantity'] * (int) $info['capacity'];
            $total += ($this->precios[(string) ($info['category'] ?? '')] ?? 0) * $plazas;
        }

        return round($total, 2);
    }
}
