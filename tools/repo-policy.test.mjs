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

  test("another client's design reference, people's recordings and local plans are never tracked", () => {
    // docs/design/reference/ holds another client's design (patterns only); recordings used as fixtures are a person's
    // own; the PLAN_V1_x files are local working notes.
    const offenders = tracked.filter(
      (p) =>
        p.startsWith('docs/design/reference/') ||
        p.startsWith('fixtures-private/') ||
        /\.(opus|m4a|aac)$/i.test(p) ||
        /^docs\/PLAN_V1_\d+\.md$/.test(p),
    );
    assert.deepEqual(offenders, []);
  });

  test('tracked text files contain no private key blocks, device tokens or provider key values', () => {
    // A token or key value that is an obvious placeholder is not a secret, so tests may use one: a single repeated
    // character, or at most three straight runs such as 0123456789abcdefABCDEF. Random values break into many runs.
    // AWS's documentation signs its published examples with a key ending in EXAMPLE, and the S3 signer is tested on them.
    const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    const runs = (value) =>
      [...value.toLowerCase()].filter(
        (c, i, all) => i === 0 || alphabet.indexOf(c) !== (alphabet.indexOf(all[i - 1]) + 1) % alphabet.length,
      ).length;
    const placeholder = ([, value]) => value !== undefined && (new Set(value).size === 1 || runs(value) <= 3 || value.endsWith('EXAMPLE'));
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
    () => {
      const offenders = findInTracked(/\b[a-z0-9-]+\.skillleo\.[a-z]{2,}\b/i);
      assert.deepEqual(offenders, []);
    },
  );

  test(
    'database seeders do not ship a fixed administrator password',
    () => {
      const offenders = tracked
        .filter((p) => p.startsWith('web/database/seeders/'))
        .filter((p) => /env\(\s*'ADMIN_PASSWORD'\s*,\s*'[^']+'\s*\)/.test(read(p)));
      assert.deepEqual(offenders, []);
    },
  );

  test(
    'ignore rules cover environment files in every app',
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
    () => {
      const composer = JSON.parse(read('web/composer.json'));
      assert.equal(String(composer.license).toLowerCase(), 'proprietary');
    },
  );

  test(
    'composer.json names and describes this product, not the framework skeleton',
    () => {
      const composer = JSON.parse(read('web/composer.json'));
      assert.notEqual(composer.name, 'laravel/laravel');
      assert.doesNotMatch(String(composer.description), /skeleton application/i);
    },
  );

  test(
    'the PHP constraint in composer.json is satisfiable by the locked dependency set',
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

// Words rule (since v1.9, docs/research/screen-share-privacy.md): user-facing text says "private", "hidden from screen
// share" and "private notes", never words that sell hiding or deceiving. English, Urdu and Arabic forms.
const BANNED_WORDS = [
  /\bundetectable\b/i,
  /\bstealth(?:y|ily)?\b/i,
  /\binvisible\s+to\s+proctors?\b/i,
  /\bcheat(?:s|ed|ing|er|ers)?\b/i,
  /\bbypass(?:es|ed|ing)?\b/i,
  /دھوک[اہے]/u, // Urdu "dhoka" (deceit, cheating) in its usual endings
  /(?:^|[\s،؟.,!?"'(])(?:ال)?غش(?:$|[\s،؟.,!?"')])/u, // Arabic "ghish" (cheating)
  // Urdu: undetectable, "can't be caught", stealthily / secret mode, hidden from examiners or invigilators, bypass.
  /ناقابلِ?\s*شناخت/u,
  /پکڑ[ای]\s+نہیں\s+جا\s+سک/u,
  /چوری\s+چھپے|خفیہ\s+موڈ/u,
  /(?:نگرانوں|نگران|ممتحن|ممتحنوں)\s+سے\s+(?:پوشیدہ|چھپا|غائب)/u,
  /بائی\s*پاس/u,
  // Arabic: undetectable, stealth, invisible to proctors, bypass / circumvent.
  /غير\s+قابل\s+للكشف|لا\s+يمكن\s+(?:اكتشافه|كشفه)/u,
  /(?:وضع\s+)?التخفي|خلسة/u,
  /(?:غير\s+مرئي|مخفي)\s+(?:عن|لل|على)\s*(?:ال)?مراقب/u,
  /تجاوز\s+(?:الحماية|المراقبة|الرقابة)|التحايل/u,
  // Roman Urdu: dhoka (cheat), chupke se (stealthily), "pakra nahi jata" (can't be caught).
  /\bdho(?:k|kh)(?:a|ay|e|ey)\b/i,
  /\bchupke\s+se\b/i,
  /\bpakr[ai]\s+nahi(?:n)?\s+ja/i,
];

/** Which banned words `text` uses (the pattern sources); empty when it is clean. */
export function bannedWordsIn(text) {
  return BANNED_WORDS.filter((re) => re.test(text)).map((re) => re.source);
}

/**
 * The text of a source file that people read: whole files for strings, pages and guides; for code, only the string
 * literals that read as words (they contain a space), so identifiers, permission names and log keys never count.
 */
function userFacingText(path, text) {
  if (/\.(xml|md)$/.test(path)) return text;
  if (path.endsWith('.blade.php')) return text.replace(/\{\{--[\s\S]*?--\}\}/g, '');
  const literals = text.match(/"(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*'|`(?:[^`\\]|\\.)*`/g) ?? [];
  // JSX text between tags (most of the desktop UI) and one-word labels count too (release audit, 2026-11-16).
  const jsx = path.endsWith('.tsx') ? (text.match(/>[^<>{}]*[A-Za-z\u0600-\u06FF][^<>{}]*</g) ?? []) : [];
  return [...literals, ...jsx].join('\n');
}

/** For the planted tests only. */
export const userFacingTextForTest = (path, text) => userFacingText(path, text);

const USER_FACING = (p) =>
  !/(^|\/)(tests?|__tests__|fixtures?)\//.test(p) &&
  !/\.test\.(tsx?|mjs)$/.test(p) &&
  (/^mobile\/app\/src\/main\/(java\/.*\.kt|res\/.*strings.*\.xml)$/.test(p) ||
    /^desktop\/src\/.*\.(tsx?)$/.test(p) ||
    /^desktop\/src-tauri\/src\/.*\.rs$/.test(p) ||
    /^web\/(app\/.*\.php|resources\/views\/.*\.blade\.php|lang\/.*)$/.test(p) ||
    /^docs\/(USER_GUIDE\.md|store\/.*\.md)$/.test(p));

describe('words rule', () => {
  test('the check finds each banned word, in English, Urdu and Arabic, and passes clean text', () => {
    for (const planted of [
      'An undetectable overlay',
      'Stealth mode for interviews',
      'Invisible to proctors',
      'Cheat on your interview',
      'Bypass the screen share',
      'یہ دھوکہ ہے',
      'هذا غش واضح',
      'ناقابلِ شناخت ونڈو',
      'یہ پکڑا نہیں جا سکتا',
      'چوری چھپے مدد',
      'نگرانوں سے پوشیدہ',
      'اسکرین شیئر بائی پاس کریں',
      'نافذة غير قابل للكشف',
      'وضع التخفي للمقابلات',
      'غير مرئي للمراقبين',
      'تجاوز الحماية بسهولة',
      'Interview mein dhoka',
      'Chupke se jawab',
      'Yeh pakra nahi jata',
      '<p>Stealth</p>',
    ]) {
      assert.notDeepEqual(bannedWordsIn(planted), [], planted);
    }
    assert.deepEqual(bannedWordsIn('Your notes stay private when you share your screen.'), []);
    assert.deepEqual(bannedWordsIn('Hidden from screen share. Private notes.'), []);
    // Everyday words that look close must stay allowed: copy (نقل), hidden (مخفي) on its own, limit exceeded (تجاوز الحد).
    assert.deepEqual(bannedWordsIn('نقل کریں · مخفي عن مشاركة الشاشة · تجاوز الحد الشهري · Shared with you'), []);
    // JSX text and one-word labels are read.
    assert.match(userFacingTextForTest('a.tsx', '<Button>Bypass</Button>'), /Bypass/);
    assert.match(userFacingTextForTest('a.kt', 'Text("Stealth")'), /Stealth/);
  });

  test('no user-facing string uses a banned word', () => {
    const offenders = textFiles
      .filter(USER_FACING)
      .flatMap((p) => bannedWordsIn(userFacingText(p, read(p))).map((w) => `${p}: ${w}`));
    assert.deepEqual(offenders, []);
  });
});
