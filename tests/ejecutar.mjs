// Pruebas del SDK de PHP (pnpm test): las unitarias y las de integración contra un servidor de Seatsyoo de
// verdad, levantado aquí con buildApp sobre una base en memoria (":memory:") en un puerto libre de 127.0.0.1.
// Prepara un espacio con su clave secreta, una clave de su entorno de pruebas (sk_test_…), el plano de ejemplo
// publicado y un evento (sin precios: Seatsyoo no los guarda), y se los pasa al PHP por variables de entorno. Sin PHP
// en el equipo, avisa y no hace nada.
//
//   node tests/ejecutar.mjs              # todo
//   node tests/ejecutar.mjs unitarias    # solo las unitarias (sin servidor)

import { spawn, spawnSync } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const aqui = dirname(fileURLToPath(import.meta.url));
const raiz = join(aqui, "..", "..", "..");
const php = process.env.PHP_BINARY || "php";
const carpetas = process.argv.slice(2);

const sonda = spawnSync(php, ["-r", "echo PHP_VERSION_ID;"], { encoding: "utf8" });
if (sonda.error || sonda.status !== 0) {
  console.warn("AVISO: no hay PHP en este equipo («php» no está en el PATH): se saltan las pruebas del SDK de PHP (sdk/php).");
  process.exit(0);
}
if (Number(sonda.stdout) < 80100) {
  console.warn(`AVISO: el SDK de PHP necesita PHP 8.1 o posterior y aquí hay ${sonda.stdout}: se saltan sus pruebas.`);
  process.exit(0);
}

/** Lanza el PHP con estas variables de entorno y devuelve su código de salida. */
function ejecutarPhp(entorno) {
  return new Promise((resolve) => {
    const hijo = spawn(php, [join(aqui, "ejecutar.php"), ...carpetas], { stdio: "inherit", env: { ...process.env, ...entorno } });
    hijo.on("error", (err) => {
      console.error(err);
      resolve(1);
    });
    hijo.on("close", (codigo) => resolve(codigo ?? 1));
  });
}

if (carpetas.length > 0 && !carpetas.includes("integracion")) {
  process.exit(await ejecutarPhp({}));
}

const importar = (ruta) => import(pathToFileURL(join(raiz, ruta)).href);
const { buildApp } = await importar("apps/server/src/app.ts");
const { createUser } = await importar("apps/server/src/users.ts");
const { addSecretKey, createWorkspace, testEnvironmentOf } = await importar("apps/server/src/workspaces.ts");
const { sampleTheatre } = await importar("packages/core/src/index.ts");

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

  console.log(`Servidor de Seatsyoo de pruebas en http://127.0.0.1:${port} (base en memoria)`);
  codigo = await ejecutarPhp({
    SEATSYOO_TEST_SERVER: `http://127.0.0.1:${port}`,
    SEATSYOO_TEST_SECRET_KEY: secretKey,
    SEATSYOO_TEST_PUBLIC_KEY: workspace.public_key,
    SEATSYOO_TEST_TEST_SECRET_KEY: claveDePruebas,
    SEATSYOO_TEST_TEST_PUBLIC_KEY: pruebas.public_key,
    SEATSYOO_TEST_EVENT: "hamlet",
  });
} finally {
  await app.close();
}
process.exit(codigo);
