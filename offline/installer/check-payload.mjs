#!/usr/bin/env node
/**
 * Payload check - the gate between the installer scripts and a shop's PC.
 *
 *   node installer/check-payload.mjs [dir] [options]
 *
 * The Windows installer is a pile of .cmd and .ps1 files that cannot be run on
 * the build machine, so everything that breaks them has to be caught here
 * instead. This script is that check, and it exists because of a real failure:
 *
 *   A payload script was written in UTF-8 without a byte-order mark and
 *   contained an em dash. Windows PowerShell 5.1 reads a mark-less .ps1 with the
 *   machine's ANSI code page - cp1252 on a Western PC - where the em dash's
 *   three bytes decode to "a-circumflex, euro, U+201D". PowerShell accepts
 *   U+201D (RIGHT DOUBLE QUOTATION MARK) as a string delimiter, so a string
 *   closed in the middle of its own text, the rest of the file was parsed as
 *   code, and the install died three lines later with:
 *
 *     install.ps1:152 char:100
 *     Unexpected token ')' in expression or statement.
 *
 *   Nothing at the reported position was wrong. The cause was a character 300
 *   bytes earlier that no editor on the build machine would ever show.
 *
 * The rules enforced here:
 *
 *   1. every .ps1, .cmd and .txt in the payload (and the SFX configuration) is
 *      pure ASCII - the one encoding that cannot be misread by cmd.exe, by
 *      PowerShell 5.1 or by the 7-Zip stub, whatever code page the shop uses;
 *   2. read as UTF-8 *and* read as cp1252, every .ps1 still has balanced
 *      strings, here-strings and comments - so a script that parses one way
 *      cannot fail the other;
 *   3. no .ps1 uses syntax only PowerShell 7 has (the target is the Windows
 *      PowerShell 5.1 that every Windows 10/11 ships with);
 *   4. every goto/call in a .cmd has a label and the file starts with
 *      `@echo off` - including the .cmd files install.ps1 *generates*, which
 *      exist nowhere but on a shop's PC and fail silently when a jump is wrong;
 *   5. line endings and byte-order marks are what each host expects: CRLF, no
 *      mark on .cmd (cmd.exe prints it), a UTF-8 mark on the .ps1 copies that
 *      ship, so a later non-ASCII edit is read correctly instead of misparsed;
 *   6. when `pwsh` is on PATH, the real PowerShell parser confirms all of it,
 *      on both readings of every file.
 *
 * Options:
 *   --fix            rewrite the payload: CRLF, marks as required, and the
 *                    typographic characters behind this class of bug (em dash,
 *                    arrow, ellipsis, smart quotes, box drawing) spelled out in
 *                    ASCII. Anything else non-ASCII is reported, never guessed.
 *   --bom            with --fix: write .ps1 files as UTF-8 with a mark
 *   --require-crlf   fail on a file that is not CRLF (the built payload)
 *   --require-bom    fail on a .ps1 without a UTF-8 mark (the built payload)
 *   --no-pwsh        skip the real parser even when pwsh is installed
 *   --quiet          only report problems
 *
 * Exit code: 0 when the payload is shippable, 1 when it is not.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));

/* -- what is checked -------------------------------------------------------- */

/** Extensions a Windows host reads with the machine's own code page. */
const TEXT_EXTENSIONS = new Set(['.ps1', '.cmd', '.txt']);

const BOM = Buffer.from([0xef, 0xbb, 0xbf]);

/**
 * Characters that look like punctuation and parse like syntax. `--fix` spells
 * these out in ASCII; anything outside this table is reported and left alone,
 * because guessing at a language is how a shop's text gets mangled.
 */
const TRANSLITERATIONS = new Map([
  ['\u2014', '-'],    // em dash
  ['\u2013', '-'],    // en dash
  ['\u2011', '-'],    // non-breaking hyphen
  ['\u2026', '...'],  // ellipsis
  ['\u2192', '->'],   // rightwards arrow
  ['\u2190', '<-'],   // leftwards arrow
  ['\u2194', '<->'],  // left-right arrow
  ['\u21d2', '=>'],   // rightwards double arrow
  ['\u2018', "'"],    // left single quotation mark
  ['\u2019', "'"],    // right single quotation mark
  ['\u201c', '"'],    // left double quotation mark
  ['\u201d', '"'],    // right double quotation mark
  ['\u00a0', ' '],    // non-breaking space
  ['\u2500', '-'],    // box drawing, light horizontal
  ['\u2502', '|'],    // box drawing, light vertical
  ['\u250c', '+'], ['\u2510', '+'], ['\u2514', '+'], ['\u2518', '+'],
  ['\u251c', '+'], ['\u2524', '+'], ['\u252c', '+'], ['\u2534', '+'], ['\u253c', '+'],
  ['\u2713', 'ok'],   // check mark
  ['\u2717', 'x'],    // ballot x
  ['\u2022', '*'],    // bullet
  ['\u00b7', '*'],    // middle dot
  ['\u2265', '>='], ['\u2264', '<='], ['\u2260', '!='],
]);

/**
 * PowerShell accepts these as string delimiters, which is why they must never
 * reach a .ps1 - not directly, and not through a misread code page.
 */
const POWERSHELL_QUOTES = new Map([
  ['\u2018', "a single quote (')"],
  ['\u2019', "a single quote (')"],
  ['\u201c', 'a double quote (")'],
  ['\u201d', 'a double quote (")'],
]);

/** Syntax PowerShell 7 has and the 5.1 that ships with Windows does not. */
const POWERSHELL_7_ONLY = [
  [/\?\?/, 'the ?? operator (PowerShell 7 only; test for $null instead)'],
  [/\?\./, 'the ?. operator (PowerShell 7 only)'],
  [/&&|\|\|/, 'the && and || pipeline chain operators (PowerShell 7 only; use if, or a separate statement)'],
  [/\$Is(?:Windows|Linux|MacOS)\b/, '$IsWindows and friends (PowerShell 7 only; use [Environment]::OSVersion)'],
  [/-Parallel\b/, '-Parallel (PowerShell 7 only)'],
  [/\bJoin-Path\b[^\r\n]*-AdditionalChildPath/, 'Join-Path -AdditionalChildPath (PowerShell 7 only; nest Join-Path calls)'],
  [/\bTest-Connection\b[^\r\n]*-TcpPort/, 'Test-Connection -TcpPort (PowerShell 7 only; use a TcpClient or Invoke-WebRequest)'],
  [/\bGet-Error\b/, 'Get-Error (PowerShell 7 only)'],
];

/* -- reading the payload ---------------------------------------------------- */

function listPayloadFiles(payloadDir) {
  const files = [];

  const walk = (dir) => {
    const entries = fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name));
    for (const entry of entries) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) { walk(full); continue; }
      if (TEXT_EXTENSIONS.has(path.extname(entry.name).toLowerCase())) files.push(full);
    }
  };

  if (fs.existsSync(payloadDir)) walk(payloadDir);

  // The SFX configuration is concatenated into the installer verbatim and read
  // by the stub, so it follows the same rules as the payload itself.
  const sfxConfig = path.join(path.dirname(payloadDir), 'sfx-config.txt');
  if (fs.existsSync(sfxConfig) && !files.includes(sfxConfig)) files.push(sfxConfig);

  return files;
}

const hasMark = (buffer) => buffer.subarray(0, 3).equals(BOM);

/** The bytes as their author wrote them: UTF-8, mark stripped. */
function readAsWritten(buffer) {
  return new TextDecoder('utf-8').decode(hasMark(buffer) ? buffer.subarray(3) : buffer);
}

/**
 * The bytes as Windows PowerShell 5.1 reads them: a marked file is UTF-8, an
 * unmarked one is decoded with the machine's ANSI code page (cp1252 in the West,
 * which is where this project's tills are).
 */
function readAsWindowsPowerShell(buffer) {
  if (hasMark(buffer)) return readAsWritten(buffer);
  return new TextDecoder('windows-1252').decode(buffer);
}

/** 1-based line and column of a byte offset. */
function positionOf(buffer, offset) {
  let line = 1;
  let lineStart = 0;
  for (let i = 0; i < offset && i < buffer.length; i++) {
    if (buffer[i] === 0x0a) { line++; lineStart = i + 1; }
  }
  return { line, column: offset - lineStart + 1 };
}

function countOccurrences(haystack, needle) {
  let count = 0;
  let index = haystack.indexOf(needle);
  while (index !== -1) {
    count++;
    index = haystack.indexOf(needle, index + needle.length);
  }
  return count;
}

/* -- the PowerShell tokenizer, far enough to prove quoting ------------------ */

const SINGLE = new Set(["'", '\u2018', '\u2019']);
const DOUBLE = new Set(['"', '\u201c', '\u201d']);

/**
 * Walks a script the way PowerShell's tokenizer does about strings and comments
 * and reports where the quoting ends up. It is not a parser - it knows nothing
 * about expressions - but an unbalanced string is exactly what a misread code
 * page produces, and that it can see.
 *
 * @param {string} text
 * @returns {{state: string, opened: {line: number, column: number}|null, code: string}}
 */
function scanPowerShell(text) {
  let state = 'code';
  let opened = null;
  let line = 1;
  let column = 1;
  const codeChunks = [];
  let code = '';

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    const next = i + 1 < text.length ? text[i + 1] : '';
    const afterNext = i + 2 < text.length ? text[i + 2] : '\n';
    const lineEndsHere = afterNext === '\r' || afterNext === '\n';

    if (state === 'code') {
      if (ch === '<' && next === '#') { state = 'blockcomment'; codeChunks.push(code); code = ''; i++; column += 2; continue; }
      if (ch === '#') { state = 'linecomment'; codeChunks.push(code); code = ''; column++; continue; }
      if (ch === '@' && DOUBLE.has(next) && lineEndsHere) { state = 'heredq'; opened = { line, column }; i++; column += 2; continue; }
      if (ch === '@' && SINGLE.has(next) && lineEndsHere) { state = 'heresq'; opened = { line, column }; i++; column += 2; continue; }
      if (DOUBLE.has(ch)) { state = 'dq'; opened = { line, column }; column++; continue; }
      if (SINGLE.has(ch)) { state = 'sq'; opened = { line, column }; column++; continue; }
      code += ch;
    }
    else if (state === 'linecomment') {
      if (ch === '\n') { state = 'code'; code = ''; }
    }
    else if (state === 'blockcomment') {
      if (ch === '#' && next === '>') { state = 'code'; code = ''; i++; column += 2; continue; }
    }
    else if (state === 'dq') {
      if (ch === '`') { i++; column += 2; continue; }          // a backtick escapes the next character
      if (DOUBLE.has(ch)) { state = 'code'; opened = null; }
    }
    else if (state === 'sq') {
      if (SINGLE.has(ch)) { state = 'code'; opened = null; }
    }
    else if (state === 'heredq') {
      // A here-string ends only at "@ in the first column.
      if (column === 1 && ch === '"' && next === '@') { state = 'code'; opened = null; i++; column += 2; continue; }
    }
    else if (state === 'heresq') {
      if (column === 1 && ch === "'" && next === '@') { state = 'code'; opened = null; i++; column += 2; continue; }
    }

    if (ch === '\n') { line++; column = 1; }
    else { column++; }
  }

  codeChunks.push(code);
  return { state, opened, code: codeChunks.join('\n') };
}

/* -- the checks ------------------------------------------------------------- */

/** Splits a run of high bytes into UTF-8 characters, or reports it as raw bytes. */
function splitCharacters(run) {
  const characters = [];
  let index = 0;
  while (index < run.length) {
    // A UTF-8 lead byte says how long its character is; anything else here is not
    // UTF-8 and is reported as the bytes it is.
    const lead = run[index];
    const length = lead >= 0xf0 ? 4 : lead >= 0xe0 ? 3 : lead >= 0xc0 ? 2 : 1;
    const candidate = run.subarray(index, index + length);
    const text = candidate.toString('utf8');
    if (candidate.length === length && Buffer.from(text, 'utf8').equals(candidate)) {
      characters.push({ text, bytes: candidate });
    }
    else {
      characters.push({ text: null, bytes: run.subarray(index) });
      break;
    }
    index += length;
  }
  return characters;
}

function checkAscii(file, buffer, problems) {
  // One report per distinct character: where it first appears, how often, and
  // what a cp1252 host makes of it - which is the part that hurts.
  const seen = new Map();
  const order = [];

  // A leading byte-order mark is the one non-ASCII byte a .ps1 may carry: it is
  // what tells Windows PowerShell to read the file as UTF-8 at all.
  let offset = hasMark(buffer) ? 3 : 0;
  for (; offset < buffer.length; offset++) {
    if (buffer[offset] <= 0x7f) continue;

    const start = offset;
    while (offset + 1 < buffer.length && buffer[offset + 1] > 0x7f) offset++;
    const run = buffer.subarray(start, offset + 1);

    let cursor = start;
    for (const character of splitCharacters(run)) {
      const key = character.text ?? 'bytes:' + character.bytes.toString('hex');
      if (!seen.has(key)) {
        const misread = new TextDecoder('windows-1252').decode(character.bytes);
        seen.set(key, {
          text: character.text,
          bytes: [...character.bytes].map((b) => b.toString(16).padStart(2, '0')).join(' '),
          where: positionOf(buffer, cursor),
          misread,
          hazards: [...new Set([...misread].filter((c) => POWERSHELL_QUOTES.has(c)).map((c) => POWERSHELL_QUOTES.get(c)))],
          count: 0,
        });
        order.push(key);
      }
      seen.get(key).count++;
      cursor += character.bytes.length;
    }
  }

  for (const key of order) {
    const detail = seen.get(key);
    const name = detail.text
      ? (() => {
        const code = 'U+' + detail.text.codePointAt(0).toString(16).toUpperCase().padStart(4, '0');
        const spelling = TRANSLITERATIONS.get(detail.text);
        return `${detail.text} (${code}${spelling ? `, write "${spelling}"` : ''})`;
      })()
      : `bytes ${detail.bytes} (not UTF-8 at all)`;
    const times = detail.count > 1 ? `, ${detail.count} times` : '';
    const why = detail.hazards.length
      ? ` - read as cp1252 it becomes "${detail.misread}", and PowerShell takes ${detail.hazards.join(' and ')} for a string delimiter`
      : ` - read as cp1252 it becomes "${detail.misread}"`;
    problems.push(`${path.basename(file)}:${detail.where.line}:${detail.where.column}  not ASCII: ${name}${times}${why}`);
  }
}

function checkLineEndings(file, buffer, problems, options) {
  const crlf = countOccurrences(buffer, Buffer.from('\r\n'));
  const bareLf = countOccurrences(buffer, Buffer.from('\n')) - crlf;
  const bareCr = countOccurrences(buffer, Buffer.from('\r')) - crlf;

  if (options.requireCrlf && (bareLf > 0 || bareCr > 0)) {
    problems.push(`${path.basename(file)}  line endings: ${bareLf} bare LF and ${bareCr} bare CR among ${crlf} CRLF - cmd.exe mis-parses LF-only .cmd files`);
  }
}

function checkMarks(file, buffer, problems, options) {
  const extension = path.extname(file).toLowerCase();
  const marked = hasMark(buffer);

  if (marked && extension === '.cmd') {
    problems.push(`${path.basename(file)}  has a UTF-8 byte-order mark: cmd.exe shows it as a stray character before @echo off`);
  }
  if (options.requireBom && extension === '.ps1' && !marked) {
    problems.push(`${path.basename(file)}  a shipped .ps1 needs a UTF-8 byte-order mark: without one, Windows PowerShell 5.1 reads the file with the machine's ANSI code page`);
  }
}

function checkPowerShellSource(file, label, text, problems) {
  const scan = scanPowerShell(text);

  if (scan.state !== 'code') {
    const kinds = {
      dq: 'double-quoted string', sq: 'single-quoted string', heredq: 'here-string',
      heresq: 'here-string', blockcomment: 'block comment', linecomment: 'comment',
    };
    const where = scan.opened ? ` opened at line ${scan.opened.line}, column ${scan.opened.column}` : '';
    problems.push(`${path.basename(file)}  ${label}: a ${kinds[scan.state] ?? scan.state}${where} is never closed - the quoting does not balance`);
  }

  // Typographic quotes are legal PowerShell delimiters, which is exactly why they
  // must not appear: nothing in a diff shows them as syntax. Reported per
  // character rather than per line, because a ruled comment has dozens.
  const found = new Map();
  text.split(/\r?\n/).forEach((lineText, lineIndex) => {
    [...lineText].forEach((character, columnIndex) => {
      if (!POWERSHELL_QUOTES.has(character)) return;
      if (!found.has(character)) found.set(character, []);
      found.get(character).push(`${lineIndex + 1}:${columnIndex + 1}`);
    });
  });

  for (const [character, positions] of found) {
    const shown = positions.slice(0, 3).join(', ');
    const more = positions.length > 3 ? ` and ${positions.length - 3} more` : '';
    problems.push(`${path.basename(file)}:${positions[0]}  ${label}: U+${character.codePointAt(0).toString(16).toUpperCase()} is ${POWERSHELL_QUOTES.get(character)} to PowerShell, at ${shown}${more} - use the ASCII quote`);
  }

  // Only the code itself is searched, so prose in a comment cannot trip a rule.
  for (const [pattern, message] of POWERSHELL_7_ONLY) {
    if (pattern.test(scan.code)) problems.push(`${path.basename(file)}  ${label}: ${message}`);
  }
}

function checkBatchSource(file, buffer, problems, notes) {
  const text = readAsWritten(buffer);
  checkBatch(file, text, problems, notes);
}

/**
 * The label and goto checks, on the text of a .cmd.
 *
 * Also used for the launchers install.ps1 *generates*: they are .cmd files that
 * never exist on the build machine, so the only chance to check them is here.
 */
function checkBatch(name, text, problems, notes) {
  const lines = text.split(/\r?\n/);

  const firstCommand = lines.find((line) => line.trim() !== '' && !/^\s*rem\b/i.test(line));
  if (!firstCommand || !/^@echo off/i.test(firstCommand.trim())) {
    problems.push(`${name}  the first command should be "@echo off", found: ${JSON.stringify(firstCommand ?? '')}`);
  }

  const labels = new Set();
  const references = [];
  lines.forEach((lineText, index) => {
    if (/^\s*rem\b/i.test(lineText)) return;

    const label = /^\s*:([A-Za-z_][\w-]*)/.exec(lineText);
    if (label && !/^\s*::/.test(lineText)) labels.add(label[1].toLowerCase());

    // `goto name` and `goto :name` are the same jump; only `call :name` is a
    // subroutine, since `call program` runs a program.
    for (const target of lineText.matchAll(/\bgoto\s+:?([A-Za-z_][\w-]*)/gi)) {
      references.push({ name: target[1], line: index + 1 });
    }
    for (const target of lineText.matchAll(/\bcall\s+:([A-Za-z_][\w-]*)/gi)) {
      references.push({ name: target[1], line: index + 1 });
    }
  });

  for (const use of references) {
    if (use.name.toLowerCase() === 'eof') continue;                       // built in
    if (!labels.has(use.name.toLowerCase())) {
      problems.push(`${name}:${use.line}  jumps to :${use.name}, which has no label`);
    }
  }

  for (const label of labels) {
    if (!references.some((use) => use.name.toLowerCase() === label)) {
      notes.push(`${name}  label :${label} is never used`);
    }
  }
}

/**
 * The .cmd files install.ps1 writes at install time, checked as .cmd files.
 *
 * The launchers are arrays of single-quoted PowerShell strings, one per line of
 * the generated batch file. Reading them back here is the only way anything ever
 * checks them: they exist for a few seconds on a shop's PC, and a `goto` with no
 * label is silent until the till fails to open.
 */
function checkGeneratedBatch(file, text, problems, notes) {
  const blocks = text.matchAll(/\$(\w*Lines)\s*=\s*@\(([\s\S]*?)\r?\n\)/g);

  for (const block of blocks) {
    const [, variable, body] = block;
    const lines = [];
    for (const line of body.split(/\r?\n/)) {
      // One PowerShell string per generated line; '' inside it is a literal '.
      const match = /^\s*[\(]?\s*'((?:[^']|'')*)'/.exec(line);
      if (!match) continue;
      lines.push(match[1].replace(/''/g, "'"));
    }
    if (lines.length === 0) continue;

    const target = /launcher/i.test(variable) ? 'SoftCora POS.cmd' : 'start-till.cmd';
    checkBatch(`${path.basename(file)} (the ${target} it generates)`, lines.join('\r\n'), problems, notes);
  }
}

/** The real PowerShell parser, on both readings of every .ps1, when pwsh exists. */
function checkWithPwsh(files, problems, notes) {
  let pwsh = null;
  for (const candidate of ['pwsh', 'powershell']) {
    try {
      execFileSync(candidate, ['-NoProfile', '-NonInteractive', '-Command', 'exit 0'], { stdio: ['ignore', 'ignore', 'ignore'] });
      pwsh = candidate;
      break;
    }
    catch { /* not installed here; the tokenizer checks above stand on their own */ }
  }

  if (!pwsh) {
    notes.push('pwsh is not installed here, so the real PowerShell parser did not run (the tokenizer checks did)');
    return;
  }

  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'softcora-payload-'));
  const program = path.join(temporary, 'parse.ps1');

  // ParseFile is used deliberately: it applies the reading rules a host does, and
  // the marked temporary copies below hand it each of the two readings a Windows
  // host could take of the same bytes.
  fs.writeFileSync(program, [
    '$ErrorActionPreference = "Stop"',
    '$errors = $null',
    '$tokens = $null',
    '[void][System.Management.Automation.Language.Parser]::ParseFile($args[0], [ref]$tokens, [ref]$errors)',
    'foreach ($problem in $errors) {',
    '  [pscustomobject]@{ line = $problem.Extent.StartLineNumber; column = $problem.Extent.StartColumnNumber; message = $problem.Message } | ConvertTo-Json -Compress',
    '}',
    'exit 0',
    '',
  ].join('\n'));

  let index = 0;
  try {
    for (const file of files) {
      const buffer = fs.readFileSync(file);
      const readings = [['as written (UTF-8)', readAsWritten(buffer)]];

      // What Windows PowerShell 5.1 sees on a Western PC when there is no mark.
      const misread = readAsWindowsPowerShell(buffer);
      if (misread !== readings[0][1]) readings.push(['as PowerShell 5.1 reads it (cp1252)', misread]);

      for (const [label, text] of readings) {
        const candidate = path.join(temporary, `candidate-${index++}.ps1`);
        // The mark forces pwsh to read the candidate as UTF-8, so the characters
        // the Windows host would see are the characters the parser sees.
        fs.writeFileSync(candidate, Buffer.concat([BOM, Buffer.from(text, 'utf8')]));

        let output = '';
        try {
          output = execFileSync(pwsh, ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', program, candidate], {
            encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
          });
        }
        catch (error) {
          problems.push(`${path.basename(file)}  ${label}: the PowerShell parser did not run (${String(error.message).split('\n')[0]})`);
          continue;
        }

        for (const line of output.split(/\r?\n/)) {
          if (!line.trim()) continue;
          let parsed = null;
          try { parsed = JSON.parse(line); } catch { continue; }
          problems.push(`${path.basename(file)}:${parsed.line}:${parsed.column}  ${label}: the PowerShell parser reports ${parsed.message}`);
        }
      }
    }
    notes.push(`the PowerShell parser (${pwsh}) read every .ps1 in both readings`);
  }
  finally {
    fs.rmSync(temporary, { recursive: true, force: true });
  }
}

/* -- --fix ------------------------------------------------------------------ */

/** Rewrites one file: ASCII spelling, CRLF endings, byte-order mark as asked. */
function fixFile(file, options, fixes) {
  const buffer = fs.readFileSync(file);
  const extension = path.extname(file).toLowerCase();
  let text = readAsWritten(buffer);

  let spelled = 0;
  text = text.replace(/[^\x00-\x7F]/g, (character) => {
    const ascii = TRANSLITERATIONS.get(character);
    if (ascii === undefined) return character;      // reported by the check, never guessed
    spelled++;
    return ascii;
  });
  if (spelled > 0) fixes.push(`${path.basename(file)}  ${spelled} typographic character(s) spelled out in ASCII`);

  const crlf = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\n/g, '\r\n');
  if (crlf !== text) fixes.push(`${path.basename(file)}  line endings normalised to CRLF`);

  const wantMark = options.bom && extension === '.ps1';
  if (wantMark !== hasMark(buffer)) fixes.push(`${path.basename(file)}  byte-order mark ${wantMark ? 'added' : 'removed'}`);

  const output = Buffer.concat([wantMark ? BOM : Buffer.alloc(0), Buffer.from(crlf, 'utf8')]);
  if (!output.equals(buffer)) fs.writeFileSync(file, output);
}

/* -- entry point ------------------------------------------------------------ */

function main() {
  const argv = process.argv.slice(2);
  const options = {
    fix: argv.includes('--fix'),
    bom: argv.includes('--bom'),
    requireCrlf: argv.includes('--require-crlf'),
    requireBom: argv.includes('--require-bom'),
    quiet: argv.includes('--quiet'),
    pwsh: !argv.includes('--no-pwsh'),
  };

  const unknown = argv.filter((arg) => arg.startsWith('--') && ![
    '--fix', '--bom', '--require-crlf', '--require-bom', '--no-pwsh', '--quiet',
  ].includes(arg));
  if (unknown.length > 0) {
    console.error(`  ! unknown option(s): ${unknown.join(', ')}`);
    process.exit(1);
  }

  const positional = argv.filter((arg) => !arg.startsWith('--'));
  const payloadDir = path.resolve(positional[0] ?? path.join(scriptDir, 'payload'));

  if (!fs.existsSync(payloadDir)) {
    console.error(`  ! no such payload folder: ${payloadDir}`);
    process.exit(1);
  }

  const files = listPayloadFiles(payloadDir);
  if (files.length === 0) {
    console.error(`  ! nothing to check in ${payloadDir}`);
    process.exit(1);
  }

  const problems = [];
  const notes = [];
  const fixes = [];

  if (options.fix) {
    for (const file of files) fixFile(file, options, fixes);
  }

  const powershellFiles = [];
  for (const file of files) {
    const buffer = fs.readFileSync(file);
    const extension = path.extname(file).toLowerCase();

    checkAscii(file, buffer, problems);
    checkLineEndings(file, buffer, problems, options);
    checkMarks(file, buffer, problems, options);

    if (extension === '.ps1') {
      powershellFiles.push(file);
      const written = readAsWritten(buffer);
      // Both readings must balance: that is what catches a code-page misread even
      // when the ASCII rule has been bypassed somehow.
      checkPowerShellSource(file, 'as written', written, problems);
      checkPowerShellSource(file, 'as cp1252', readAsWindowsPowerShell(buffer), problems);
      checkGeneratedBatch(file, written, problems, notes);
    }
    if (extension === '.cmd') checkBatchSource(file, buffer, problems, notes);
  }

  if (options.pwsh && powershellFiles.length > 0) checkWithPwsh(powershellFiles, problems, notes);

  if (!options.quiet) {
    console.log(`  payload : ${path.relative(process.cwd(), payloadDir) || payloadDir}`);
    console.log(`  files   : ${files.map((file) => path.basename(file)).join(', ')}`);
    for (const fix of fixes) console.log(`  fixed   : ${fix}`);
    for (const note of notes) console.log(`  note    : ${note}`);
  }

  const unique = [...new Set(problems)];
  if (unique.length > 0) {
    console.log('');
    for (const problem of unique) console.log(`  ! ${problem}`);
    console.log('');
    console.log(`  ${unique.length} problem(s) in the installer payload: it would not run on a shop PC.`);
    console.log('  cmd.exe and Windows PowerShell 5.1 read these files with the machine\'s own');
    console.log('  code page, so the payload stays ASCII. Fix the lines above, or run this');
    console.log('  script with --fix to spell the typographic characters out.');
    process.exit(1);
  }

  if (!options.quiet) {
    console.log('  ok      : ASCII, quoting balanced in both readings, CRLF, no PowerShell 7-only syntax');
  }
}

main();
