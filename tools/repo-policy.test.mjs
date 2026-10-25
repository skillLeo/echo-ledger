// Repository policy checks: commit identity, secrets, ignore rules, licensing, manifest metadata and documentation.
//
// Dependency-free; run from the repository root with:
//   node --test "tools/*.test.mjs"
//
// The checks read tracked files only (git ls-files), so local build output and other untracked files are ignored.
// A check that fails because of a known open defect is skipped with that defect's ID from PLAN_V1_1.md; the fix for
// the defect removes the skip.

import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { basename, dirname, isAbsolute, join, posix, resolve } from 'node:path';
import { describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const AUTHOR_NAME = 'skillLeo';
const AUTHOR_EMAIL = 'hassam.dev.571@gmail.com';

const PRIVATE_KEY_BLOCK = /-----BEGIN [A-Z ]*PRIVATE KEY-----/;

// UTF-8 text that was decoded as Latin-1/Windows-1252 and saved again shows up as these lead characters
// (for example an arrow turning into three unrelated symbols). Built from code points to keep this file ASCII.
const DOUBLE_ENCODED = new RegExp(
  [
    '\\u00c3[\\u0080-\\u00bf]', // accented Latin letters
    '\\u00e2\\u20ac', // punctuation such as dashes and quotes
    '\\u00e2\\u2020', // arrows
    '\\u00e2\\u2030', // comparison symbols
  ].join('|'),
);

function git(...args) {
  return execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
}

const tracked = git('ls-files', '-z')
  .split('\0')
  .filter((p) => p !== '' && existsSync(join(root, p)));

const isBinary = (p) => /\.(png|ico|icns|jar|webp|gif|jpe?g|woff2?|ttf)$/i.test(p);
const isLockfile = (p) => /(^|\/)(composer\.lock|package-lock\.json|Cargo\.lock)$/.test(p);
const textFiles = tracked.filter((p) => !isBinary(p));
const markdownFiles = tracked.filter((p) => p.endsWith('.md'));

const contents = new Map();
function read(path) {
  if (!contents.has(path)) {
    contents.set(path, readFileSync(join(root, path), 'utf8'));
  }
  return contents.get(path);
}

/**
 * Lines of every tracked text file (lockfiles excluded) with a match of `pattern` that `allow` does not accept,
 * as `path:line`.
 * @param {RegExp} pattern
 * @param {{ allow?: (match: RegExpMatchArray) => boolean }} [options]
 */
function findInTracked(pattern, { allow = () => false } = {}) {
  const everyMatch = new RegExp(pattern.source, `${pattern.flags.replace('g', '')}g`);
  const hits = [];
  for (const path of textFiles) {
    if (isLockfile(path)) continue;
    read(path)
      .split('\n')
      .forEach((line, index) => {
        if ([...line.matchAll(everyMatch)].some((match) => !allow(match))) hits.push(`${path}:${index + 1}`);
      });
  }
  return hits;
}

/**
 * The paths in `paths` that no .gitignore file of this repository ignores. Personal exclude files
 * (core.excludesFile, .git/info/exclude) are left out, so every clone gets the same answer.
 */
function notIgnoredByRepository(paths) {
  const result = spawnSync('git', ['check-ignore', '--verbose', '--non-matching', '-z', '--stdin'], {
    cwd: root,
    input: paths.map((p) => `${p}\0`).join(''),
    encoding: 'utf8',
  });
  assert.ok(result.status === 0 || result.status === 1, `git check-ignore failed: ${result.error ?? result.stderr}`);
  // With -z every path yields four fields: the file holding the deciding pattern, its line, the pattern, the path.
  const fields = result.stdout.split('\0');
  const ignored = new Set();
  for (let i = 0; i + 4 <= fields.length; i += 4) {
    const [source, , pattern, path] = fields.slice(i, i + 4);
    const repositoryRule = !isAbsolute(source) && basename(source) === '.gitignore';
    if (repositoryRule && !pattern.startsWith('!')) ignored.add(path);
  }
  return paths.filter((p) => !ignored.has(p));
}

describe('commit history', () => {
  test('every commit on every branch is authored and committed by the project identity', () => {
    const expected = `${AUTHOR_NAME} <${AUTHOR_EMAIL}>`;
    const offenders = git('log', '--all', '--format=%h|%an <%ae>|%cn <%ce>')
      .split('\n')
      .filter(Boolean)
      .filter((row) => {
        const [, author, committer] = row.split('|');
        return author !== expected || committer !== expected;
      });
    assert.deepEqual(offenders, []);
  });

  test('commit messages carry no co-author, sign-off or tool-attribution trailers', () => {
    // Attribution lines start a line ("Generated with ..."); the phrase inside an ordinary sentence is fine.
    const attributed = (message) =>
      /^(co-authored-by|signed-off-by):/im.test(message) || /^\W*generated (with|by)\b/im.test(message);
    const offenders = git('log', '--all', '--format=%h%x00%B%x01')
      .split('\x01')
      .map((entry) => entry.trim())
      .filter(Boolean)
      .filter(attributed)
      .map((entry) => entry.split('\0')[0]);
    assert.deepEqual(offenders, []);
  });
});

describe('secrets and local files', () => {
  test('no environment files, keystores or private key files are tracked', () => {
    // Certificates under a fixtures/ directory are test data; the root .gitignore allows them explicitly.
    const fixtureCertificate = (p) => /(^|\/)fixtures\/.*\.pem$/i.test(p) && !PRIVATE_KEY_BLOCK.test(read(p));
    const offenders = tracked.filter(
      (p) =>
        /(^|\/)\.env(\.(?!example$)[^/]*)?$/.test(p) ||
        (/\.(jks|keystore|p12|pfx|pem|key)$/i.test(p) && !fixtureCertificate(p)) ||
        /(^|\/)(keystore|local)\.properties$/.test(p),
    );
    assert.deepEqual(offenders, []);
  });

  test('tracked text files contain no private key blocks, device tokens or provider key values', () => {
    // A token or key value that is an obvious placeholder is not a secret, so tests may use one: a single repeated
    // character, or at most three straight runs such as 0123456789abcdefABCDEF. Random values break into many runs.
    const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    const runs = (value) =>
      [...value.toLowerCase()].filter(
        (c, i, all) => i === 0 || alphabet.indexOf(c) !== (alphabet.indexOf(all[i - 1]) + 1) % alphabet.length,
      ).length;
    const placeholder = ([, value]) => value !== undefined && (new Set(value).size === 1 || runs(value) <= 3);
    const patterns = [
      PRIVATE_KEY_BLOCK,
      /\beld_([A-Za-z0-9]{20,})/,
      /\bsk_([A-Za-z0-9]{24,})/,
      /xi-api-key["']?\s*[:=]\s*["']([A-Za-z0-9]{16,})/i,
      /\bAKIA([0-9A-Z]{16})\b/,
      /\bgh[pousr]_([A-Za-z0-9]{30,})/,
    ];
    const offenders = patterns.flatMap((pattern) => findInTracked(pattern, { allow: placeholder }));
    assert.deepEqual(offenders, []);
  });

  test(
    'tracked sources do not embed production hostnames',
    { skip: 'A6.R4: a mobile unit test still uses the production server host name' },
    () => {
      const offenders = findInTracked(/\b[a-z0-9-]+\.skillleo\.[a-z]{2,}\b/i);
      assert.deepEqual(offenders, []);
    },
  );

  test(
    'database seeders do not ship a fixed administrator password',
    { skip: 'A6.W11: the database seeder falls back to a published default administrator password' },
    () => {
      const offenders = tracked
        .filter((p) => p.startsWith('web/database/seeders/'))
        .filter((p) => /env\(\s*'ADMIN_PASSWORD'\s*,\s*'[^']+'\s*\)/.test(read(p)));
      assert.deepEqual(offenders, []);
    },
  );

  test(
    'ignore rules cover environment files in every app',
    { skip: 'A6.R2: .gitignore does not ignore .env files outside web/' },
    () => {
      const environmentFiles = ['.env', 'web/.env', 'web/.env.production', 'desktop/.env', 'mobile/.env'];
      assert.deepEqual(notIgnoredByRepository(environmentFiles), []);
    },
  );

  test('ignore rules cover signing material, dependencies and build output', () => {
    const generatedOrSecret = [
      'web/vendor/autoload.php',
      'web/node_modules/vite/package.json',
      'desktop/node_modules/vite/package.json',
      'desktop/dist/index.html',
      'desktop/src-tauri/target/release/echoledger-desktop.exe',
      'mobile/app/build/outputs/apk/release/app-release.apk',
      'mobile/keystore.properties',
      'mobile/echoledger-release.jks',
      'mobile/local.properties',
      'web/database/database.sqlite',
    ];
    assert.deepEqual(notIgnoredByRepository(generatedOrSecret), []);
  });
});

describe('licensing and manifest metadata', () => {
  test('the repository has a proprietary LICENSE that the README refers to', () => {
    assert.ok(tracked.includes('LICENSE'), 'LICENSE is missing');
    assert.match(read('LICENSE'), /proprietary/i);
    assert.match(read('README.md'), /license-proprietary/);
    assert.match(read('README.md'), /\]\(LICENSE\)/);
  });

  test(
    'composer.json declares the same licence as the LICENSE file',
    { skip: 'A6.W29: composer.json still declares the MIT licence of the framework skeleton' },
    () => {
      const composer = JSON.parse(read('web/composer.json'));
      assert.equal(String(composer.license).toLowerCase(), 'proprietary');
    },
  );

  test(
    'composer.json names and describes this product, not the framework skeleton',
    { skip: 'A6.W29: composer.json still carries the framework skeleton name and description' },
    () => {
      const composer = JSON.parse(read('web/composer.json'));
      assert.notEqual(composer.name, 'laravel/laravel');
      assert.doesNotMatch(String(composer.description), /skeleton application/i);
    },
  );

  test(
    'the PHP constraint in composer.json is satisfiable by the locked dependency set',
    { skip: 'A6.W29: composer.json allows PHP 8.3 although the locked packages need 8.4.1' },
    () => {
      const composer = JSON.parse(read('web/composer.json'));
      const lock = JSON.parse(read('web/composer.lock'));
      const lowerBound = (constraint) => {
        const match = /(\d+)\.(\d+)(?:\.(\d+))?/.exec(constraint);
        return match ? [Number(match[1]), Number(match[2]), Number(match[3] ?? 0)] : [0, 0, 0];
      };
      const compare = (a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2];
      const declared = lowerBound(composer.require.php);
      const required = [...lock.packages, ...lock['packages-dev']]
        .map((pkg) => pkg.require?.php)
        .filter(Boolean)
        .filter((constraint) => !constraint.includes('||') && !constraint.includes('|'))
        .map(lowerBound)
        .reduce((max, current) => (compare(current, max) > 0 ? current : max), [0, 0, 0]);
      assert.ok(
        compare(declared, required) >= 0,
        `composer.json allows PHP ${declared.join('.')} but the locked packages need ${required.join('.')} or newer`,
      );
    },
  );

  test(
    'the desktop app version is identical in tauri.conf.json, package.json and Cargo.toml',
    { skip: 'A6.DR16: tauri.conf.json says 0.1.1 while package.json and Cargo.toml say 0.1.0' },
    () => {
      const tauri = JSON.parse(read('desktop/src-tauri/tauri.conf.json')).version;
      const npm = JSON.parse(read('desktop/package.json')).version;
      const cargo = /^version\s*=\s*"([^"]+)"/m.exec(read('desktop/src-tauri/Cargo.toml'))?.[1];
      assert.deepEqual({ tauri, npm, cargo }, { tauri, npm: tauri, cargo: tauri });
    },
  );

  test('the product name is EchoLedger in every application manifest', () => {
    const names = {
      'desktop/src-tauri/tauri.conf.json': JSON.parse(read('desktop/src-tauri/tauri.conf.json')).productName,
      'desktop/index.html': /<title>([^<]*)<\/title>/.exec(read('desktop/index.html'))?.[1],
      'mobile/app/src/main/res/values/strings.xml': /<string name="app_name">([^<]*)<\/string>/.exec(
        read('mobile/app/src/main/res/values/strings.xml'),
      )?.[1],
    };
    const wrong = Object.entries(names).filter(([, name]) => name !== 'EchoLedger');
    assert.deepEqual(wrong, []);
  });

  test(
    'the server environment template sets the product name EchoLedger',
    { skip: 'A6.W29: web/.env.example still sets APP_NAME=Laravel' },
    () => {
      const name = /^APP_NAME="?([^"\r\n]*)"?\s*$/m.exec(read('web/.env.example'))?.[1];
      assert.equal(name, 'EchoLedger');
    },
  );
});

describe('documentation', () => {
  test('relative links in markdown files resolve to files in the repository', () => {
    const broken = [];
    for (const file of markdownFiles) {
      let inFence = false;
      read(file)
        .split('\n')
        .forEach((line, index) => {
          if (/^\s*```/.test(line)) inFence = !inFence;
          if (inFence) return;
          for (const match of line.matchAll(/\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g)) {
            const target = match[1].split('#')[0];
            if (target === '' || /^[a-z][a-z0-9+.-]*:/i.test(target)) continue;
            const resolved = posix.normalize(posix.join(posix.dirname(file), target));
            if (!existsSync(join(root, resolved))) broken.push(`${file}:${index + 1} -> ${match[1]}`);
          }
        });
    }
    assert.deepEqual(broken, []);
  });

  test(
    'documents only name repository files that exist',
    { skip: 'A6.R1: research notes cite planning files that are not in the repository' },
    () => {
      const trackedNames = new Set(tracked.map((p) => basename(p)));
      const missing = [];
      for (const file of markdownFiles) {
        read(file)
          .split('\n')
          .forEach((line, index) => {
            for (const match of line.matchAll(/`([A-Za-z0-9_.\-/]+\.md)`/g)) {
              const name = match[1];
              // Only names that look like this repository's own documents: a top-level directory prefix,
              // or a capitalised document name such as PLAN.md. Third-party documentation file names are ignored.
              const inRepoDirectory = /^(docs|web|desktop|mobile|tools)\//.test(name);
              const capitalisedName = /^[A-Z][A-Za-z0-9_]*([-_][A-Za-z0-9]+)*\.md$/.test(name);
              if (!inRepoDirectory && !capitalisedName) continue;
              const candidates = [name, posix.join(posix.dirname(file), name)];
              const exists = candidates.some((c) => tracked.includes(posix.normalize(c)));
              const bareNameExists = !name.includes('/') && trackedNames.has(name);
              if (!exists && !bareNameExists) missing.push(`${file}:${index + 1} -> ${name}`);
            }
          });
      }
      assert.deepEqual(missing, []);
    },
  );

  test(
    'two-factor documentation matches the admin panel configuration',
    { skip: 'A6.W12: docs and CLI messages still say two-factor sign-in is required' },
    () => {
      const panel = read('web/app/Providers/Filament/AdminPanelProvider.php');
      const required = /isRequired:\s*true/.test(panel);
      const claims = [
        /\b(?:requires?|required|mandatory|forced?|forces)\b[^|\n]{0,60}\b(?:TOTP|two-factor|2FA|MFA)\b/i,
        /\b(?:TOTP|two-factor|2FA|MFA)\b[^|\n]{0,60}\b(?:is|are)\s+(?:\*\*)?(?:required|mandatory|forced)/i,
        /\b(?:will be|is|are) (?:asked|prompted) to (?:set up|enrol)[^\n]{0,20}\b(?:2FA|MFA|TOTP)/i,
      ];
      const isClaim = (text) => !/optional/i.test(text) && claims.some((claim) => claim.test(text));
      const sources = tracked.filter(
        (p) => p.endsWith('.md') || p.startsWith('web/app/') || p.startsWith('web/resources/'),
      );
      const stale = [];
      for (const path of sources) {
        const lines = read(path).split('\n');
        lines.forEach((line, index) => {
          // A sentence can wrap onto the next line, so each line is also read together with the one after it.
          const next = lines[index + 1] ?? '';
          const wrapped = `${line.trimEnd()} ${next.trimStart()}`;
          if (isClaim(line) || (isClaim(wrapped) && !isClaim(next))) stale.push(`${path}:${index + 1}`);
        });
      }
      // With optional two-factor sign-in no document or message may promise that it is enforced.
      assert.ok(
        required || stale.length === 0,
        `two-factor sign-in is optional but described as required in:\n${stale.join('\n')}`,
      );
    },
  );

  test(
    'install steps that use npm ci have a committed package-lock.json',
    { skip: 'A6.W29: web/DEPLOY.md runs npm ci but web/ has no package-lock.json' },
    () => {
      const offenders = markdownFiles
        .filter((file) => /\bnpm ci\b/.test(read(file)))
        .filter((file) => {
          const dir = posix.dirname(file);
          const nearest = [dir, '.'].map((d) => posix.join(d, 'package-lock.json'));
          return !nearest.some((lock) => tracked.includes(posix.normalize(lock)));
        });
      assert.deepEqual(offenders, []);
    },
  );

  test('text files are valid UTF-8 without a byte order mark', () => {
    const strict = new TextDecoder('utf-8', { fatal: true });
    const offenders = [];
    for (const path of textFiles) {
      if (isLockfile(path)) continue;
      const bytes = readFileSync(join(root, path));
      if (bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
        offenders.push(`${path} (byte order mark)`);
        continue;
      }
      try {
        strict.decode(bytes);
      } catch {
        offenders.push(`${path} (invalid UTF-8)`);
      }
    }
    assert.deepEqual(offenders, []);
  });

  test(
    'text files contain no double-encoded characters',
    { skip: 'A6.W29: a comment in web/app/Services/ChunkedUploadService.php has double-encoded arrows' },
    () => {
      const offenders = textFiles.filter((p) => !isLockfile(p) && DOUBLE_ENCODED.test(read(p)));
      assert.deepEqual(offenders, []);
    },
  );

  test('tracked files use LF line endings and end with a newline', () => {
    const crlf = git('ls-files', '--eol')
      .split('\n')
      .filter((row) => /^i\/(crlf|mixed)\s/.test(row) && !/\.(bat|cmd|ps1)\s*$/.test(row));
    assert.deepEqual(crlf, []);
    const noFinalNewline = textFiles.filter(
      (p) => !isLockfile(p) && read(p).length > 0 && !read(p).endsWith('\n'),
    );
    assert.deepEqual(noFinalNewline, []);
  });

  test('no tracked file is larger than 1 MiB except dependency lockfiles', () => {
    const large = tracked.filter((p) => !isLockfile(p) && readFileSync(join(root, p)).length > 1024 * 1024);
    assert.deepEqual(large, []);
  });
});
