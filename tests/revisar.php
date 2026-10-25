<?php

/**
 * Revisión estática del SDK, solo con PHP:
 *
 *     php tests/revisar.php
 *
 * - La sintaxis de cada .php del repositorio con «php -l» (con este PHP: el CI lo pasa con cada versión).
 * - Que nada use lo que no existe en PHP 8.1 (la versión mínima) ni lo que ya está obsoleto.
 * - Que cada archivo empiece con declare(strict_types=1) y que src/ siga PSR-4.
 * - Que composer.json diga lo que Packagist necesita (nombre, licencia, PHP mínimo, extensiones, autoload).
 * - Que la descarga de Composer lleve solo el SDK: lo demás, con export-ignore en .gitattributes.
 * - Que ni el README ni el código nombren a la sociedad (solo va en LICENSE).
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'El SDK necesita PHP 8.1 o posterior (este es ' . PHP_VERSION . ")\n");
    exit(1);
}

$raiz = dirname(__DIR__);
$problemas = [];

/**
 * Los .php de una carpeta, recorriéndola entera (sin carpetas ocultas, vendor/ ni node_modules/).
 *
 * @return list<string>
 */
function archivosPhp(string $carpeta): array
{
    $archivos = [];
    $nombres = scandir($carpeta);
    if ($nombres === false) {
        return [];
    }
    foreach ($nombres as $nombre) {
        if ($nombre[0] === '.' || $nombre === 'vendor' || $nombre === 'node_modules') {
            continue;
        }
        $ruta = $carpeta . '/' . $nombre;
        if (is_dir($ruta)) {
            array_push($archivos, ...archivosPhp($ruta));
        } elseif (substr($nombre, -4) === '.php') {
            $archivos[] = $ruta;
        }
    }
    sort($archivos);

    return $archivos;
}

function relativa(string $raiz, string $ruta): string
{
    return ltrim(substr($ruta, strlen($raiz)), '/');
}

$archivos = archivosPhp($raiz);

// La sintaxis, con este mismo PHP y con todos los avisos (un «Deprecated» al compilar también es un problema).
foreach ($archivos as $archivo) {
    $orden = [PHP_BINARY, '-n', '-l', '-d', 'display_errors=1', '-d', 'error_reporting=-1', $archivo];
    $proceso = proc_open($orden, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    if (!is_resource($proceso)) {
        $problemas[] = relativa($raiz, $archivo) . ': no se pudo ejecutar php -l';
        continue;
    }
    $salida = stream_get_contents($tuberias[1]) . stream_get_contents($tuberias[2]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);
    if (proc_close($proceso) !== 0 || preg_match('/Deprecated|Warning/', $salida) === 1) {
        $problemas[] = relativa($raiz, $archivo) . ': ' . trim($salida);
    }
}

// Lo que llegó después de PHP 8.1 (o está obsoleto en las versiones nuevas) y no se puede usar. «\x5c» es la
// barra invertida (\) de los nombres con espacio de nombres.
$prohibido = [
    ['~\breadonly\s+(final\s+|abstract\s+)?class\b~', 'clases readonly (PHP 8.2)'],
    ['~\b(true|false|null)\s+\$\w+|\):\s*\??(true|false|null)\b~', 'tipos true, false o null sueltos (PHP 8.2)'],
    ['~\(\s*\x5c?[A-Z]\w*\s*&\s*\x5c?[A-Z]\w*\s*\)\s*\|~', 'tipos DNF (PHP 8.2)'],
    ['~#\[\x5c?SensitiveParameter\]|\x5cRandom\x5cRandomizer|\bmb_str_pad\s*\(|\bini_parse_quantity\s*\(~', 'algo de PHP 8.2 o 8.3'],
    ['~\bconst\s+\??[A-Za-z_\x5c|]+\s+[A-Za-z_]\w*\s*=~', 'constantes con tipo (PHP 8.3)'],
    ['~#\[\x5c?Override\]|\bjson_validate\s*\(|\bstr_(increment|decrement)\s*\(~', 'algo de PHP 8.3'],
    ['~\b(private|protected|public)\(set\)|\barray_(find|find_key|any|all)\s*\(|\bmb_(trim|ltrim|rtrim|ucfirst|lcfirst)\s*\(~', 'algo de PHP 8.4'],
    ['~\bnew\s+[\w\x5c]+\([^()]*\)\s*->~', 'new sin paréntesis alrededor (PHP 8.4)'],
    ['~\|>|\barray_(first|last)\s*\(|#\[\x5c?NoDiscard\]|\bclone\s+with\b~', 'algo de PHP 8.5'],
    ['~\bcurl_close\s*\(|\$http_response_header\b|\butf8_(en|de)code\s*\(~', 'funciones obsoletas'],
];

foreach ($archivos as $archivo) {
    $fuente = (string) file_get_contents($archivo);
    // Sin comentarios ni textos entre comillas simples: solo el código.
    $codigo = (string) preg_replace('~/\*.*?\*/~s', '', $fuente);
    $codigo = (string) preg_replace('~(^|[^:])//.*$~m', '$1', $codigo);
    $codigo = (string) preg_replace("~'(?:[^'\\x5c]|\\x5c.)*'~", "''", $codigo);
    foreach ($prohibido as [$patron, $que]) {
        if (preg_match($patron, $codigo) === 1) {
            $problemas[] = relativa($raiz, $archivo) . ": usa $que";
        }
    }
    if (preg_match('~^<\?php\s+(/\*\*.*?\*/\s+)?declare\(strict_types=1\);~s', $fuente) !== 1) {
        $problemas[] = relativa($raiz, $archivo) . ': falta declare(strict_types=1) al principio';
    }
}

// PSR-4: Seatsyoo\X\Y en src/X/Y.php, una clase por archivo con el nombre del archivo.
foreach (archivosPhp($raiz . '/src') as $archivo) {
    $ruta = substr(relativa($raiz . '/src', $archivo), 0, -4);
    $partes = explode('/', $ruta);
    $clase = (string) array_pop($partes);
    $espacio = implode('\\', ['Seatsyoo', ...$partes]);
    $codigo = (string) file_get_contents($archivo);
    if (strpos($codigo, "namespace $espacio;") === false) {
        $problemas[] = "src/$ruta.php: el namespace debería ser $espacio";
    }
    if (preg_match('~^(final |abstract )?(class|interface|trait|enum) ' . preg_quote($clase, '~') . '\b~m', $codigo) !== 1) {
        $problemas[] = "src/$ruta.php: debería declarar $clase";
    }
}

// composer.json: lo que Packagist necesita y lo que promete el README (sin dependencias, solo PHP y extensiones).
$composer = json_decode((string) file_get_contents($raiz . '/composer.json'), true);
if (!is_array($composer)) {
    $problemas[] = 'composer.json: no es JSON válido';
    $composer = [];
}
$require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
if (($composer['name'] ?? null) !== 'seatsyoo/seatsyoo-php') {
    $problemas[] = 'composer.json: el nombre ha de ser seatsyoo/seatsyoo-php';
}
if (($composer['license'] ?? null) !== 'MIT') {
    $problemas[] = 'composer.json: la licencia ha de ser MIT';
}
if (($require['php'] ?? null) !== '>=8.1') {
    $problemas[] = 'composer.json: ha de pedir php >=8.1';
}
foreach (['ext-json', 'ext-hash', 'ext-pcre'] as $extension) {
    if (!isset($require[$extension])) {
        $problemas[] = "composer.json: ha de pedir $extension (la usa el SDK)";
    }
}
foreach (array_keys($require) as $dependencia) {
    if ($dependencia !== 'php' && strncmp((string) $dependencia, 'ext-', 4) !== 0) {
        $problemas[] = "composer.json: el SDK no tiene dependencias de paquetes ($dependencia)";
    }
}
if (isset($composer['version'])) {
    $problemas[] = 'composer.json: sin «version» (Packagist la toma de la etiqueta vX.Y.Z)';
}
if (($composer['autoload']['psr-4']['Seatsyoo\\'] ?? null) !== 'src/') {
    $problemas[] = 'composer.json: autoload PSR-4 de Seatsyoo\\ en src/';
}
if (($composer['homepage'] ?? null) !== 'https://seatsyoo.tikyoo.es') {
    $problemas[] = 'composer.json: falta la página del producto (homepage)';
}

// La descarga de Composer (el zip de cada etiqueta) lleva solo esto; el resto ha de ir con export-ignore.
$enLaDescarga = ['LICENSE', 'README.md', 'autoload.php', 'composer.json', 'ejemplo', 'src'];
$git = static function (array $argumentos) use ($raiz): ?string {
    $proceso = proc_open(['git', '-C', $raiz, ...$argumentos], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    if (!is_resource($proceso)) {
        return null;
    }
    $salida = (string) stream_get_contents($tuberias[1]);
    stream_get_contents($tuberias[2]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);

    return proc_close($proceso) === 0 ? $salida : null;
};
$listado = $git(['ls-files', '--cached', '--others', '--exclude-standard']);
if ($listado === null) {
    echo "Sin git (o fuera de un repositorio): no se revisa qué lleva la descarga de Composer.\n";
} else {
    $primeros = [];
    foreach (explode("\n", trim($listado)) as $ruta) {
        if ($ruta !== '') {
            $primeros[explode('/', $ruta)[0]] = true;
        }
    }
    foreach (array_keys($primeros) as $entrada) {
        $atributo = $git(['check-attr', 'export-ignore', '--', (string) $entrada]);
        $ignorado = $atributo !== null && preg_match('/: export-ignore: set$/', trim($atributo)) === 1;
        $va = in_array($entrada, $enLaDescarga, true);
        if ($va && $ignorado) {
            $problemas[] = ".gitattributes: $entrada ha de ir en la descarga de Composer (quita su export-ignore)";
        } elseif (!$va && !$ignorado) {
            $problemas[] = ".gitattributes: $entrada no debe ir en la descarga de Composer (añade «/$entrada export-ignore»)";
        }
    }
}

// La marca: el README y el código hablan de Seatsyoo; la sociedad solo va en LICENSE.
foreach ([$raiz . '/README.md', ...archivosPhp($raiz . '/src'), ...archivosPhp($raiz . '/ejemplo'), $raiz . '/autoload.php'] as $archivo) {
    if (preg_match('/tikyoo/i', (string) file_get_contents($archivo)) === 1) {
        $problemas[] = relativa($raiz, $archivo) . ': nombra a la sociedad (solo va en LICENSE)';
    }
}

if ($problemas !== []) {
    fwrite(STDERR, implode("\n", $problemas) . "\n");
    exit(1);
}
echo 'Revisión con PHP ' . PHP_VERSION . ': ' . count($archivos) . " archivos .php, composer.json y .gitattributes, bien\n";
