<?php

declare(strict_types=1);

use App\Filament\Support\SettingsContext;

/*
 * W-209: a project's page (and the Projects list's rows) were hard-coded English, so the new frame in Urdu showed them
 * in English. Their words now go through the translations: in Urdu every tab, heading, label and note is Urdu, and in
 * English (and in classic) nothing changes.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

function projectPage(object $test, bool $v2, string $language, string $page): string
{
    webParityDesign($v2);
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($test->owner->fresh(), 'web')->get($test->pages[$page])->assertOk()->getContent();
}

/** What the page says, without its markup. */
function projectWords(string $html): string
{
    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? ''))) ?? '';
}

/** Per page: the English words it shows in this world (text, or an attribute's value when it starts with an `=`). */
const PROJECT_ENGLISH = [
    'project-overview' => ['Overview', 'Files', 'Rules', 'Last call', 'New subfolder', 'Notes after every call, the memory and suggested fixes.',
        'AI this month:', 'this project', 'The brief, the memory and the latest call notes, to paste into a chat.', 'Make again',
        'Memory', 'No brief yet. Add documents or instructions on Files', 'Decisions, open questions, changes and scope from the calls collect here.',
        'Line to remember', 'Open question', 'Latest call notes', 'Notes are written after each call filed under',
        '="Add a line to remember"', '="AI budget used this month"'],
    'project-recordings' => ['Search in this project', 'Newest first', 'Oldest first', 'By title', 'Longest first', '="Search in Qureshi Textiles"'],
    'project-screens' => ['No screens yet', 'When a meeting filed under Qureshi Textiles shares a screen'],
    'project-files' => ['Drop a document here, or', 'choose a file', 'kept encrypted', 'Paste instructions', 'Add instructions',
        'Nothing yet. The brief is written from the call notes', 'Uploading…', '="Paste the brief, an email or your own notes"', '="Add a document"'],
    'project-ask' => ['Ask anything about Qureshi Textiles', 'Answers come from the brief, the memory, the files and the transcripts',
        'Asking…', '="What did we agree about the deadline?"'],
    'project-rules' => ['Add a rule', 'Recordings that end without a project are filed here by the first match', 'Title contains',
        'Caller is (phone or email)', '="Words in the title, like Acme weekly"', '="Remove this rule?"'],
    'projects' => ['2 recordings'],
];

/** Whether the English is on the page: in its words, or in an attribute for an `=`. */
function projectHas(string $html, string $english): bool
{
    return str_starts_with($english, '=')
        ? str_contains(html_entity_decode($html), $english)
        : str_contains(projectWords($html), $english);
}

it('shows a project\'s page in Urdu in the new frame: none of its English tabs, headings, labels or notes are left', function () {
    foreach (PROJECT_ENGLISH as $page => $phrases) {
        $html = projectPage($this, true, 'ur', $page);
        foreach ($phrases as $english) {
            expect(projectHas($html, $english))->toBeFalse("{$page}: \"{$english}\" is still in English");
        }
    }
    expect(projectWords(projectPage($this, true, 'ur', 'project-overview')))->toContain('جائزہ')->toContain('یادداشت')->toContain('بریف');
    expect(projectWords(projectPage($this, true, 'ur', 'project-rules')))->toContain('قاعدہ');
    expect(projectWords(projectPage($this, true, 'ur', 'projects')))->toContain('ریکارڈنگ');
});

it('keeps a project\'s page in English in English and in classic', function () {
    foreach ([[true, 'en'], [false, 'en']] as [$v2, $language]) {
        foreach (PROJECT_ENGLISH as $page => $phrases) {
            $html = projectPage($this, $v2, $language, $page);
            foreach ($phrases as $english) {
                expect(projectHas($html, $english))->toBeTrue(($v2 ? 'v2' : 'classic')." {$page}: \"{$english}\" is missing");
            }
        }
    }
});
