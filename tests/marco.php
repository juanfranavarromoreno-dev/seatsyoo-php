<?php

/**
 * Un ejecutor de pruebas mínimo, sin dependencias: las pruebas se registran con prueba() y las comprobaciones
 * lanzan Fallo. Lo lanza tests/ejecutar.php.
 */

declare(strict_types=1);

namespace Seatsyoo\Tests;

/** Una comprobación que no se cumple. */
final class Fallo extends \Exception
{
}

/** Una prueba que no se puede hacer aquí (falta algo del entorno). */
final class Salto extends \Exception
{
}

final class Marco
{
    /** @var list<array{grupo: string, nombre: string, fn: callable}> */
    public static array $pruebas = [];
    public static string $grupo = '';
    /** @var list<callable> Lo que se hace al acabar (parar servidores…). */
    public static array $alAcabar = [];
}

function grupo(string $nombre): void
{
    Marco::$grupo = $nombre;
}

function prueba(string $nombre, callable $fn): void
{
    Marco::$pruebas[] = ['grupo' => Marco::$grupo, 'nombre' => $nombre, 'fn' => $fn];
}

function alAcabar(callable $fn): void
{
    Marco::$alAcabar[] = $fn;
}

function texto($valor): string
{
    return var_export($valor, true);
}

/**
 * @param mixed $esperado
 * @param mixed $real
 */
function igual($esperado, $real, string $mensaje = ''): void
{
    if ($esperado !== $real) {
        throw new Fallo(($mensaje !== '' ? $mensaje . ': ' : '') . 'se esperaba ' . texto($esperado) . ' y es ' . texto($real));
    }
}

/** @param mixed $condicion */
function cierto($condicion, string $mensaje): void
{
    if ($condicion !== true) {
        throw new Fallo($mensaje);
    }
}

function contiene(string $aguja, string $pajar, string $mensaje = ''): void
{
    if (strpos($pajar, $aguja) === false) {
        throw new Fallo(($mensaje !== '' ? $mensaje . ': ' : '') . texto($aguja) . ' no está en ' . texto($pajar));
    }
}

function entre(float $min, float $max, float $valor, string $mensaje = ''): void
{
    if ($valor < $min || $valor > $max) {
        throw new Fallo(($mensaje !== '' ? $mensaje . ': ' : '') . "$valor no está entre $min y $max");
    }
}

/**
 * Comprueba que $fn lanza una excepción de la clase indicada y la devuelve.
 *
 * @template T of \Throwable
 *
 * @param class-string<T> $clase
 *
 * @return T
 */
function lanza(string $clase, callable $fn): \Throwable
{
    try {
        $fn();
    } catch (Fallo $e) {
        throw $e;
    } catch (\Throwable $e) {
        if ($e instanceof $clase) {
            return $e;
        }
        throw new Fallo('se esperaba ' . $clase . ' y se lanzó ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new Fallo('se esperaba ' . $clase . ' y no se lanzó nada');
}

function saltar(string $motivo): void
{
    throw new Salto($motivo);
}

/**
 * Ejecuta las pruebas registradas y devuelve cuántas fallaron.
 */
function ejecutar(): int
{
    $bien = 0;
    $fallos = 0;
    $saltos = 0;
    $grupo = null;
    $inicio = microtime(true);
    foreach (Marco::$pruebas as $p) {
        if ($p['grupo'] !== $grupo) {
            $grupo = $p['grupo'];
            echo "\n" . $grupo . "\n";
        }
        $t = microtime(true);
        try {
            ($p['fn'])();
            $bien++;
            printf("  ok    %s (%d ms)\n", $p['nombre'], (int) round((microtime(true) - $t) * 1000));
        } catch (Salto $e) {
            $saltos++;
            printf("  salto %s: %s\n", $p['nombre'], $e->getMessage());
        } catch (\Throwable $e) {
            $fallos++;
            printf("  FALLO %s\n        %s: %s\n", $p['nombre'], get_class($e), $e->getMessage());
            // Dónde: la primera línea de una prueba (no del marco).
            foreach ($e->getTrace() as $marco) {
                if (isset($marco['file'], $marco['line']) && strpos($marco['file'], '/tests/') !== false && basename($marco['file']) !== 'marco.php') {
                    printf("        en %s:%d\n", substr($marco['file'], strpos($marco['file'], '/tests/') + 1), $marco['line']);
                    break;
                }
            }
            if (!$e instanceof Fallo) {
                printf("        lanzada en %s:%d\n", $e->getFile(), $e->getLine());
            }
        }
    }
    foreach (Marco::$alAcabar as $fn) {
        $fn();
    }
    printf(
        "\n%d pruebas: %d bien, %d fallos, %d saltadas (%.1f s)\n",
        $bien + $fallos + $saltos,
        $bien,
        $fallos,
        $saltos,
        microtime(true) - $inicio,
    );

    return $fallos;
}
