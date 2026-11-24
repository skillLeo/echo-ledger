// The web console's copy of the one action list (UX-21, UX-22). The desktop's
// desktop/src/v2/actions/actions.json is the source of truth: every menu's words, icons and keys come from it, and the
// phone checks its ids against it too. The server gets only web/, so the console carries a byte-identical copy at
// web/resources/js/actions.json, and this test fails the moment the two differ.
//   node --test tests/js/actions-mirror.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const SOURCE = 'desktop/src/v2/actions/actions.json';
const desktop = join(here, '..', '..', '..', SOURCE);
const web = join(here, '..', '..', 'resources', 'js', 'actions.json');

test('the console\'s actions.json is the desktop\'s, byte for byte (the desktop file is the source of truth)', () => {
  const source = readFileSync(desktop);
  const copy = readFileSync(web);
  assert.ok(source.equals(copy), `web/resources/js/actions.json differs from ${SOURCE}: copy ${SOURCE} over it (cp ${SOURCE} web/resources/js/actions.json); never edit the web copy.`);
});
