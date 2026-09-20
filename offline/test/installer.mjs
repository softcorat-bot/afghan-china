/**
 * The Windows installer, tested as far as it can be on a build machine that is
 * not Windows.
 *
 * The installer is a pile of .cmd and .ps1 files that run on a shop's till and
 * nowhere else, which is exactly why it broke once in a way nobody here could
 * see: a typographic dash in a UTF-8 script, read by Windows PowerShell 5.1 with
 * the machine's ANSI code page, became a string delimiter and the script stopped
 * parsing three lines away from the cause.
 *
 * So these checks are about the contracts the payload lives by:
 *
 *   * the payload passes installer/check-payload.mjs, and that check actually
 *     fails when the payload is broken the way it once was (a guard nobody has
 *     seen fail is not a guard);
 *   * what the build ships - CRLF, ASCII, marked .ps1, unmarked .cmd - is what
 *     cmd.exe and Windows PowerShell expect;
 *   * the promises install.ps1 makes about the data folder, the port and the
 *     exit codes are still in the script;
 *   * the environment variables the launchers set are the ones the till reads;
 *   * the upgrade gate the installer parses still prints the JSON it parses.
 *
 *   node test/installer.mjs
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { openDatabase, setMeta } from '../src/db.mjs';

const offlineDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const payloadDir = path.join(offlineDir, 'installer', 'payload');
const checker = path.join(offlineDir, 'installer', 'check-payload.mjs');

const results = [];
let failures = 0;

async function step(name, fn) {
  try {
    await fn();
    results.push(`  ok   ${name}`);
  }
  catch (error) {
    failures++;
    results.push(`  FAIL ${name}\n       ${error.message.split('\n').join('\n       ')}`);
  }
}

/* -- helpers --------------------------------------------------------------- */

function freshCopy(label, source = payloadDir) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), `softcora-${label}-`));
  const target = path.join(dir, 'payload');
  fs.cpSync(source, target, { recursive: true });
  // check-payload.mjs looks for the SFX configuration next to the payload, the
  // way the real installer folder is laid out.
  fs.copyFileSync(path.join(offlineDir, 'installer', 'sfx-config.txt'), path.join(dir, 'sfx-config.txt'));
  return { dir, payload: target };
}

/** Runs the payload check and returns what it decided, without throwing. */
function runChecker(target, args = []) {
  const run = spawnSync(process.execPath, [checker, target, '--no-pwsh', ...args], {
    encoding: 'utf8', cwd: offlineDir,
  });
  return { code: run.status, output: (run.stdout ?? '') + (run.stderr ?? '') };
}

function read(file) {
  return fs.readFileSync(file, 'utf8');
}

/**
 * The code of a PowerShell script, without its comments.
 *
 * The payload scripts carry their history in the header comment - including the
 * exact line that once broke an install - so assertions about what the script
 * does have to be made against the code alone.
 */
function codeOnly(text) {
  return text
    .replace(/<#[\s\S]*?#>/g, '')
    .split(/\r?\n/)
    .filter((line) => !/^\s*#/.test(line))
    .join('\n');
}

/* -- the checks ------------------------------------------------------------ */

await step('the payload in version control passes its own check', () => {
  const { code, output } = runChecker(payloadDir);
  assert.equal(code, 0, `check-payload.mjs rejected the committed payload:\n${output}`);
});

await step('a typographic dash in a payload script is caught before it ships', () => {
  // The real bug, reproduced: an em dash inside a double-quoted string of a
  // mark-less UTF-8 script. Read as cp1252 its last byte is U+201D, which
  // PowerShell accepts as a closing quote, so the quoting stops balancing.
  const { dir, payload } = freshCopy('dash');
  const script = path.join(payload, 'install.ps1');
  const text = read(script);
  fs.writeFileSync(script, text.replace(
    /Write-Log 'copying program files'/,
    "Write-Log \u201ccopying program files\u201d",
  ));
  // A second copy of the trap: the em dash, which is what the file actually had.
  fs.writeFileSync(path.join(payload, 'uninstall.ps1'), read(path.join(payload, 'uninstall.ps1')).replace(
    "Say 'removing program files'",
    "Say 'removing program files \u2014 now'",
  ));

  const { code, output } = runChecker(payload);
  assert.equal(code, 1, 'the check let a payload through that Windows PowerShell would misread');
  assert.match(output, /not ASCII/, 'the report should name the character that is not ASCII');
  assert.match(output, /U\+201D|double quote/, 'the report should say why an em dash is dangerous');

  fs.rmSync(dir, { recursive: true, force: true });
});

await step('a misread script is reported as unbalanced, at the line Windows blames', () => {
  // Exactly the shape of the field failure: everything looks fine read as UTF-8,
  // and only the cp1252 reading leaves a string open. The check has to say so.
  const { dir, payload } = freshCopy('unbalanced');
  const script = path.join(payload, 'verify.ps1');
  const broken = [
    '$ErrorActionPreference = \'Stop\'',
    'Write-Host "Done \u2014 data folder kept at $DataDir"',
    'Write-Host \'  Next:\'',
    'Write-Host \'    1. The till opens in your browser (http://127.0.0.1:\' -NoNewline; Write-Host "$Port)."',
    '',
  ].join('\r\n');
  fs.writeFileSync(script, Buffer.from(broken, 'utf8'));

  const { code, output } = runChecker(payload);
  assert.equal(code, 1, 'the check let an unparseable script through');
  assert.match(output, /as cp1252[^\n]*never closed/, 'the report should say which reading breaks and where the string was opened');

  fs.rmSync(dir, { recursive: true, force: true });
});

await step('--fix spells the typographic characters out and the payload then passes', () => {
  const { dir, payload } = freshCopy('fix');
  fs.writeFileSync(
    path.join(payload, 'install.cmd'),
    read(path.join(payload, 'install.cmd')).replace('SoftCora POS - offline point of sale', 'SoftCora POS \u2014 offline point of sale'),
  );

  assert.equal(runChecker(payload).code, 1, 'the broken payload should fail before it is fixed');

  const fixed = runChecker(payload, ['--fix']);
  assert.equal(fixed.code, 0, `--fix did not repair the payload:\n${fixed.output}`);
  assert.match(fixed.output, /spelled out in ASCII/, '--fix should say what it changed');
  assert.equal(runChecker(payload).code, 0, 'the payload should pass after --fix');

  fs.rmSync(dir, { recursive: true, force: true });
});

await step('what the build ships is what cmd.exe and Windows PowerShell expect', () => {
  const { dir, payload } = freshCopy('shipped');

  // The same two calls the build makes: normalise, then verify against the rules
  // for a payload that is about to be packed into the installer.
  assert.equal(runChecker(payload, ['--fix', '--bom']).code, 0, 'normalising the payload should succeed');
  const verified = runChecker(payload, ['--require-crlf', '--require-bom']);
  assert.equal(verified.code, 0, `the normalised payload broke the shipping rules:\n${verified.output}`);

  for (const file of fs.readdirSync(payload)) {
    const full = path.join(payload, file);
    if (!fs.statSync(full).isFile()) continue;
    const bytes = fs.readFileSync(full);
    const extension = path.extname(file).toLowerCase();
    const marked = bytes.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf]));
    const bareLf = (bytes.toString('latin1').match(/(?<!\r)\n/g) ?? []).length;

    if (extension === '.ps1') assert.ok(marked, `${file} ships without a UTF-8 mark, so PowerShell 5.1 reads it with the ANSI code page`);
    if (extension === '.cmd') assert.ok(!marked, `${file} ships with a UTF-8 mark, which cmd.exe prints before @echo off`);
    assert.equal(bareLf, 0, `${file} has LF-only lines, which cmd.exe mis-parses`);
  }

  fs.rmSync(dir, { recursive: true, force: true });
});

await step('the launchers install.ps1 generates are checked as .cmd files', () => {
  // They exist nowhere but on a shop's PC, so a wrong jump in one is invisible
  // until a till fails to open. The checker reads them back out of the script.
  const { dir, payload } = freshCopy('generated');
  const script = path.join(payload, 'install.ps1');
  fs.writeFileSync(script, read(script).replace(
    "'if not errorlevel 1 goto open',",
    "'if not errorlevel 1 goto opne',",
  ));

  const { code, output } = runChecker(payload);
  assert.equal(code, 1, 'a broken jump in a generated launcher went unnoticed');
  assert.match(output, /the SoftCora POS\.cmd it generates/, 'the report should say which generated file is wrong');
  assert.match(output, /jumps to :opne, which has no label/, 'the report should name the missing label');

  fs.rmSync(dir, { recursive: true, force: true });
});

await step('install.cmd hands over to install.ps1 and honours its exit codes', () => {
  const text = read(path.join(payloadDir, 'install.cmd'));

  assert.match(text, /set "PAYLOAD=%~dp0"/, 'install.cmd must work from the folder the setup unpacked into');
  assert.match(text, /-ExecutionPolicy Bypass -File "%PAYLOAD%install\.ps1" %\*/, 'install.cmd must run the script next to it and forward arguments');
  assert.match(text, /if "%RESULT%"=="3" set "LAUNCH=0"/, 'exit code 3 means "installed, do not launch"');
  assert.match(text, /if "%RESULT%"=="3" set "RESULT=0"/, 'exit code 3 is a success, not a failure');
  assert.match(text, /if not "%RESULT%"=="0" goto failed/, 'anything but success must be reported as a failure');
  assert.match(text, /set "ROOT=%LOCALAPPDATA%\\SoftCoraPOS"/, 'the installed location must be the per-user folder, never Program Files');
  assert.match(text, /start "" "%ROOT%\\app\\SoftCora POS\.cmd"/, 'the launcher that starts must be the installed one, not the temporary copy');
  assert.match(text, /install\.log/, 'a failure must point at the install log');
});

await step('install.ps1 keeps the promises the shop relies on', () => {
  // Assertions are made against the code, not the prose: the header comment
  // quotes the broken line that this file once shipped, on purpose, as the
  // reason the ASCII rule exists.
  const text = codeOnly(read(path.join(payloadDir, 'install.ps1')));

  // The data folder is created when missing and never removed: an install that
  // could delete it would be an install that can lose unsynced sales.
  assert.ok(!/Remove-Item[^\r\n]*\$DataDir/.test(text), 'install.ps1 must never delete the data folder');
  assert.match(text, /if \(-not \(Test-Path -LiteralPath \$directory\)\)/, 'folders are created only when missing');
  assert.match(text, /'--cli', 'verify'/, 'an update asks the database whether it is safe to continue');
  assert.match(text, /The update was refused to protect the shop data/, 'a refused update must stop the install, not continue');
  assert.match(text, /PSBoundParameters\.ContainsKey\('Port'\)/, 'an update keeps the port this PC already uses unless one is given');
  assert.match(text, /if \(\$NoLaunch\) \{ exit 3 \}/, 'the exit codes install.cmd reads must still be there');
  assert.match(text, /Add-Content -LiteralPath \$LogFile/, 'every run must leave a log behind');
  assert.match(text, /Get-FileHash -LiteralPath \$PayloadExe/, 'the copied program is compared with the payload it came from');

  // The line that broke the install was two Write-Host calls splicing a string
  // together mid-sentence. Splitting a sentence across calls is what made one
  // misread quote swallow three lines, so the summary is built with -f instead.
  assert.ok(!/-NoNewline;\s*Write-Host/.test(text), 'a message must not be spliced together from two Write-Host calls');
});

await step('the launchers set only environment variables somebody reads', () => {
  const sources = fs.readdirSync(path.join(offlineDir, 'src'), { recursive: true })
    .filter((file) => String(file).endsWith('.mjs'))
    .map((file) => read(path.join(offlineDir, 'src', String(file))))
    .join('\n');

  const readByTill = new Set([...sources.matchAll(/process\.env\.(SOFTCORA_[A-Z_]+)/g)].map((match) => match[1]));
  assert.ok(readByTill.has('SOFTCORA_DATA') && readByTill.has('SOFTCORA_PORT'), 'the till must still read its data folder and port from the environment');

  const launcherFiles = [
    path.join(payloadDir, 'SoftCora POS.cmd'),
    path.join(payloadDir, 'start-till.cmd'),
    path.join(payloadDir, 'install.ps1'),
  ];

  for (const file of launcherFiles) {
    const text = read(file);
    for (const [, name] of text.matchAll(/\b(SOFTCORA_[A-Z_]+)\b/g)) {
      // A name is either configuration the till reads, or the launcher's own
      // bookkeeping that it expands again a few lines later. A third thing -
      // set and read by nobody - is a launcher that has silently stopped
      // configuring the till, which is what this catches.
      const readByLauncher = new RegExp(`%${name}%|\\$env:${name}\\b`).test(text);
      assert.ok(readByTill.has(name) || readByLauncher, `${path.basename(file)} sets ${name}, which nothing reads`);
    }
  }
});

await step('the upgrade gate prints the JSON the installer parses', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'softcora-verify-'));

  const run = (dataDir) => spawnSync(process.execPath, [path.join(offlineDir, 'src', 'cli.mjs'), 'verify'], {
    encoding: 'utf8', cwd: offlineDir, env: { ...process.env, SOFTCORA_DATA: dataDir },
  });

  // A first install: no database yet, nothing to lose, safe to proceed.
  const fresh = run(dir);
  assert.equal(fresh.status, 0, `--cli verify failed on an empty data folder:\n${fresh.stderr}`);

  let verdict = null;
  try { verdict = JSON.parse(fresh.stdout); }
  catch { assert.fail(`--cli verify printed something the installer cannot parse: ${JSON.stringify(fresh.stdout.slice(0, 200))}`); }
  assert.equal(verdict.ok, true);
  assert.equal(verdict.first_install, true);

  // An existing till with work still in the outbox: the count install.ps1 reads.
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  setMeta(db, 'device_id', 'SC-POS-TEST01');
  db.prepare("insert into sync_queue (uuid, device_id, entity_type, entity_uuid, operation, payload, status, attempts, created_at) values (?, ?, 'sale', ?, 'create', '{}', 'pending', 0, ?)")
    .run('11111111-1111-4111-8111-111111111111', 'SC-POS-TEST01', '22222222-2222-4222-8222-222222222222', new Date().toISOString());
  db.close();

  const existing = run(dir);
  assert.equal(existing.status, 0, `--cli verify failed on an existing database:\n${existing.stderr}`);
  verdict = JSON.parse(existing.stdout);
  assert.equal(verdict.ok, true);
  assert.equal(verdict.device_id, 'SC-POS-TEST01');
  assert.equal(verdict.pending, 1, 'install.ps1 reports this count to the shop before it replaces anything');

  fs.rmSync(dir, { recursive: true, force: true });
});

await step('the SEA blob is written by the same Node version the till ships', () => {
  // The failure this guards against: a blob written by the build host's own
  // Node, injected into a runtime of another version. Node's deserializer reads
  // a format field it did not write and aborts the process, so the .exe starts
  // on no PC at all - and every check that runs on the build host passes,
  // because the blob agrees with the version that wrote it.
  const build = read(path.join(offlineDir, 'installer', 'build-windows.sh'));

  const pin = /^NODE_VERSION="\$\{SOFTCORA_NODE_VERSION:-([^}]+)\}"/m.exec(build);
  assert.ok(pin, 'build-windows.sh must pin the Node version the till ships with, so a Node release cannot change it between builds');
  assert.match(build, /if version not in meta\['versions'\]/, 'the pinned version must have to exist, or the build stops');

  assert.match(build, /"\$HOST_NODE" --experimental-sea-config/, 'the blob must be prepared by a runtime of the pinned version');
  assert.ok(!/"\$NODE" --experimental-sea-config/.test(build), "the build host's own Node must never write the blob");

  assert.match(build, /cp "\$HOST_NODE" "\$SELFCHECK"/, 'the packaged form must be self-tested under the pinned runtime');
  assert.ok(!/cp "\$NODE" "\$SELFCHECK"/.test(build), "the self-test must not run under the build host's Node");

  // Both halves are checked, not assumed: the fetched runtime is asked which
  // version it is, and the Windows binary - which cannot run here - has its
  // version read out of the version resource it carries.
  assert.match(build, /process\.versions\.node/, 'the build must ask the runtime which version it is');
  assert.match(build, /FileVersion/, 'the Windows runtime must be checked against the pin as well');
  assert.match(build, /node-v\$NODE_VERSION-win-x64\.exe/, 'a cached runtime must carry the version in its name, so a bump cannot reuse the old one');
});

await step('the built installer is run on Windows before it can ship', () => {
  // The other half of the same lesson: a Linux self-test can say a blob is fine
  // but it cannot say that a Windows .exe starts. So the artifact is unpacked
  // and run on a real Windows host by installer/verify-windows.ps1, and the
  // publish workflow waits for that before it uploads anything.
  const verifier = read(path.join(offlineDir, 'installer', 'verify-windows.ps1'));

  assert.match(verifier, /'--cli', 'status'/, 'the verifier must run the program the installer ships');
  assert.match(verifier, /api\/device/, 'the verifier must talk to the till over HTTP');
  assert.match(verifier, /install\.cmd/, 'the verifier must run the installer itself');
  assert.match(verifier, /install\.log/, 'the verifier must read what the installer logged');
  assert.match(verifier, /verify\.ps1/, 'the verifier must ask the installed till whether it is healthy');

  const until = read(path.join(offlineDir, '..', '.github', 'workflows', 'offline-till.yml'));
  assert.match(until, /windows-installer:/, 'the till workflow must have a job that runs the built installer on Windows');
  assert.match(until, /verify-windows\.ps1 -Installer \$installer -Install/, 'that job must run the verifier against the artifact this run built');
  assert.match(until, /needs: package/, 'the Windows job must check the artifact the build job produced, not rebuild it');
  assert.match(until, /ParseFile/, 'the verifier must be parsed by the Windows PowerShell that runs it');

  // A run that goes red has to be readable afterwards. The first run of this
  // job failed with nothing but "Process completed with exit code 1" in the
  // run's annotations - the log itself could not be downloaded at all - which
  // is a failure nobody can act on. So: the verifier reports a thrown error
  // like a failed check, every failed check becomes a workflow annotation, the
  // report is written to a file the job keeps, and a native command's stderr is
  // never merged into the pipeline under $ErrorActionPreference = 'Stop' (in
  // Windows PowerShell 5.1 that can end the script with no report at all).
  const verifierCode = codeOnly(verifier);
  assert.match(verifierCode, /^trap \{/m, 'the verifier must report even when something unforeseen throws');
  assert.match(verifierCode, /::\{0\} title=\{1\}::\{2\}/, 'a failed check must reach the run as an annotation');
  assert.match(verifierCode, /Write-Annotation -Title 'verify-windows' -Message \(\$check\.name/, 'every failed check must be annotated, not just the thrown ones');
  assert.ok(!/2>&1/.test(verifierCode), "a native command's stderr must not be merged into the pipeline: it can end the script with no report");
  // A red run whose annotations are empty has two possible meanings, and the
  // first person to read it cannot tell them apart: the verifier never started,
  // or it started and had nothing to say. One notice at the start settles it.
  assert.match(verifierCode, /Write-Annotation -Title 'verify-windows' -Message \$saying -Level 'notice'/, 'the verifier must say it started');
  // In PowerShell a function exists only once its definition has run, so a trap
  // that fires before its callee is defined fails on its own - quietly.
  assert.ok(verifierCode.indexOf('trap {') > verifierCode.indexOf('function Complete-Run {'), 'the trap must be defined after the function it calls');

  assert.match(until, /-Install -ReportPath \$report/, 'the CI job must keep the verification report as a file');
  // Windows PowerShell has one namespace for variables: while the parameter was
  // called -Report and the health report of the installed copy lived in
  // `$report`, assigning the second silently overwrote the first, and the file
  // the job asked for was never written. The names must not be able to meet.
  assert.match(verifier, /\[string\]\$ReportPath = ''/, 'the report parameter must not collide with the health report variable');
  assert.ok(!/\$report\s*=/.test(verifierCode), 'nothing may assign to $report: that is the report path parameter');
  assert.match(until, /name: windows-verification/, 'the report must be uploaded, failed or not');
  assert.match(until, /if: always\(\)/, 'the report must be kept even when the verification fails');

  const publish = read(path.join(offlineDir, '..', '.github', 'workflows', 'publish-release-assets.yml'));
  assert.match(publish, /needs: \[build, verify-windows\]/, 'nothing may be published before the built installer has run on Windows');
  assert.match(publish, /name: installer-dist/, 'the bytes that are verified must be the bytes that are published');

  // The release gate cannot fail quietly either. Its first run went red in two
  // seconds with nothing in the annotations but "Process completed with exit
  // code 1" - and the job's log could not be downloaded from where the failure
  // was being read, so there was nothing to act on at all.
  assert.match(publish, /-Install -ReportPath \$report/, 'the release gate must keep the verification report as a file');
  assert.match(publish, /::error title=verify-windows::/, 'the release gate must annotate whatever stops it before the verifier can');
  assert.match(publish, /Out-String/, "the release gate must keep the verifier's own output");
  assert.match(publish, /name: windows-verification/, 'the release gate must keep the report as an artifact, passed or failed');
  assert.ok(
    !/2>&1/.test(publish) || /\$ErrorActionPreference = 'Continue'/.test(publish),
    "a step that merges a native command's stderr must not run under 'Stop'"
  );
});

await step("the installer reads a program's exit code reliably", () => {
  // Windows PowerShell 5.1 does not fill in ExitCode on the process object that
  // Start-Process -PassThru returns: it comes back empty, every time, for a
  // program that has clearly exited. The installer read that as a failure and
  // refused a program that had just answered with its own status JSON - on a
  // Windows runner, on a machine where nothing was wrong - and the empty
  // "(exit )" in a shop's install.log came out of the same hole. Everything
  // that runs the till and needs the code uses the .NET process object, which
  // reports the code it was given.
  for (const file of ['install.ps1', 'verify.ps1']) {
    const text = codeOnly(read(path.join(payloadDir, file)));
    assert.match(text, /New-Object System\.Diagnostics\.Process/, `${file} must take the exit code from a .NET process object`);
    assert.ok(!/Start-Process/.test(text), `${file} must not run the till through Start-Process`);
  }

  // And the probe does not decide on the number alone: what proves the program
  // runs is the program answering. A blocked, quarantined or mis-built one
  // prints nothing, so it still fails there.
  const installer = codeOnly(read(path.join(payloadDir, 'install.ps1')));
  assert.match(installer, /\$exitCodeRead = \(\$null -ne \$probe\.ExitCode\)/, 'an unreadable exit code must be told apart from a failing one');
  assert.match(installer, /\$probe\.StdOut -match '"device_id"'/, 'a program that answers with its own status has to count as running');

  const verifier = codeOnly(read(path.join(offlineDir, 'installer', 'verify-windows.ps1')));
  assert.match(verifier, /New-Object System\.Diagnostics\.Process/, 'the Windows verifier needs a readable exit code too');

  // cmd.exe parses its own command line: quoting install.cmd's path a second
  // time (the verifier quotes every argument it is given) makes cmd look for a
  // file whose name has quote characters in it, and the install then fails with
  // "'\"C:\...\install.cmd\""' is not recognized as an internal or external
  // command" - which is exactly what one red run reported, and it said nothing
  // about the installer. The line cmd gets has to be built once, for cmd.
  // ...and a run that cannot even unpack has to say so through the same door as
  // every other failure: no exit path may end without a report, because a run
  // that ends without one is a run nobody can diagnose. Two red runs of this job
  // produced nothing but "Process completed with exit code 1".
  assert.match(verifier, /function Complete-Run/, 'every exit must go through one reporting path');
  assert.match(verifier, /'there is a program to run'[\s\S]{0,300}?Complete-Run/, 'a run that finds no program must still report');
  assert.equal((verifier.match(/^\s*exit 1\s*$/gm) || []).length, 1, 'the only exit 1 in the verifier is the one Complete-Run takes');

  assert.match(verifier, /-RawArguments/, 'the verifier must not let install.cmd go through the generic argument quoting');
  assert.match(verifier, /\/s \/c ""\{0\}" \{1\}"/, "cmd's own /s /c \"\"<path>\" <args>\" form is the one it parses correctly");
});

await step('the installer says why the program did not run', () => {
  // In the field the install stopped with "did not run on this PC (exit )." -
  // Windows reported no exit code for a program that aborted, and the message
  // left a shop with nothing to act on. The code is now named when it is
  // missing, the program's own words are written into the log line by line, and
  // an abort inside its own runtime is called what it is: a broken build.
  const text = codeOnly(read(path.join(payloadDir, 'install.ps1')));

  assert.match(text, /\$probeCode = 'unknown'/, 'a missing exit code must be named, not printed as an empty pair of brackets');
  assert.match(text, /program says: /, "the program's own output must reach the log");
  assert.match(text, /SeaDeserializer\|Assertion failed/, 'the message must recognise a runtime-plane abort and say it is a broken build');
  assert.match(text, /RUNTIME\.txt/, 'the log must record which runtime the payload carries');
});

await step('the payload check itself is wired into the build and the release', () => {
  const build = read(path.join(offlineDir, 'installer', 'build-windows.sh'));
  assert.match(build, /check-payload\.mjs"\s+"\$INSTALLER\/payload"/, 'the build must check the payload in version control before it builds anything');
  assert.match(build, /check-payload\.mjs"\s+"\$PAYLOAD"\s+--fix --bom/, 'the build must normalise the payload it packs');
  assert.match(build, /--require-crlf --require-bom/, 'the build must verify what it is about to pack');

  const scripts = JSON.parse(read(path.join(offlineDir, 'package.json'))).scripts;
  assert.ok(scripts['check:installer'], 'npm run check:installer must exist so the check can be run by hand');
});

console.log('\nOffline POS - the Windows installer\n');
console.log(results.join('\n'));
console.log(`\n${results.length - failures}/${results.length} checks passed`);

process.exit(failures ? 1 : 0);
