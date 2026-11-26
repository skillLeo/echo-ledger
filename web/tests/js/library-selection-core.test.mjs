// The v2.0 Library selection's Mark / Unmark important rule (resources/js/library-selection-core.cjs), without a browser:
// node --test tests/js/library-selection-core.test.mjs (Pest runs it too: tests/Feature/V2/LibrarySelectionMoreTest.php).
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { importantShows } = require('../../resources/js/library-selection-core.cjs');
const offered = (flags) => ['important', 'unimportant'].filter((which) => importantShows(which, flags));

test('none important: Mark important only', () => {
    assert.deepEqual(offered(['0', '0']), ['important']);
});

test('all important: Unmark important only', () => {
    assert.deepEqual(offered(['1', '1', '1']), ['unimportant']);
});

test('mixed: both, as Pin and Unpin', () => {
    assert.deepEqual(offered(['1', '0']), ['important', 'unimportant']);
});

test('a row not on the page, or nothing known: both', () => {
    assert.deepEqual(offered(['1', null]), ['important', 'unimportant']);
    assert.deepEqual(offered([]), ['important', 'unimportant']);
});
