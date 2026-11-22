// The nightly test run: every suite this Mac can run, one after another, each timed, a failing suite never stopping
// the rest. Failed tests are run once more to tell a flaky test from a real failure. Writes a short report and a JSON
// summary to ~/EchoLedgerTeam/reports/<date>.md and .json (outside git) and compares them with the previous run.
//   sh docs/qa/nightly.sh [--quick] [--only=policy,tokens,...] [--skip=cargo,...]
// docs/qa/NIGHTLY.md has what each suite is and how long it takes.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { homedir, loadavg } from 'node:os';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split('=')[1];
const quick = process.argv.includes('--quick');
const only = arg('only')?.split(',');
const skip = arg('skip')?.split(',') ?? [];
const pad = (n) => String(n).padStart(2, '0');
const now = new Date();
const date = process.env.NIGHTLY_DATE ?? `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
const reports = resolve(process.env.NIGHTLY_REPORTS ?? join(homedir(), 'EchoLedgerTeam', 'reports'));
const work = join(reports, `${date}-run`); // logs and JUnit files of this run
const cache = resolve(process.env.NIGHTLY_CACHE ?? join(homedir(), 'EchoLedgerTeam', 'cache'));
rmSync(work, { recursive: true, force: true });
mkdirSync(work, { recursive: true });

// ---------------------------------------------------------------------------------------------------------------
// Running commands

/** Runs a command, its output to a log file (and a short line per minute to the console). Never throws. */
function run(cmd, args, { cwd = root, env = {}, log, timeout = 3 * 3600 } = {}) {
  return new Promise((done) => {
    const t0 = Date.now();
    let out = '';
    let child;
    try {
      child = spawn(cmd, args, { cwd, env: { ...process.env, CI: '1', FORCE_COLOR: '0', NO_COLOR: '1', ...env } });
    } catch (e) {
      done({ code: 127, out: String(e), secs: 0 });
      return;
    }
    const timer = setTimeout(() => child.kill('SIGKILL'), timeout * 1000);
    const add = (d) => { out += d; };
    child.stdout.on('data', add);
    child.stderr.on('data', add);
    child.on('error', (e) => { out += `\n${e.message}\n`; });
    child.on('close', (code, signal) => {
      clearTimeout(timer);
      // eslint-disable-next-line no-control-regex
      out = out.replace(/\x1b\[[0-9;]*[A-Za-z]/g, '');
      if (log) writeFileSync(join(work, log), out);
      done({ code: code ?? (signal ? 137 : 1), out, secs: Math.round((Date.now() - t0) / 1000), timedOut: signal === 'SIGKILL' });
    });
  });
}

const which = async (cmd) => (await run('sh', ['-c', `command -v ${cmd}`])).code === 0;

// ---------------------------------------------------------------------------------------------------------------
// Reading results

const unescape = (s) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&#39;/g, "'").replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16))).replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d))).replace(/&amp;/g, '&');
const attr = (tag, name) => { const m = tag.match(new RegExp(`\\s${name}="([^"]*)"`)); return m ? unescape(m[1]) : undefined; };
/** The first line that says what went wrong (not a blank line or a bare "AssertionError"/stack line). */
function firstLine(text) {
  const lines = unescape(text ?? '').split('\n').map((l) => l.trim()).filter(Boolean);
  const good = lines.find((l) => !/^(at |\[|Caused by|Error$|AssertionError$|Failed asserting\.?$|stack trace|<!\[CDATA\[)/i.test(l) && l.length > 3);
  let line = (good ?? lines[0] ?? '').replace(/^<!\[CDATA\[/, '');
  // A bare exception name says little: add the first frame in our own code.
  if (/^[\w.$]+(Error|Exception)$/.test(line)) {
    const frame = lines.find((l) => /^at (com\.echoledger|Tests\\|.*\/(src|tests)\/)/.test(l));
    if (frame) line += ` ${frame}`;
  }
  return line.split(`${root}/`).join('').slice(0, 240);
}

/** Every test case in a JUnit XML file: { id, file, name, status: pass|fail|skip, error }. */
function junit(xml, fallbackFile = '') {
  const cases = [];
  const re = /<testcase\b([^>]*?)(\/>|>([\s\S]*?)<\/testcase>)/g;
  let m;
  while ((m = re.exec(xml))) {
    const head = m[1];
    const body = m[3] ?? '';
    const name = attr(head, 'name') ?? '?';
    const cls = attr(head, 'classname') ?? '';
    const file = attr(head, 'file') ?? fallbackFile;
    const fail = body.match(/<(failure|error)\b([^>]*?)(?:\/>|>([\s\S]*?)<\/\1>)/);
    const skipped = /<skipped\b/.test(body);
    const msg = fail ? (attr(`<x${fail[2]}>`, 'message') || '') + '\n' + (fail[3] ?? '') : '';
    cases.push({ id: `${cls ? `${cls} > ` : ''}${name}`, cls, file: file ? relative(root, resolve(root, file)) : '', name, status: fail ? 'fail' : skipped ? 'skip' : 'pass', error: fail ? firstLine(msg) : '' });
  }
  return cases;
}
const readJunit = (file, fallbackFile) => (existsSync(file) ? junit(readFileSync(file, 'utf8'), fallbackFile) : []);
const filesIn = (dir, ext) => (existsSync(dir) ? readdirSync(dir).filter((f) => f.endsWith(ext)).map((f) => join(dir, f)) : []);

function tally(cases) {
  const t = { tests: cases.length, passed: 0, failed: 0, skipped: 0 };
  for (const c of cases) t[c.status === 'pass' ? 'passed' : c.status === 'fail' ? 'failed' : 'skipped']++;
  return t;
}
const tail = (out, n = 12) => out.trim().split('\n').slice(-n).join('\n');

// ---------------------------------------------------------------------------------------------------------------
// The suites. Each returns { status: pass|fail|skipped, reason?, cases?, tests?, failed?, flaky?, problems?, note? }.

/** node --test files with a JUnit copy of the results; failures are run again once, file by file. */
async function nodeTest(id, cwd, files) {
  const xml = join(work, `${id}.xml`);
  const r = await run('node', ['--test', '--test-reporter=spec', '--test-reporter-destination=stdout', '--test-reporter=junit', `--test-reporter-destination=${xml}`, ...files], { cwd, log: `${id}.log` });
  let cases = readJunit(xml);
  if (!cases.length) return { status: 'fail', reason: `no results (exit ${r.code})`, error: tail(r.out, 3) };
  const failed = cases.filter((c) => c.status === 'fail');
  if (failed.length) {
    const again = join(work, `${id}-again.xml`);
    await run('node', ['--test', '--test-reporter=junit', `--test-reporter-destination=${again}`, ...files], { cwd, log: `${id}-again.log` });
    cases = merge(cases, readJunit(again));
  }
  return result(cases, r);
}

/** First run's cases with the re-run's outcome folded in: a test that failed, then passed, is flaky. */
function merge(first, again) {
  const second = new Map(again.map((c) => [c.id, c]));
  return first.map((c) => {
    if (c.status !== 'fail') return c;
    const b = second.get(c.id);
    return b && b.status === 'pass' ? { ...c, status: 'pass', flaky: true } : { ...c, rerun: b ? b.status : 'not run again' };
  });
}

function result(cases, r, extra = {}) {
  const t = tally(cases);
  const flaky = cases.filter((c) => c.flaky);
  const failures = cases.filter((c) => c.status === 'fail');
  const status = failures.length || (r && r.code !== 0 && !flaky.length && !extra.ignoreExit) ? 'fail' : 'pass';
  return { status, ...t, failures, flaky, ...(status === 'fail' && !failures.length ? { reason: `exit ${r.code}`, error: tail(r.out, 3) } : {}), ...extra };
}

const suites = [
  {
    id: 'policy', name: 'Repo policy', quick: true,
    run: () => nodeTest('policy', root, ['tools/repo-policy.test.mjs']),
  },
  {
    id: 'tokens', name: 'Design tokens', quick: true,
    async run() {
      const dir = join(root, 'tokens');
      if (!existsSync(join(dir, 'node_modules'))) await run('npm', ['ci', '--no-audit', '--no-fund'], { cwd: dir, log: 'tokens-install.log' });
      return nodeTest('tokens', dir, readdirSync(join(dir, 'test')).filter((f) => f.endsWith('.test.mjs')).map((f) => `test/${f}`));
    },
  },
  {
    id: 'vitest', name: 'Desktop unit (vitest)', quick: true,
    async run() {
      const dir = join(root, 'desktop');
      if (!existsSync(join(dir, 'node_modules'))) {
        const i = await run('npm', ['ci', '--no-audit', '--no-fund'], { cwd: dir, log: 'desktop-install.log' });
        if (i.code) return { status: 'skipped', reason: `npm ci failed: ${tail(i.out, 1)}` };
      }
      const xml = join(work, 'vitest.xml');
      const r = await run('npx', ['vitest', 'run', '--reporter=default', '--reporter=junit', `--outputFile.junit=${xml}`], { cwd: dir, log: 'vitest.log' });
      let cases = readJunit(xml);
      if (!cases.length) return { status: 'fail', reason: `no results (exit ${r.code})`, error: tail(r.out, 3) };
      // Vitest's JUnit names the file in classname.
      cases = cases.map((c) => ({ ...c, file: c.file || `desktop/${c.cls}` }));
      const files = [...new Set(cases.filter((c) => c.status === 'fail').map((c) => c.cls))];
      if (files.length) {
        const again = join(work, 'vitest-again.xml');
        await run('npx', ['vitest', 'run', ...files, '--reporter=junit', `--outputFile.junit=${again}`], { cwd: dir, log: 'vitest-again.log' });
        cases = merge(cases, readJunit(again));
      }
      return result(cases, r);
    },
  },
  {
    id: 'tsc', name: 'Desktop types (tsc)', quick: true,
    async run() {
      const r = await run('npx', ['tsc', '--noEmit', '-p', '.'], { cwd: join(root, 'desktop'), log: 'tsc.log' });
      const errors = r.out.split('\n').filter((l) => /error TS\d+/.test(l));
      return { status: r.code === 0 ? 'pass' : 'fail', tests: null, failed: errors.length, failures: errors.slice(0, 20).map((l) => ({ id: l.split(':')[0], file: l.split('(')[0], name: 'type check', error: l.replace(/^.*error /, 'error ') })), flaky: [], ...(r.code && !errors.length ? { reason: `exit ${r.code}`, error: tail(r.out, 3) } : {}) };
    },
  },
  {
    id: 'cargo', name: 'Desktop core (cargo test)', quick: false,
    async run() {
      if (!(await which('cargo'))) return { status: 'skipped', reason: 'cargo not found (rustup not installed)' };
      const dir = join(root, 'desktop', 'src-tauri');
      // A target folder kept between nights, so only what changed is rebuilt.
      const env = { CARGO_TARGET_DIR: process.env.CARGO_TARGET_DIR ?? join(cache, 'cargo-target') };
      const r = await run('cargo', ['test', '--no-fail-fast'], { cwd: dir, env, log: 'cargo.log' });
      let cases = cargoCases(r.out);
      if (!cases.length) return { status: 'fail', reason: `did not build (exit ${r.code})`, error: firstLine(r.out.split('\n').filter((l) => /^error/.test(l)).join('\n')) || tail(r.out, 3) };
      const failed = cases.filter((c) => c.status === 'fail').slice(0, 20);
      for (const c of failed) {
        const target = { lib: ['--lib'], bin: ['--bins'], doc: ['--doc'] }[c.cls] ?? ['--test', c.cls];
        const again = await run('cargo', ['test', '--no-fail-fast', ...target, '--', '--exact', c.name], { cwd: dir, env, log: `cargo-again-${failed.indexOf(c)}.log` });
        const b = cargoCases(again.out).find((x) => x.name === c.name);
        if (b && b.status === 'pass') Object.assign(c, { status: 'pass', flaky: true });
        else c.rerun = b ? b.status : 'not run again';
      }
      return result(cases, r);
    },
  },
  {
    id: 'widths', name: 'Desktop widths (widthcheck)', quick: false,
    async run() {
      const script = join(root, 'desktop', 'scripts', 'widthcheck.mjs');
      if (!existsSync(script)) return { status: 'skipped', reason: 'desktop/scripts/widthcheck.mjs is not on this branch yet' };
      const out = join(reports, `${date}-desktop-widths`);
      rmSync(out, { recursive: true, force: true });
      const r = await run('node', ['scripts/widthcheck.mjs', `--out=${out}`], { cwd: join(root, 'desktop'), log: 'widthcheck.log' });
      const rows = existsSync(join(out, 'report.json')) ? JSON.parse(readFileSync(join(out, 'report.json'), 'utf8')) : [];
      if (!rows.length) return { status: 'fail', reason: `no report (exit ${r.code}; the self-test may have failed)`, error: tail(r.out, 3) };
      const bad = rows.filter((x) => x.problems.length || x.errors?.length);
      const problems = bad.flatMap((x) => [...x.problems, ...(x.errors ?? []).map((e) => `page error: ${e}`)].map((p) => ({ picture: x.file, screen: x.screen, where: `${x.width} ${x.theme} ${x.lang}`, problem: p })));
      return { status: bad.length || r.code ? 'fail' : 'pass', tests: rows.length, failed: bad.length, failures: [], flaky: [], problems, pictures: out };
    },
  },
  {
    id: 'scroll', name: 'Desktop scrolling (scrollcheck)', quick: false,
    async run() {
      const out = join(reports, `${date}-desktop-scroll`);
      rmSync(out, { recursive: true, force: true });
      const r = await run('node', ['scripts/scrollcheck.mjs', `--out=${out}`], { cwd: join(root, 'desktop'), log: 'scrollcheck.log' });
      const rows = existsSync(join(out, 'report.json')) ? JSON.parse(readFileSync(join(out, 'report.json'), 'utf8')) : [];
      if (!rows.length) return { status: 'fail', reason: `no report (exit ${r.code})`, error: tail(r.out, 3) };
      const problems = [];
      for (const x of rows) {
        const where = `${x.engine} ${x.design} ${x.size}`;
        if (x.firstPaint === null) problems.push({ picture: x.file, screen: x.screen, where, problem: 'first paint: nothing drawn within 4 s' });
        if (x.wheelScrolled === false) problems.push({ picture: x.file, screen: x.screen, where, problem: `wheel: the mouse wheel at 60% of the window scrolls nothing (scrollers: ${x.scrollers.join(', ') || 'none'})` });
        for (const e of x.errors) problems.push({ picture: x.file, screen: x.screen, where, problem: `page error: ${e}` });
      }
      const bad = new Set(problems.map((p) => p.picture));
      return { status: bad.size || r.code ? 'fail' : 'pass', tests: rows.length, failed: bad.size, failures: [], flaky: [], problems, pictures: out };
    },
  },
  {
    id: 'web', name: 'Web server and console (Pest)', quick: true,
    async run() {
      const dir = join(root, 'web');
      if (!(await which('php'))) return { status: 'skipped', reason: 'php not found' };
      if (!existsSync(join(dir, 'vendor'))) {
        const i = await run('composer', ['install', '--no-interaction'], { cwd: dir, log: 'web-install.log' });
        if (i.code) return { status: 'skipped', reason: `composer install failed: ${tail(i.out, 1)}` };
      }
      await run('php', ['artisan', 'config:clear'], { cwd: dir });
      const xml = join(work, 'web.xml');
      const r = await run('php', ['artisan', 'test', '--parallel', `--log-junit=${xml}`], { cwd: dir, log: 'web.log' });
      let cases = readJunit(xml).map(webFile);
      if (!cases.length) return { status: 'fail', reason: `no results (exit ${r.code})`, error: tail(r.out, 3) };
      const files = [...new Set(cases.filter((c) => c.status === 'fail' && c.file).map((c) => resolve(root, c.file)))];
      if (files.length) {
        const again = join(work, 'web-again.xml');
        await run('php', ['artisan', 'test', ...files, `--log-junit=${again}`], { cwd: dir, log: 'web-again.log' });
        cases = merge(cases, readJunit(again).map(webFile));
      }
      return result(cases, r);
    },
  },
  {
    id: 'webjs', name: 'Web JS rules (node --test)', quick: true,
    run: () => nodeTest('webjs', join(root, 'web'), readdirSync(join(root, 'web', 'tests', 'js')).filter((f) => f.endsWith('.test.mjs')).map((f) => `tests/js/${f}`)),
  },
  {
    id: 'phone', name: 'Phone unit + screens (Gradle, Roborazzi verify)', quick: false,
    async run() {
      const dir = join(root, 'mobile');
      const env = androidEnv();
      if (!env.JAVA_HOME) return { status: 'skipped', reason: 'no JDK 17 or newer found (set JAVA_HOME)' };
      if (!env.ANDROID_HOME) return { status: 'skipped', reason: 'no Android SDK found (set ANDROID_HOME)' };
      const results = join(dir, 'app', 'build', 'test-results');
      // Without the old results Gradle runs the tests again instead of calling them up to date.
      rmSync(results, { recursive: true, force: true });
      // verifyRoborazziFullDebug runs every unit test of the website build and fails a screen that no longer matches
      // docs/design/screens; the Play build runs its own few.
      const r = await run('sh', ['./gradlew', '--console=plain', '--continue', '--no-build-cache', 'verifyRoborazziFullDebug', 'testPlayDebugUnitTest'], { cwd: dir, env, log: 'phone.log' });
      const read = () => (existsSync(results) ? readdirSync(results) : []).flatMap((task) => filesIn(join(results, task), '.xml').flatMap((f) => readJunit(f).map((c) => ({ ...c, id: `${task}: ${c.id}`, task, file: `mobile/app/src/test/java/${c.cls.replace(/\./g, '/')}.kt` }))));
      let cases = read();
      if (!cases.length) return { status: 'fail', reason: `did not build (exit ${r.code})`, error: firstLine(r.out.split('\n').filter((l) => /^e: |FAILURE|What went wrong/.test(l)).join('\n')) || tail(r.out, 3) };
      const failed = cases.filter((c) => c.status === 'fail');
      const classes = [...new Set(failed.map((c) => c.cls))].slice(0, 15);
      if (classes.length) {
        const keep = join(work, 'phone-first-results');
        await run('cp', ['-R', results, keep]);
        rmSync(results, { recursive: true, force: true });
        const tasks = [...new Set(failed.map((c) => c.task))];
        const filter = classes.flatMap((c) => ['--tests', c]);
        const args = tasks.flatMap((t) => [t, ...filter]);
        await run('sh', ['./gradlew', '--console=plain', '--continue', '--no-build-cache', '-Proborazzi.test.verify=true', ...args], { cwd: dir, env, log: 'phone-again.log' });
        cases = merge(cases, read());
      }
      const shots = cases.filter((c) => c.status === 'fail' && /roborazzi|screenshot|compare/i.test(c.error)).length;
      return result(cases, r, { note: shots ? `${shots} of the failures are screens that no longer match their saved picture (docs/design/screens, docs/v2)` : undefined, matrix: phoneMatrix() });
    },
  },
  {
    id: 'webshots', name: 'Web screenshots 390/1440 light/dark', quick: false,
    async run() {
      return { status: 'skipped', reason: 'not automated yet: needs web/node_modules + a vite build, a seeded local SQLite database, php artisan serve and Playwright; admin pages also need a local TOTP secret' };
    },
  },
];

/** Pest names the file relative to web/, with the test's name after "::". */
const webFile = (c) => ({ ...c, file: c.file ? `web/${c.file.replace(/::.*$/, '')}` : '' });

/** "test-file::path::name ... ok/FAILED/ignored" lines and each failure's first panic line. */
function cargoCases(out) {
  const cases = [];
  let binary = '';
  const errors = new Map();
  const lines = out.split('\n');
  for (let i = 0; i < lines.length; i++) {
    const l = lines[i];
    const b = l.match(/Running (?:unittests )?(\S+)/);
    if (b) binary = b[1] === 'src/lib.rs' ? 'lib' : b[1].startsWith('src/') ? 'bin' : b[1].replace(/^tests\//, '').replace(/\.rs$/, '');
    if (/^\s*Doc-tests /.test(l)) binary = 'doc';
    const m = l.match(/^test (\S+) \.\.\. (ok|FAILED|ignored)/);
    if (m) cases.push({ id: `${binary}::${m[1]}`, cls: binary, name: m[1], file: ['lib', 'bin', 'doc'].includes(binary) ? 'desktop/src-tauri/src' : `desktop/src-tauri/tests/${binary}.rs`, status: m[2] === 'ok' ? 'pass' : m[2] === 'FAILED' ? 'fail' : 'skip', error: '' });
    const f = l.match(/^---- (\S+) stdout ----/);
    if (f) {
      const body = [];
      for (let j = i + 1; j < lines.length && !/^---- |^failures:$/.test(lines[j]); j++) body.push(lines[j]);
      const panic = body.findIndex((x) => /panicked at/.test(x));
      errors.set(f[1], firstLine(panic >= 0 ? body.slice(panic + 1).join('\n') || body[panic] : body.join('\n')));
    }
  }
  for (const c of cases) if (c.status === 'fail') c.error = errors.get(c.name) ?? '';
  return cases;
}

function androidEnv() {
  const env = {};
  const javaOk = (home) => home && existsSync(join(home, 'bin', 'java'));
  const candidates = [process.env.JAVA_HOME, '/opt/homebrew/opt/openjdk@17', '/opt/homebrew/opt/openjdk@21', '/Applications/Android Studio.app/Contents/jbr/Contents/Home', '/opt/homebrew/opt/openjdk'];
  env.JAVA_HOME = candidates.find(javaOk);
  const sdk = [process.env.ANDROID_HOME, process.env.ANDROID_SDK_ROOT, join(homedir(), 'Library', 'Android', 'sdk')].find((d) => d && existsSync(join(d, 'platforms')));
  if (sdk) env.ANDROID_HOME = sdk;
  return Object.fromEntries(Object.entries(env).filter(([, v]) => v));
}

/** What the phone's screen tests already cover (text sizes, Urdu, split screen), read from the test sources. */
function phoneMatrix() {
  const dir = join(root, 'mobile', 'app', 'src', 'test');
  const all = [];
  const walk = (d) => readdirSync(d).forEach((f) => { const p = join(d, f); if (statSync(p).isDirectory()) walk(p); else if (f.endsWith('.kt')) all.push(readFileSync(p, 'utf8')); });
  if (existsSync(dir)) walk(dir);
  const count = (re) => all.filter((s) => re.test(s)).length;
  return {
    'large text (fontScale > 1)': count(/fontScale\s*=\s*(1\.[1-9]|2)/),
    'small text (fontScale < 1)': count(/fontScale\s*=\s*0\.\d/),
    'Urdu (ur qualifiers)': count(/qualifiers\s*=\s*"[^"]*\bur\b/),
    'split screen / multi-window': count(/multiWindow|isInMultiWindowMode|split screen|w\d+dp-h[34]\d\ddp/i),
    'dark (night)': count(/qualifiers\s*=\s*"[^"]*\bnight\b/),
  };
}

// ---------------------------------------------------------------------------------------------------------------
// The run

const git = async (...a) => (await run('git', a)).out.trim();
const sha = await git('rev-parse', '--short=10', 'HEAD');
const branch = await git('rev-parse', '--abbrev-ref', 'HEAD');
const started = new Date();
const load = loadavg()[0].toFixed(0);
const rows = [];
for (const s of suites) {
  let res;
  if (only && !only.includes(s.id)) continue;
  if (skip.includes(s.id)) res = { status: 'skipped', reason: '--skip' };
  else if (quick && !s.quick) res = { status: 'skipped', reason: '--quick' };
  else {
    process.stdout.write(`${s.name} ... `);
    const t0 = Date.now();
    try {
      res = await s.run();
    } catch (e) {
      res = { status: 'fail', reason: 'the runner itself failed', error: String(e.stack ?? e).split('\n')[0] };
    }
    res.secs = Math.round((Date.now() - t0) / 1000);
    console.log(`${res.status}${res.failed ? ` (${res.failed} failed)` : ''}${res.reason ? ` (${res.reason})` : ''} in ${res.secs} s`);
  }
  rows.push({ id: s.id, name: s.name, failures: [], flaky: [], ...res });
}
const finished = new Date();

// ---------------------------------------------------------------------------------------------------------------
// The report

const previous = (() => {
  const older = readdirSync(reports).filter((f) => /^\d{4}-\d{2}-\d{2}\.json$/.test(f) && f < `${date}.json`).sort();
  if (!older.length) return null;
  try { return { file: older.at(-1), data: JSON.parse(readFileSync(join(reports, older.at(-1)), 'utf8')) }; } catch { return null; }
})();
const key = (suite, f) => `${suite}|${f.id ?? f.problem}`;
const before = new Set(previous ? previous.data.suites.flatMap((s) => [...(s.failures ?? []).map((f) => key(s.id, f)), ...(s.problems ?? []).map((p) => `${s.id}|${p.screen}|${p.problem.split(':')[0]}`)]) : []);
const beforeStatus = new Map(previous ? previous.data.suites.map((s) => [s.id, s.status]) : []);

const overall = rows.some((r) => r.status === 'fail') ? 'FAIL' : 'PASS';
const secs = Math.round((finished - started) / 1000);
const dur = (s) => (s == null ? '' : s >= 60 ? `${Math.floor(s / 60)} min ${pad(s % 60)} s` : `${s} s`);
const res = (r) => (r.status === 'pass' ? 'pass' : r.status === 'skipped' ? `skipped: ${r.reason}` : `**fail ${r.failed ?? ''}**${r.reason ? ` (${r.reason})` : ''}`.replace('fail  ', 'fail '));
const md = [];
md.push(`# Nightly tests ${date}: ${overall}`, '');
md.push(`${date} ${pad(started.getHours())}:${pad(started.getMinutes())} to ${pad(finished.getHours())}:${pad(finished.getMinutes())} · ${branch} @ ${sha} · ${dur(secs)} · load ${load} at the start${quick ? ' · --quick' : ''}`, '');
md.push('| Suite | Result | Tests | Time |', '|---|---|---|---|');
for (const r of rows) md.push(`| ${r.name} | ${res(r)} | ${r.tests == null ? '' : `${r.passed != null ? `${r.passed} passed` : ''}${r.skipped ? `, ${r.skipped} skipped` : ''}${r.flaky?.length ? ` (${r.flaky.length} flaky)` : ''}${r.passed == null ? `${r.tests}` : ''}`} | ${dur(r.secs)} |`);
md.push('');

const real = rows.flatMap((r) => r.failures.map((f) => ({ suite: r, f })));
const newOnes = real.filter(({ suite, f }) => !before.has(key(suite.id, f)));
md.push(`## New failures${previous ? ` (since ${previous.file.replace('.json', '')})` : ''}`, '');
if (!real.length && !rows.some((r) => r.status === 'fail')) md.push('None.');
for (const { suite, f } of (previous ? newOnes : real).slice(0, 40)) md.push(`- ${suite.name}: \`${f.file}\` ${f.name}${f.error ? `\n  ${f.error}` : ''}${f.rerun ? ` (failed again when re-run)` : ''}`);
for (const r of rows.filter((x) => x.status === 'fail' && !x.failures.length && !x.problems)) md.push(`- ${r.name}: ${r.reason}${r.error ? `\n  ${firstLine(r.error)}` : ''}`);
if (previous) {
  const still = real.length - newOnes.length;
  if (still) md.push('', `Still failing from before: ${still} (in ${previous.file.replace('.json', '.md')}).`);
  const fixed = [...before].filter((k) => !real.some(({ suite, f }) => key(suite.id, f) === k) && beforeStatus.get(k.split('|')[0]) && rows.find((r) => r.id === k.split('|')[0] && r.status !== 'skipped'));
  if (fixed.length) md.push('', `Passing again since last run: ${fixed.length} (${fixed.slice(0, 8).map((k) => k.split('|').slice(1).join(' ')).join('; ')}).`);
}
for (const r of rows.filter((x) => x.note)) md.push('', `${r.name}: ${r.note}.`);
md.push('');

const flaky = rows.flatMap((r) => r.flaky.map((f) => ({ suite: r, f })));
md.push('## Flaky (failed, then passed when run again)', '');
md.push(flaky.length ? flaky.map(({ suite, f }) => `- ${suite.name}: \`${f.file}\` ${f.name}\n  first run: ${f.error}`).join('\n') : 'None.');
md.push('');

md.push('## Visual problems', '');
const visual = rows.filter((r) => r.problems);
if (!visual.length) md.push('No picture check ran.', '');
for (const r of visual) {
  md.push(`**${r.name}**: ${r.failed} of ${r.tests} pictures with a problem. Pictures: \`${r.pictures}\``, '');
  const groups = new Map();
  for (const p of r.problems) {
    const kind = p.problem.split(':')[0];
    const g = groups.get(`${p.screen}|${kind}`) ?? { screen: p.screen, kind, where: new Set(), example: p.problem, picture: p.picture };
    g.where.add(p.where);
    groups.set(`${p.screen}|${kind}`, g);
  }
  for (const g of [...groups.values()].sort((a, b) => b.where.size - a.where.size).slice(0, 30)) {
    const isNew = previous && !before.has(`${r.id}|${g.screen}|${g.kind}`);
    md.push(`- ${g.screen}: ${g.kind} (${g.where.size}×: ${[...g.where].slice(0, 4).join(', ')}${g.where.size > 4 ? ', …' : ''})${isNew ? ' **new**' : ''}\n  e.g. ${g.example.slice(0, 200)} (\`${g.picture}\`)`);
  }
  md.push('');
}
const phone = rows.find((r) => r.id === 'phone' && r.matrix);
if (phone) md.push('## Phone screen matrix (test files covering each)', '', Object.entries(phone.matrix).map(([k, v]) => `- ${k}: ${v}`).join('\n'), '');
md.push(`Logs and JUnit files: \`${work}\``, '');

mkdirSync(reports, { recursive: true });
writeFileSync(join(reports, `${date}.md`), md.join('\n'));
const summary = {
  date, overall, sha, branch, started: started.toISOString(), finished: finished.toISOString(), secs, quick, previous: previous?.file ?? null,
  suites: rows.map((r) => ({
    id: r.id, name: r.name, status: r.status, reason: r.reason, secs: r.secs, tests: r.tests, passed: r.passed, failed: r.failed, skipped: r.skipped,
    failures: r.failures.map(({ id, file, name, error, rerun }) => ({ id, file, name, error, rerun })),
    flaky: r.flaky.map(({ id, file, name, error }) => ({ id, file, name, error })),
    ...(r.problems ? { problems: r.problems, pictures: r.pictures } : {}), ...(r.matrix ? { matrix: r.matrix } : {}), ...(r.error ? { error: r.error } : {}),
  })),
};
writeFileSync(join(reports, `${date}.json`), `${JSON.stringify(summary, null, 1)}\n`);
console.log(`\n${overall} in ${dur(secs)}: ${join(reports, `${date}.md`)}`);
process.exit(overall === 'PASS' ? 0 : 1);
