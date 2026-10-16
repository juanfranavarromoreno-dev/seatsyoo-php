// Revisión estática del SDK de PHP (pnpm typecheck): la sintaxis de cada archivo con «php -l», que nada use
// lo que no existe en PHP 8.1 (la versión mínima) ni lo que ya está obsoleto, que src/ siga PSR-4 y que
// composer.json sea válido. Sin PHP en el equipo, avisa y no hace nada.

import { spawnSync } from "node:child_process";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const paquete = join(dirname(fileURLToPath(import.meta.url)), "..");
const php = process.env.PHP_BINARY || "php";
const problemas = [];

/** Los .php de una carpeta, recorriéndola entera. */
function archivosPhp(carpeta) {
  return readdirSync(carpeta).flatMap((nombre) => {
    const ruta = join(carpeta, nombre);
    if (statSync(ruta).isDirectory()) return archivosPhp(ruta);
    return ruta.endsWith(".php") ? [ruta] : [];
  });
}
const archivos = [join(paquete, "autoload.php"), ...["src", "tests", "ejemplo"].flatMap((c) => archivosPhp(join(paquete, c)))];

// Lo que llegó después de PHP 8.1 (o está obsoleto en las versiones nuevas) y no se puede usar.
const prohibido = [
  [/\breadonly\s+(final\s+|abstract\s+)?class\b/, "clases readonly (PHP 8.2)"],
  [/\b(true|false|null)\s+\$\w+|\):\s*\??(true|false|null)\b/, "tipos true, false o null sueltos (PHP 8.2)"],
  [/\(\s*\\?[A-Z]\w*\s*&\s*\\?[A-Z]\w*\s*\)\s*\|/, "tipos DNF (PHP 8.2)"],
  [/#\[\\?SensitiveParameter\]|\\Random\\Randomizer|\bmb_str_pad\s*\(|\bini_parse_quantity\s*\(/, "algo de PHP 8.2 o 8.3"],
  [/\bconst\s+\??[A-Za-z_\\|]+\s+[A-Za-z_]\w*\s*=/, "constantes con tipo (PHP 8.3)"],
  [/#\[\\?Override\]|\bjson_validate\s*\(|\bstr_(increment|decrement)\s*\(/, "algo de PHP 8.3"],
  [/\b(private|protected|public)\(set\)|\barray_(find|find_key|any|all)\s*\(|\bmb_(trim|ltrim|rtrim|ucfirst|lcfirst)\s*\(/, "algo de PHP 8.4"],
  [/\bnew\s+[\w\\]+\([^()]*\)\s*->/, "new sin paréntesis alrededor (PHP 8.4)"],
  [/\|>|\barray_(first|last)\s*\(|#\[\\?NoDiscard\]|\bclone\s+with\b/, "algo de PHP 8.5"],
  [/\bcurl_close\s*\(|\$http_response_header\b|\butf8_(en|de)code\s*\(/, "funciones obsoletas"],
];

if (spawnSync(php, ["-v"]).status !== 0) {
  console.warn("AVISO: no hay PHP en este equipo: no se revisa la sintaxis de sdk/php.");
} else {
  for (const archivo of archivos) {
    const r = spawnSync(php, ["-l", "-d", "display_errors=1", "-d", "error_reporting=-1", archivo], { encoding: "utf8" });
    const salida = `${r.stdout}${r.stderr}`;
    if (r.status !== 0 || /Deprecated|Warning/.test(salida)) problemas.push(`${relative(paquete, archivo)}: ${salida.trim()}`);
  }
}

for (const archivo of archivos) {
  // Sin comentarios ni textos: solo el código.
  const codigo = readFileSync(archivo, "utf8")
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|[^:])\/\/.*$/gm, "$1")
    .replace(/'(?:[^'\\]|\\.)*'/g, "''");
  for (const [patron, que] of prohibido) {
    if (patron.test(codigo)) problemas.push(`${relative(paquete, archivo)}: usa ${que}`);
  }
  if (!/^<\?php\s+(\/\*\*[\s\S]*?\*\/\s+)?declare\(strict_types=1\);/.test(readFileSync(archivo, "utf8"))) {
    problemas.push(`${relative(paquete, archivo)}: falta declare(strict_types=1) al principio`);
  }
}

// PSR-4: Seatsyoo\X\Y en src/X/Y.php, una clase por archivo con el nombre del archivo.
for (const archivo of archivosPhp(join(paquete, "src"))) {
  const ruta = relative(join(paquete, "src"), archivo).replace(/\.php$/, "");
  const partes = ruta.split("/");
  const clase = partes.pop();
  const espacio = ["Seatsyoo", ...partes].join("\\");
  const codigo = readFileSync(archivo, "utf8");
  if (!codigo.includes(`namespace ${espacio};`)) problemas.push(`src/${ruta}.php: el namespace debería ser ${espacio}`);
  if (!new RegExp(`^(final |abstract )?(class|interface|trait|enum) ${clase}\\b`, "m").test(codigo)) {
    problemas.push(`src/${ruta}.php: debería declarar ${clase}`);
  }
}

const composer = JSON.parse(readFileSync(join(paquete, "composer.json"), "utf8"));
if (composer.name !== "seatsyoo/seatsyoo-php") problemas.push("composer.json: el nombre ha de ser seatsyoo/seatsyoo-php");
if (composer.require?.php !== ">=8.1") problemas.push("composer.json: ha de pedir php >=8.1");
if (Object.keys(composer.require ?? {}).some((d) => d !== "php")) problemas.push("composer.json: el SDK no tiene dependencias");
if (composer.autoload?.["psr-4"]?.["Seatsyoo\\"] !== "src/") problemas.push("composer.json: autoload PSR-4 de Seatsyoo\\ en src/");

if (problemas.length > 0) {
  console.error(problemas.join("\n"));
  process.exit(1);
}
console.log(`sdk/php: ${archivos.length} archivos revisados`);
