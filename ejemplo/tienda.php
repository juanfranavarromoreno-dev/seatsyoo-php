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
 * Si la reserva falla con el pago ya cobrado (una plaza que se soltó y se llevó otro, un token que caducó), no se
 * vende nada: el pedido queda «por devolver» y no se vuelve a reservar (ver alConfirmarElPago). Un pedido reservado
 * tampoco se vuelve a reservar: un aviso repetido de la pasarela recibe lo que respondió la reserva.
 *
 * Lo usa ejemplo/compra.php. Cópialo a tu proyecto y guarda los pedidos en tu base de datos, no en memoria.
 */

declare(strict_types=1);

use Seatsyoo\Client;
use Seatsyoo\SeatsyooException;

final class Tienda
{
    /**
     * Los errores de la reserva que dicen que no se ha vendido nada y que no se arreglan repitiéndola: alguna plaza
     * ya no está retenida con el token (la soltó y la retuvo o la vendió otro), o el token caducó o ya no existe.
     */
    private const SIN_PLAZAS = ['objects-not-available', 'hold-token-expired', 'invalid-hold-token'];

    /**
     * Los pedidos guardados en la caja. En tu tienda, tu base de datos de pedidos. reservado: lo que respondió la
     * reserva, que ya está hecha; porDevolver: la reserva falló con el pago ya cobrado y sin vender nada (el código
     * del error y sus detalles).
     *
     * @var array<string, array{evento: string, holdToken: string, objetos: list<array{key: string, quantity: int}>, importe: float, reservado?: array{objects: list<string>, seq: int, retried?: true}, porDevolver?: array{motivo: string, detalles: mixed}}>
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
     * sin vender nada, así que con el de otra persona vería su compra). Volver a la caja con el mismo pedido renueva
     * la lista mientras no se haya reservado; uno reservado o por devolver ya no vuelve a la caja (usa otro número).
     *
     * @return float El importe que cobras.
     */
    public function alPasarPorCaja(string $pedido, string $evento, string $holdToken): float
    {
        $anterior = $this->pedidos[$pedido] ?? [];
        if (isset($anterior['reservado']) || isset($anterior['porDevolver'])) {
            throw new \RuntimeException("El pedido $pedido ya se cobró: para otra compra, usa otro número de pedido");
        }
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
     * reintento, un aviso de la pasarela que llega dos veces) no vende dos veces: un pedido ya reservado no vuelve a
     * llamar a book() y devuelve lo que respondió la reserva. Con el mismo pedido, book() no vende dos veces lo que
     * sigue vendido, pero si después devolviste una parte, volvería a vender lo devuelto (si sigue libre y el token
     * vive), así que la tienda no la repite.
     *
     * Si falla, el pago ya está cobrado y esta reserva no ha vendido nada, porque es todo o nada:
     *
     * - 409 objects-not-available (getDetails() dice qué plaza y por qué: held, booked…), 400 hold-token-expired o
     *   400 invalid-hold-token: repetirla no lo arregla. Antes de darlo por perdido mira el pedido en Seatsyoo
     *   (retrieveOrder()): si ya tiene plazas, se vendió en un intento anterior cuya respuesta no llegó (y después
     *   cambió, con una devolución, por ejemplo); queda reservado con lo que diga el pedido, y cuadra() dirá si es lo
     *   cobrado. Si no tiene nada, el pedido queda «por devolver» (en guardado()) y se relanza el error: devuelve el
     *   cobro con tu pasarela, o ofrece al comprador otras plazas en un pedido nuevo, y no emitas entradas. Un pedido
     *   por devolver no se vuelve a reservar: si las plazas quedaran libres después, un aviso repetido de la pasarela
     *   las vendería con el cobro ya devuelto.
     * - Sin respuesta tras los reintentos del SDK (getStatus() 0: network-error, timeout) o un 5xx: no se sabe si se
     *   vendió. Repítelo más tarde con el mismo pedido, que no vende dos veces.
     * - Otro 4xx (el evento ya no existe, la clave de producción bloqueada…): no se ha vendido nada, y repetirlo no lo
     *   arregla mientras no cambie la causa. Revísalo, o devuelve el cobro.
     *
     * Para que el token no caduque mientras se cobra, amplíalo en la caja (holdTokens->extend(), como compra.php).
     *
     * @return array{objects: list<string>, seq: int, retried?: true} La respuesta de book() (la guardada, si ya se
     *                                                                 había reservado).
     */
    public function alConfirmarElPago(string $pedido): array
    {
        $guardado = $this->guardado($pedido);
        if (isset($guardado['porDevolver'])) {
            throw new \RuntimeException("El pedido $pedido no se pudo reservar y está por devolver: no se vuelve a reservar");
        }
        if (isset($guardado['reservado'])) {
            return $guardado['reservado'];
        }

        try {
            $reserva = $this->seatsyoo->events->book($guardado['evento'], $guardado['objetos'], holdToken: $guardado['holdToken'], orderId: $pedido);
        } catch (SeatsyooException $e) {
            if (!in_array($e->getErrorCode(), self::SIN_PLAZAS, true)) {
                throw $e;
            }
            $vendido = $this->seatsyoo->events->retrieveOrder($guardado['evento'], $pedido)['objects'];
            if ($vendido === []) {
                $this->pedidos[$pedido]['porDevolver'] = ['motivo' => $e->getErrorCode(), 'detalles' => $e->getDetails()];
                throw $e;
            }
            // Vendido antes: como la respuesta de una reserva repetida (seq 0, el número del cambio no se sabe).
            $reserva = ['objects' => array_column($vendido, 'key'), 'seq' => 0, 'retried' => true];
        }
        $this->pedidos[$pedido]['reservado'] = $reserva;

        return $reserva;
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
     * Lo guardado en la caja para un pedido (con reservado si la reserva está hecha, y con porDevolver si falló con el
     * pago ya cobrado y sin vender nada).
     *
     * @return array{evento: string, holdToken: string, objetos: list<array{key: string, quantity: int}>, importe: float, reservado?: array{objects: list<string>, seq: int, retried?: true}, porDevolver?: array{motivo: string, detalles: mixed}}
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
