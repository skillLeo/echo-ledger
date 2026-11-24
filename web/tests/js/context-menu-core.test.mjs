// UX-22 on the web console: the right-click menu's contents and keys (resources/js/context-menu-core.cjs), without a
// browser, from the console's copy of the one action list.
//   node --test tests/js/context-menu-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/context-menu-core.cjs');
const here = dirname(fileURLToPath(import.meta.url));
const { actions } = JSON.parse(readFileSync(join(here, '..', '..', 'resources', 'js', 'actions.json'), 'utf8'));
const ALL = ['recording.open', 'recording.transcribe', 'recording.pin', 'recording.important', 'recording.unread', 'recording.labels',
    'recording.moveToProject', 'file.rename', 'recording.exportAs', 'recording.moveToTrash'];
const words = (items) => items.map((i) => (i.separator ? `| ${i.label}` : i.label));

test('one recording: only what fits and runs, in 3b\'s groups, in the file\'s words', () => {
    const items = core.menu(actions, core.RECORDING_ONE, {
        targets: ['a'], state: { pinned: true, important: false, unread: false },
        runnable: ALL.filter((id) => id !== 'recording.transcribe'),
        children: {
            'recording.labels': core.choices('recording.labels', [{ value: 'q4', label: 'Q4', checked: true }]),
            'recording.moveToProject': core.choices('recording.moveToProject', [{ value: 'none', label: 'No project', checked: true }]),
            'recording.exportAs': [],
        },
    });
    // Unpin while pinned; Mark as unread while read; Export with no formats goes; Transcribe can't run here.
    assert.deepEqual(words(items), ['Open', '| Unpin', 'Mark as important', 'Mark as unread', 'Labels', 'Move to project', '| Rename…', '| Move to trash']);
    assert.equal(items.find((i) => i.label === 'Mark as important').checked, false);
    assert.equal(items.find((i) => i.label === 'Move to trash').danger, true);
    assert.deepEqual(items.find((i) => i.label === 'Labels').items, [{ action: 'recording.labels', choice: 'q4', label: 'Q4', checked: true }]);
    // Every word is the action list's.
    for (const item of items) assert.ok(actions.some((a) => a.id === item.action && [a.label, a.state && a.state.on].includes(item.label)), item.label);
});

test('several: Rename and Open need one; the title says how many', () => {
    const items = core.menu(actions, core.RECORDING_MANY, { targets: ['a', 'b', 'c'], state: {}, runnable: ALL, children: { 'recording.exportAs': core.choices('recording.exportAs', [{ value: 'txt', label: 'Text (.txt)' }]) } });
    assert.deepEqual(words(items), ['Transcribe', '| Pin', 'Mark as important', 'Mark as unread', '| Export', '| Move to trash']);
    assert.equal(core.title(3, ':count recordings'), '3 recordings');
    assert.equal(core.title(1, ':count recordings'), null);
});

test('keys: arrows wrap, Home and End go to the ends; a menu never opens off the window', () => {
    assert.deepEqual([core.move(4, -1, 'ArrowDown'), core.move(4, 3, 'ArrowDown'), core.move(4, 0, 'ArrowUp'), core.move(4, 2, 'Home'), core.move(4, 1, 'End')], [0, 0, 3, 0, 3]);
    assert.deepEqual(core.place(1000, 700, 240, 300, 1280, 800), { x: 1000, y: 496 });
    assert.deepEqual(core.place(1200, 10, 240, 300, 1280, 800), { x: 1036, y: 10 });
});
