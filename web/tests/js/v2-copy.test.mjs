// The v2.0 Copy menu (resources/views/filament/v2/copy.blade.php) against classic's own copies: the player's
// transcriptText (resources/views/filament/infolists/transcript-player.blade.php) run on one transcript with two
// speakers, an unsure word and the note line. node --test tests/js/v2-copy.test.mjs (Pest runs it: RecordingCopyTest).
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const views = new URL('../../resources/views/filament/', import.meta.url);
const playerSource = readFileSync(new URL('infolists/transcript-player.blade.php', views), 'utf8');
const copySource = readFileSync(new URL('v2/copy.blade.php', views), 'utf8');

// Classic's player methods, as the page has them: markedLine and transcriptText.
const from = playerSource.indexOf('markedLine(i, style) {');
const to = playerSource.indexOf('// A line on screen as text parts');
assert.ok(from > 0 && to > from, 'the player still has markedLine and transcriptText');
const methods = vm.runInNewContext('({' + playerSource.slice(from, to) + '})');

function player() {
    const segments = [
        { speaker: 'A', start_ms: 0, text: 'Assalam o alaikum, the proforma is ready.', words: [{ t: 'proforma', u: true }] },
        { speaker: 'B', start_ms: 65000, text: 'Forty percent advance this time.', words: [] },
    ];
    return Object.assign(Object.create(methods), {
        view: 'original',
        d: { segments, unsure: { style: 'brackets', note: true, note_text: '' }, screens: [{ index: 1, at_ms: 30000, description: 'The invoice' }] },
        viewReady: () => false,
        lineText: (i) => segments[i].text,
        name: (s) => (s.speaker === 'A' ? 'Bilal' : 'Sana'),
        clock: (ms) => Math.floor(ms / 60000) + ':' + String(Math.floor(ms / 1000) % 60).padStart(2, '0'),
        shotLine: (s) => '[Screen ' + s.index + ': ' + s.description + ']',
    });
}

// Runs the v2 script with a page that has that player, and returns what it puts on the clipboard.
async function v2Copy(mode, header = '') {
    const p = player();
    let clip = null;
    const notice = { title() { return this; }, success() { return this; }, danger() { return this; }, send() {} };
    const page = {
        document: { querySelector: (q) => (q === '.el-player' ? {} : null) },
        Alpine: { $data: () => p },
        navigator: { clipboard: { writeText: async (t) => { clip = t; } } },
        FilamentNotification: function () { return notice; },
    };
    page.window = page;
    const script = copySource.slice(copySource.indexOf('<script>') + 8, copySource.indexOf('</script>')).replace(/@js\([^)]*\)\)/g, "'Copied'");
    vm.runInNewContext(script, page);
    await page.elV2Copy(mode, header);
    return { clip, classic: p };
}

test('Copy text is classic\'s Copy text: who said what, the unsure word marked, the note line first', async () => {
    const { clip, classic } = await v2Copy('text');
    assert.equal(clip, classic.transcriptText(false));
    assert.match(clip, /^Note: \[\?word\]/);
    assert.ok(clip.includes('Bilal: Assalam o alaikum, the [?proforma] is ready.'));
    assert.ok(clip.includes('\nSana: Forty percent advance this time.'));
    assert.ok(! clip.includes('[0:'), 'no timestamps');
});

test('Copy with timestamps is classic\'s', async () => {
    const { clip, classic } = await v2Copy('times');
    assert.equal(clip, classic.transcriptText(true));
    assert.ok(clip.includes('[1:05] Sana: Forty percent advance this time.'));
});

test('Copy for AI is classic\'s Copy for AI after the header', async () => {
    const header = 'Recording: Payment terms\nPeople: Bilal, Sana';
    const { clip, classic } = await v2Copy('ai', header);
    assert.equal(clip, header + '\n\n' + classic.transcriptText(false, true));
    assert.ok(clip.includes('[Screen 1: The invoice]'));
});
