<?php

declare(strict_types=1);

use App\Filament\Pages\LiveAssist\AssistPreps;
use App\Filament\Pages\LiveAssist\AssistSessions;
use App\Filament\Pages\WorkspaceSettings;
use App\Filament\Support\SettingsContext;
use App\Models\AssistPrep;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Assist\AssistRecords;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The Workspace page (people, invitations, invite links) and Live Assist (preps, sessions, a session's report, its
 * settings, the super admin's Live Assist now, the workspaces overview) in the console's language: in the new frame in
 * Urdu their words read Urdu, and English reads exactly as before (ClassicGoldenTest and AdminClassicGoldenTest keep
 * their goldens).
 */

const WORKSPACE_ASSIST_FILES = [
    'app/Filament/Pages/WorkspaceSettings.php',
    'app/Filament/Pages/LiveAssist/AssistPreps.php',
    'app/Filament/Pages/LiveAssist/AssistReportPage.php',
    'app/Filament/Pages/LiveAssist/AssistSessions.php',
    'app/Filament/Pages/LiveAssist/AssistSettings.php',
    'resources/views/filament/pages/workspace-invitations.blade.php',
    'resources/views/filament/pages/live-assist-now.blade.php',
    'resources/views/filament/pages/assist-report.blade.php',
    'resources/views/filament/pages/workspaces-overview.blade.php',
];

beforeEach(function () {
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    app(InterfaceFlags::class)->set('live_assist', true, 0, [], []);
    $this->owner = Fx::user(['name' => 'Bilal Qureshi', 'email' => 'bilal@qureshi-textiles.example']);
    $workspaceId = (int) $this->owner->current_workspace_id;
    // A second workspace of the person (Switch workspace), a teammate and an invitation waiting in the first.
    app(Workspaces::class)->createFor($this->owner, 'Qureshi Textiles team');
    $this->owner->forceFill(['current_workspace_id' => $workspaceId])->save();
    $this->mate = Fx::user(['name' => 'Sana Malik', 'email' => 'sana@qureshi-textiles.example']);
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($workspaceId), $this->mate);
    WorkspaceInvitation::query()->create(['workspace_id' => $workspaceId, 'email' => 'omar@qureshi-textiles.example', 'role' => 'member',
        'token_hash' => hash('sha256', 'invite'), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDays(7)]);
    AssistPrep::query()->create(['workspace_id' => $workspaceId, 'user_id' => $this->owner->id, 'meeting_type' => 'interview', 'title' => 'Flutter role',
        'counterpart' => 'Sara', 'mode' => 'fast', 'hint_level' => 'outline', 'language_help' => 'urdu', 'status' => 'draft']);
});

/** Signs the owner in with the console in [$language] (Settings › Appearance › Language). */
function workspaceAssistAs(object $test, string $language): void
{
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $test->actingAs($test->owner->fresh(), 'web');
    app()->setLocale($language);
}

/**
 * What a page says, without its markup, and without the isolates Urdu puts around a name or a number in a sentence
 * (App\Support\Translation\IsolatingTranslator): they are invisible, and the words around them are what is checked.
 */
function workspaceAssistWords(string $html): string
{
    $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? ''), ENT_QUOTES);

    return preg_replace('/\s+/u', ' ', str_replace(["\u{2068}", "\u{2069}"], '', $text)) ?? '';
}

/** The page as drawn, in [$language]. */
function workspaceAssistPage(object $test, string $url, string $language): string
{
    workspaceAssistAs($test, $language);

    return workspaceAssistWords((string) $test->get($url)->assertOk()->getContent());
}

it('has an Urdu line for every word of the Workspace and Live Assist pages', function () {
    $urdu = json_decode((string) file_get_contents(lang_path('ur.json')), true, 512, JSON_THROW_ON_ERROR);
    $keys = [];
    foreach (WORKSPACE_ASSIST_FILES as $file) {
        preg_match_all('/__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', (string) file_get_contents(base_path($file)), $found, PREG_SET_ORDER);
        foreach ($found as $match) {
            $keys[] = stripslashes(($match[2] ?? '') !== '' ? $match[2] : $match[1]);
        }
    }
    // The labels the pages translate from their lists (a prep's meeting type, mode, hints and language help, a verdict,
    // a prep's status, a report's state, how long an invitation works).
    $lists = [AssistRecords::MEETING_TYPES, AssistRecords::MODES, AssistRecords::HINT_LEVELS, AssistRecords::LANGUAGE_HELP, AssistRecords::VERDICTS,
        AssistPreps::STATUS_LABELS, array_diff(AssistSessions::REPORT_LABELS, ['—']), WorkspaceSettings::DAY_OPTIONS];
    foreach ($lists as $labels) {
        array_push($keys, ...array_values($labels));
    }

    expect(count(array_unique($keys)))->toBeGreaterThan(200);
    foreach (array_unique($keys) as $key) {
        expect($urdu[$key] ?? '')->not->toBe('', "No Urdu for: {$key}");
    }
});

it('keeps the English of the sentences built from names, numbers and roles exactly as before', function () {
    app()->setLocale('en');
    $minutes = ceil(130 / 60);
    $seats = ['used' => 2, 'limit' => 5];

    expect(__(':name is now :role', ['name' => 'Ayesha', 'role' => 'admin']))->toBe('Ayesha'.' is now '.'admin')
        ->and(__('You are an :role', ['role' => 'owner']))->toBe('You are '.'an '.'owner')
        ->and(__('You are a :role', ['role' => 'viewer']))->toBe('You are '.'a '.'viewer')
        ->and('Acme · '.__('You are an :role', ['role' => 'owner']).' · '.__('Plan :plan', ['plan' => 'Pro']))->toBe('Acme · You are an owner · Plan Pro')
        ->and(__('Try again in :minutes minutes.', ['minutes' => $minutes]))->toBe('Try again in '.$minutes.' minutes.')
        ->and(__(':used of :limit seats taken (open invitations count).', $seats))->toBe("{$seats['used']} of {$seats['limit']} seats taken (open invitations count).")
        ->and(e(__('Invitation for :email cancelled', ['email' => 'o\'brien&co@example.test'])))->toBe(e("Invitation for o'brien&co@example.test cancelled"))
        ->and(__('One-time password for :email', ['email' => e('a&b@example.test')]))->toBe('One-time password for '.e('a&b@example.test'))
        ->and(__(':type · with :name', ['type' => 'Interview', 'name' => 'Sara']))->toBe('Interview'.' · with '.'Sara')
        ->and(__(':level hints', ['level' => 'Outline']))->toBe('Outline'.' hints')
        ->and(__('This month · :live · Practice: :time left', ['live' => __('Live Assist: :time left', ['time' => __(':hours h :minutes min', ['hours' => 5, 'minutes' => 0])]),
            'time' => __(':n min', ['n' => 45])]))->toBe('This month · '.'Live Assist: '.'5 h 0 min'.' left'.' · Practice: '.'45 min'.' left')
        ->and(__('24 h (:checks checks) · 7 days :uptime', ['checks' => number_format(1234), 'uptime' => '99.5%']))->toBe('24 h (1,234 checks) · 7 days 99.5%');
});

it('shows the Workspace page in Urdu in the new frame, and in English otherwise', function () {
    $url = WorkspaceSettings::getUrl(panel: 'app');
    $ur = workspaceAssistPage($this, $url, 'ur');
    // The role reads in Urdu too (ur.json's Owner, Admin, Member, Viewer).
    expect($ur)->toContain('آپ مالک ہیں')
        ->toContain('ہر فرد کی ریکارڈنگز اور پروجیکٹس صرف اسی کے ہیں')
        ->toContain('ورک اسپیس بدلیں')
        ->toContain('دعوت دیں')
        ->toContain('زیرِ التوا دعوتیں')
        ->toContain('دعوت کا کوئی لنک کھلا نہیں۔')
        ->not->toContain('You are an owner')
        ->not->toContain('Owners and admins invite and remove people and revoke devices')
        ->not->toContain('Switch workspace')
        ->not->toContain('Pending invitations')
        ->not->toContain('No invite link is open.');

    $en = workspaceAssistPage($this, $url, 'en');
    expect($en)->toContain('You are an owner')
        ->toContain('Owners and admins invite and remove people and revoke devices; viewers open what is shared with them and record nothing.')
        ->toContain('Switch workspace')
        ->toContain('Pending invitations')
        ->toContain('No invite link is open.')
        ->not->toContain('زیرِ التوا دعوتیں');
});

it('words the Workspace page\'s windows and notices in Urdu, and keeps them in English', function () {
    workspaceAssistAs($this, 'ur');
    Livewire::test(WorkspaceSettings::class)
        ->assertActionExists('transfer', fn (Action $a): bool => $a->getLabel() === 'حوالے کریں' && str_starts_with((string) $a->getModalDescription(), 'جسے آپ چنیں وہ مالک بن جاتا ہے'))
        ->callAction(TestAction::make('changeRole')->table($this->mate), ['role' => 'admin'])
        ->assertNotified("\u{2068}Sana Malik\u{2069} اب \u{2068}ایڈمن\u{2069} ہیں")
        ->callAction('rename', ['name' => 'Qureshi Textiles'])
        ->assertNotified('ورک اسپیس کا نام بدل گیا');

    workspaceAssistAs($this, 'en');
    Livewire::test(WorkspaceSettings::class)
        ->assertActionExists('transfer', fn (Action $a): bool => $a->getLabel() === 'Hand over'
            && $a->getModalDescription() === 'The person you pick becomes the owner; you stay as an admin. Only they can hand it back.')
        ->callAction(TestAction::make('changeRole')->table($this->mate), ['role' => 'member'])
        ->assertNotified('Sana Malik is now member')
        ->callAction('rename', ['name' => 'Qureshi Textiles'])
        ->assertNotified('Workspace renamed');
});

it('shows Live Assist preps in Urdu in the new frame, and in English otherwise', function () {
    $url = AssistPreps::getUrl(panel: 'app');
    $ur = workspaceAssistPage($this, $url, 'ur');
    expect($ur)->toContain('لائیو اسسٹ کی تیاریاں')
        ->toContain('یہاں میٹنگ کی تیاری کریں، پھر اسے کمپیوٹر یا فون ایپ سے شروع کریں۔')
        ->toContain('نئی تیاری')
        ->toContain('انٹرویو · Sara کے ساتھ')
        ->toContain('اشارے: خاکہ')
        ->toContain('مسودہ')
        ->toContain('وقت طے نہیں')
        ->not->toContain('Live Assist preps')
        ->not->toContain('Get ready for a meeting here')
        ->not->toContain('New prep')
        ->not->toContain('Interview · with Sara')
        ->not->toContain('Outline hints')
        ->not->toContain('Not scheduled');

    $en = workspaceAssistPage($this, $url, 'en');
    expect($en)->toContain('Live Assist preps')
        ->toContain('Get ready for a meeting here, then start it from the desktop or phone app.')
        ->toContain('New prep')
        ->toContain('Interview · with Sara')
        ->toContain('Outline hints')
        ->toContain('Draft')
        ->toContain('Not scheduled')
        ->not->toContain('نئی تیاری');

    workspaceAssistAs($this, 'ur');
    Livewire::test(AssistPreps::class)
        ->callAction('create', ['meeting_type' => 'boss', 'title' => 'Review', 'mode' => 'balanced', 'hint_level' => 'keywords', 'language_help' => 'off'])
        ->assertHasNoActionErrors()->assertNotified('تیاری محفوظ ہو گئی');
});
