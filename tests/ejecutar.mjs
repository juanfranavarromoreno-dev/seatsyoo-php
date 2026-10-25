// Pruebas del SDK de PHP con las de integración: las unitarias y, además, las que van contra un servidor de
// Seatsyoo de verdad. El servidor no está en este repositorio: se toma de la copia del repositorio del servidor
// a la que apunte la variable BUTACAS_DIR (con sus dependencias ya instaladas con pnpm install), y se levanta aquí
// con buildApp sobre una base en memoria (":memory:") en un puerto libre de 127.0.0.1. Prepara un espacio con su
// clave secreta, una clave de su entorno de pruebas (sk_test_…), el plano de ejemplo publicado y un evento (sin
// precios: Seatsyoo no los guarda), y se los pasa al PHP por variables de entorno.
//
//   BUTACAS_DIR=/ruta/al/servidor node tests/ejecutar.mjs               # unitarias e integración
//   BUTACAS_DIR=/ruta/al/servidor node tests/ejecutar.mjs integracion   # solo las de integración
//   node tests/ejecutar.mjs                                             # sin BUTACAS_DIR: solo las unitarias
//
// Sin BUTACAS_DIR, las de integración se saltan con un aviso y el resto se ejecuta igual. Con BUTACAS_DIR mal
// puesta (sin el servidor o sin sus dependencias), falla: quien la pone quiere esas pruebas. Necesita Node 24
// (ejecuta el TypeScript del servidor sin compilar) y PHP 8.1 o posterior («php» en el PATH, o PHP_BINARY).

import { spawn, spawnSync } from "node:child_process";
import { existsSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const aqui = dirname(fileURLToPath(import.meta.url));
const php = process.env.PHP_BINARY || "php";
const pedidas = process.argv.slice(2);
const carpetas = pedidas.length > 0 ? pedidas : ["unitarias", "integracion"];

/** Avisa del problema y sale con error. */
function fallar(mensaje) {
  console.error(`ERROR: ${mensaje}`);
  process.exit(1);
}

const sonda = spawnSync(php, ["-r", "echo PHP_VERSION_ID;"], { encoding: "utf8" });
if (sonda.error || sonda.status !== 0) {
  fallar(`no se puede ejecutar PHP («${php}»): instálalo o di cuál con PHP_BINARY=/ruta/a/php.`);
}
if (Number(sonda.stdout) < 80100) {
  fallar(`el SDK necesita PHP 8.1 o posterior y «${php}» es ${sonda.stdout}.`);
}

/** Lanza tests/ejecutar.php con estas carpetas y variables de entorno, y devuelve su código de salida. */
function ejecutarPhp(lista, entorno) {
  return new Promise((listo) => {
    const hijo = spawn(php, [join(aqui, "ejecutar.php"), ...lista], { stdio: "inherit", env: { ...process.env, ...entorno } });
    hijo.on("error", (err) => {
      console.error(err);
      listo(1);
    });
    hijo.on("close", (codigo) => listo(codigo ?? 1));
  });
}

if (!carpetas.includes("integracion")) {
  process.exit(await ejecutarPhp(carpetas, {}));
}

const butacasDir = process.env.BUTACAS_DIR?.trim();
if (!butacasDir) {
  console.warn(
    "AVISO: sin BUTACAS_DIR se saltan las pruebas de integración (las que levantan un servidor de Seatsyoo de verdad).\n" +
      "       Para lanzarlas: BUTACAS_DIR=/ruta/a/la/copia/del/servidor node tests/ejecutar.mjs (mira «Pruebas» en el README).",
  );
  const resto = carpetas.filter((c) => c !== "integracion");
  process.exit(resto.length > 0 ? await ejecutarPhp(resto, {}) : 0);
}

// Con BUTACAS_DIR, lo que haga falta para levantar el servidor tiene que estar.
const raiz = resolve(butacasDir);
const servidor = join(raiz, "apps", "server");
if (!existsSync(join(servidor, "src", "app.ts")) || !existsSync(join(raiz, "packages", "core", "src", "index.ts"))) {
  fallar(`BUTACAS_DIR=${butacasDir} no es una copia del repositorio del servidor de Seatsyoo (le falta apps/server/src/app.ts o packages/core/src/index.ts).`);
}
if (!existsSync(join(servidor, "node_modules"))) {
  fallar(`el servidor de BUTACAS_DIR=${butacasDir} no tiene sus dependencias: ejecuta «pnpm install --frozen-lockfile» en esa carpeta.`);
}
const nodeMayor = Number(process.versions.node.split(".")[0]);
if (nodeMayor < 24) {
  fallar(`las pruebas de integración necesitan Node 24 o posterior (ejecutan el TypeScript del servidor) y este es ${process.version}.`);
}

// Los avisos de lo experimental de Node (node:sqlite, que usa el servidor) aquí solo estorban; los demás, se ven.
process.removeAllListeners("warning");
process.on("warning", (aviso) => {
  if (aviso.name !== "ExperimentalWarning") console.warn(aviso);
});

const importar = (ruta) => import(pathToFileURL(join(raiz, ruta)).href);
const { buildApp } = await importar("apps/server/src/app.ts");
const { createUser } = await importar("apps/server/src/users.ts");
const { addSecretKey, createWorkspace, testEnvironmentOf } = await importar("apps/server/src/workspaces.ts");
const { sampleTheatre } = await importar("packages/core/src/index.ts");
// Las claves de cuenta (integración con Tikyoo) solo están en los servidores que ya las tienen: sin ellas, sus pruebas
// se saltan.
const cuentas = existsSync(join(servidor, "src", "accountKeys.ts")) ? await importar("apps/server/src/accountKeys.ts") : null;

// El repartidor de webhooks en marcha (cada segundo) y con permiso para enviar a 127.0.0.1, donde escucha el
// receptor de las pruebas.
const { app, ctx } = buildApp({ dbPath: ":memory:", webhooks: { allowPrivate: true, timeoutMs: 5000 } });
let codigo = 1;
try {
  await app.listen({ host: "127.0.0.1", port: 0 });
  const { port } = app.server.address();
  const usuario = createUser(ctx, "php@pruebas.test", "Pruebas PHP", "una-contraseña-bastante-larga");
  const { workspace, secretKey } = createWorkspace(ctx, "Pruebas PHP", usuario.id);
  // El espacio nace con su entorno de pruebas; su clave secreta se crea en el panel (aquí, directamente).
  const pruebas = testEnvironmentOf(ctx, workspace);
  const claveDePruebas = addSecretKey(ctx, pruebas, usuario.id).key;

  const api = async (method, url, payload) => {
    const res = await app.inject({ method, url: `/api/v1${url}`, payload, headers: { authorization: `Bearer ${secretKey}` } });
    if (res.statusCode >= 300) throw new Error(`${method} ${url}: ${res.statusCode} ${res.body}`);
    return res.json();
  };
  await api("POST", "/charts", { name: "Teatro de ejemplo", key: "teatro", document: sampleTheatre() });
  await api("POST", "/charts/teatro/publish");
  await api("POST", "/events", { chartKey: "teatro", key: "hamlet", name: "Hamlet", date: "2026-11-20T20:30:00+01:00" });

  const claveDeCuenta = cuentas ? cuentas.createAccountKey(ctx, workspace.public_key, "php@pruebas.test") : "";

  console.log(`Servidor de Seatsyoo de pruebas en http://127.0.0.1:${port} (base en memoria, de ${raiz})`);
  codigo = await ejecutarPhp(carpetas, {
    SEATSYOO_TEST_SERVER: `http://127.0.0.1:${port}`,
    SEATSYOO_TEST_SECRET_KEY: secretKey,
    SEATSYOO_TEST_PUBLIC_KEY: workspace.public_key,
    SEATSYOO_TEST_TEST_SECRET_KEY: claveDePruebas,
    SEATSYOO_TEST_TEST_PUBLIC_KEY: pruebas.public_key,
    SEATSYOO_TEST_EVENT: "hamlet",
    SEATSYOO_TEST_ACCOUNT_KEY: claveDeCuenta,
  });
} finally {
  await app.close();
}
process.exit(codigo);
