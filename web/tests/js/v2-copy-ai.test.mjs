// Copy for AI's lines (App\Filament\Support\V2CopyForAi::body) against the recording player's own transcriptText
// (resources/views/filament/infolists/transcript-player.blade.php), on the lines Pest wrote to V2_COPY_AI_FIXTURE:
// the same "[m:ss] Name: line", the same unsure-word marks and the same note. Run by
// tests/Feature/V2/LibrarySelectionMoreTest.php.
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const fixture = process.env.V2_COPY_AI_FIXTURE;
const php = fixture ? JSON.parse(readFileSync(fixture, 'utf8')) : null;
const source = readFileSync(new URL('../../resources/views/filament/infolists/transcript-player.blade.php', import.meta.url), 'utf8');
const from = source.indexOf('markedLine(i, style) {');
const to = source.indexOf('// A line on screen as text parts');
const methods = vm.runInNewContext('({' + source.slice(from, to) + '})');
const clockFrom = source.indexOf('clock(ms) {');
const clockTo = source.indexOf('pct(ms)');
const clock = vm.runInNewContext('({' + source.slice(clockFrom, clockTo) + '})').clock;

/** The player on the same lines, as the recording page has it. */
function player(style) {
    const segments = php.lines.map((l) => ({ speaker: l.name, start_ms: l.start_ms, text: l.text, words: l.words.map((w) => ({ t: w.t, ...(w.u ? { u: true } : {}) })) }));
    return Object.assign(Object.create(methods), {
        view: 'original',
        d: { segments, unsure: { style, note: true, note_text: '' }, screens: [] },
        viewReady: () => false,
        lineText: (i) => segments[i].text,
        name: (s) => s.speaker,
        clock,
        shotLine: () => '',
    });
}

test('the fixture is there', { skip: ! php }, () => assert.ok(php.lines.length > 0));

test('with timestamps and [?word] marks: the player\'s lines, word for word', { skip: ! php }, () => {
    const text = player('brackets').transcriptText(true, true);
    assert.equal(text, php.note + '\n\n' + php.brackets);
});

test('the suffix style, without timestamps: the player\'s lines too', { skip: ! php }, () => {
    const text = player('suffix').transcriptText(false, false);
    assert.ok(text.endsWith(php.suffix), text + '\n---\n' + php.suffix);
});
