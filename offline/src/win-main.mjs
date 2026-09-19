/**
 * Entry point for the installed Windows application (a Node single executable).
 *
 *   SoftCora-POS.exe                          → run the till
 *   SoftCora-POS.exe --cli status             → the same commands as `node src/cli.mjs …`
 *   SoftCora-POS.exe --cli sync               → sync from a script or Task Scheduler
 *
 * Configuration comes from the environment, so a shortcut or a service can point
 * one installation at a different port without touching the code:
 *
 *   SOFTCORA_DATA    where the database, device identity and backups live
 *   SOFTCORA_SERVER  the central server address
 *   SOFTCORA_PORT    the local port the till listens on (7817)
 *   SOFTCORA_HOST    the interface to listen on (0.0.0.0, so a phone on the
 *                    shop's own network can reach it; 127.0.0.1 to keep it local)
 *
 * There is no top-level await here on purpose: a packaged executable loads its
 * entry point as CommonJS, and CommonJS has none.
 */
async function main() {
  const args = process.argv.slice(2);

  if (args[0] === '--cli') {
    // The CLI reads process.argv itself; shift our marker out of the way.
    process.argv.splice(2, 1);
    await import('./cli.mjs');
    return;
  }

  const { startServer } = await import('./server.mjs');

  startServer({
    serverUrl: process.env.SOFTCORA_SERVER ?? null,
    port: Number(process.env.SOFTCORA_PORT ?? 7817),
    host: process.env.SOFTCORA_HOST ?? '0.0.0.0',
  });
}

main().catch((error) => {
  console.error(`SoftCora POS could not start: ${error?.message ?? error}`);
  console.error('The till database is untouched. Check the log and try again.');
  process.exit(1);
});
