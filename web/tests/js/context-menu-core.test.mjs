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

// The menu for rows as the page draws them (data-* on .v2-lib-row) and the page's choices (ListCaptureSessions::menuData).
const DATA = {
    actions, runnable: ALL.filter((id) => id !== 'recording.unread').concat('label.new'),
    labels: [{ value: 'Q4', label: 'Q4' }, { value: 'Clients', label: 'Clients' }],
    projects: [{ value: 'c1', label: 'Acme' }],
    formats: [{ value: 'docx', label: 'Word (.docx)' }, { value: 'txt', label: 'Text (.txt)' }],
    words: { many: ':count recordings', noProject: 'No project' },
    say: { Pin: 'پن کریں', Unpin: 'پن ہٹائیں' },
};
const rowOf = (id, more = {}) => ({ id, pinned: false, important: false, state: 'ready', client: null, labels: [], ...more });

test('a row\'s menu from what it shows: Transcribe only while Audio only, its labels and project ticked, the words in the console\'s language', () => {
    const { items, title, targets } = core.recordingMenu(DATA, [rowOf('a', { pinned: true, state: 'audio_only', client: 'c1', labels: ['q4'] })]);
    assert.equal(title, null);
    assert.deepEqual(targets, ['a']);
    assert.deepEqual(words(items), ['Open', 'Transcribe', '| پن ہٹائیں', 'Mark as important', 'Labels', 'Move to project', '| Rename…', 'Export', '| Move to trash']);
    const sub = (label) => items.find((i) => i.label === label).items;
    assert.deepEqual(sub('Labels').map((i) => [i.label, i.checked, !! i.separator]), [['Q4', true, false], ['Clients', false, false], ['New label…', undefined, true]]);
    assert.equal(sub('Labels')[2].action, 'label.new');
    assert.deepEqual(sub('Move to project').map((i) => [i.choice, i.label, i.checked]), [['none', 'No project', false], ['c1', 'Acme', true]]);
    assert.deepEqual(sub('Export').map((i) => i.choice), ['docx', 'txt']);
    // A recording with its transcript: no Transcribe.
    assert.ok(! words(core.recordingMenu(DATA, [rowOf('b')]).items).includes('Transcribe'));
});

test('several rows: titled with the count; Unpin and a tick only when every one is so; Transcribe while any is Audio only', () => {
    const rows = [rowOf('a', { pinned: true, important: true, labels: ['q4'] }), rowOf('b', { pinned: false, important: true, state: 'audio_only', labels: ['q4', 'clients'] })];
    const { items, title, targets } = core.recordingMenu(DATA, rows);
    assert.equal(title, '2 recordings');
    assert.deepEqual(targets, ['a', 'b']);
    assert.deepEqual(words(items), ['Transcribe', '| پن کریں', 'Mark as important', 'Labels', 'Move to project', '| Export', '| Move to trash']);
    assert.equal(items.find((i) => i.label === 'Mark as important').checked, true);
    const labels = items.find((i) => i.label === 'Labels').items;
    assert.deepEqual(labels.slice(0, 2).map((i) => i.checked), [true, false]);
    // Neither in a project: No project is ticked; one in Acme and one not: nothing is.
    assert.equal(items.find((i) => i.label === 'Move to project').items[0].checked, true);
    const mixed = core.recordingMenu(DATA, [rowOf('a', { client: 'c1' }), rowOf('b')]).items.find((i) => i.label === 'Move to project').items;
    assert.deepEqual(mixed.map((i) => i.checked), [false, false]);
});

test('a read-only console\'s menu holds only Open, and nothing for several', () => {
    const readOnly = { ...DATA, runnable: ['recording.open'] };
    assert.deepEqual(words(core.recordingMenu(readOnly, [rowOf('a')]).items), ['Open']);
    assert.deepEqual(core.recordingMenu(readOnly, [rowOf('a'), rowOf('b')]).items, []);
});
