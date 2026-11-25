<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Support\SettingsContext;
use App\Models\Plan;
use App\Models\UsageLedgerEntry;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * FIX-06 on the web console: what axe-core (scripts/a11y.mjs) found serious or critical on the new console's main pages
 * (Library, a recording, Activity, Settings, Workspace) in English and Urdu, fixed and held here. A control's name holds
 * the words it shows (label-content-name-mismatch), a target is 24 px (target-size), a heading or a badge reads at
 * 4.5 to 1 (color-contrast), the rail is one Tab stop, and the console's head installs the keyboard and screen-reader
 * help (resources/js/a11y-core.cjs, whose Node test runs here). Classic keeps every byte (ClassicGoldenTest). Contrast
 * ratios and target sizes are measured in a browser by scripts/a11y.mjs, not here: this file holds the styles that
 * give them.
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->owner = Fx::user(['name' => 'Saif Hassam', 'email' => 'saif@example.com']);
});

function a11yConsole(bool $v2, string $language = 'en'): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, $v2, 0, [], [], $v2 ? 'everyone' : 'off');
    }
    test()->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
    test()->actingAs(test()->owner->fresh(), 'web');
}

it('names the workspace button with the words it shows first, its letters drawn, in the new console only', function () {
    a11yConsole(true);
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toMatch('~class="el-tb-workspace-btn" aria-label="Personal, Owner, workspace"~')
        ->toContain('<span class="el-tb-workspace-mark" aria-hidden="true" data-initials="P"></span>')
        ->toMatch('~<span class="el-tb-workspace-name">Personal</span>\s*(<!--\[if BLOCK\]><!\[endif\]-->)?\s*<span class="el-tb-workspace-role">Owner</span>~');

    // In Urdu too (each name set apart for its own direction).
    a11yConsole(true, 'ur');
    preg_match('~class="el-tb-workspace-btn" aria-label="([^"]*)"~', (string) $this->get('/app/sessions')->assertOk()->getContent(), $m);
    expect(str_replace(["\u{2068}", "\u{2069}"], '', $m[1] ?? ''))->toBe('ذاتی، مالک، ورک اسپیس');

    a11yConsole(false);
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())->toContain('aria-label="Workspace: Personal"')
        ->toContain('<span class="el-tb-workspace-mark" aria-hidden="true">P</span>')->not->toContain('data-initials="P"');
});

it('names the recording\'s project chip with the words it shows first, in the new console only', function () {
    $recording = Fx::session($this->owner, Fx::device($this->owner));
    a11yConsole(true);
    Livewire::test(ViewCaptureSession::class, ['record' => $recording->id])->assertSeeHtml('aria-label="No project. Change project"');
    $recording->update(['client_id' => Fx::client($this->owner, 'Acme <Ltd>')->id]);
    Livewire::test(ViewCaptureSession::class, ['record' => $recording->id])->assertSeeHtml('aria-label="Acme &lt;Ltd&gt;. Change project"');

    a11yConsole(false);
    Livewire::test(ViewCaptureSession::class, ['record' => $recording->id])->assertSeeHtml('aria-label="Project: Acme &lt;Ltd&gt;. Change"');
    $recording->update(['client_id' => null]);
    Livewire::test(ViewCaptureSession::class, ['record' => $recording->id])->assertSeeHtml('aria-label="Project: none. Change"');
});

it('names the profile card with the words it shows, in their order, then where it leads', function () {
    a11yConsole(true);
    $html = (string) $this->get('/app/settings')->assertOk()->getContent();
    preg_match('~class="el-pcard-body" aria-label="([^"]*)"~', $html, $m);
    $name = html_entity_decode($m[1] ?? '', ENT_QUOTES);
    expect($name)->toStartWith('Saif Hassam, saif@example.com, ')->toEndWith('. Opens plan and usage.');
    // Every word the card shows, in the order it shows them.
    preg_match('~<a href="[^"]*" class="el-pcard-body".*?</a>~s', $html, $card);
    $shown = trim((string) preg_replace('/\s+/', ' ', strip_tags($card[0] ?? '')));
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}@.]+/u', $shown) ?: []));
    $from = 0;
    foreach ($words as $word) {
        $at = mb_strpos($name, $word, $from);
        expect($at)->not->toBeFalse("\"{$word}\" is shown but not in the card's name: {$name}");
        $from = (int) $at + mb_strlen($word);
    }

    a11yConsole(false);
    expect((string) $this->get('/app/settings')->assertOk()->getContent())->toContain('aria-label="Saif Hassam, saif@example.com, ');
});

it('ends each line of the profile card\'s name once, never with two full stops', function () {
    $owner = Fx::user(['name' => 'Sara Khan']);
    Workspace::query()->whereKey($owner->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id'), 'plan_expires_at' => now()->addDays(30)]);
    $member = withoutWorkspace(Fx::user(['name' => 'Bilal', 'email' => 'bilal@example.com']));
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $member, WorkspaceRole::Member);
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($member->fresh(), 'web');
    SettingsContext::forget();
    $html = (string) $this->get('/app/settings')->assertOk()->getContent();
    preg_match('~class="el-pcard-body" aria-label="([^"]*)"~', $html, $m);
    $name = html_entity_decode($m[1] ?? '', ENT_QUOTES);
    // The card shows "… is on Pro. Managed by Sara Khan." under the plan.
    expect($html)->toContain('Managed by Sara Khan.</span>')
        ->and($name)->toStartWith('Bilal, bilal@example.com, Pro, ')->toEndWith('Managed by Sara Khan. Opens plan and usage.')->not->toContain('..');
});

it('makes the rail one Tab stop before any script runs: the place you are on, else the logo', function () {
    a11yConsole(true);
    $railOf = fn (string $path): string => preg_match('~<nav class="v2-rail".*?</nav>~s', (string) $this->get($path)->assertOk()->getContent(), $m) === 1 ? $m[0] : '';

    $library = $railOf('/app/sessions');
    expect(substr_count($library, 'tabindex="0"'))->toBe(1)
        ->and($library)->toMatch('~aria-current="page"[^>]*data-module="library"[^>]*tabindex="0"~')
        ->toMatch('~class="v2-rail-logo"[^>]*tabindex="-1"~')
        ->toMatch('~data-sidebar-upload data-record\s+title="[^"]*" tabindex="-1"~')
        ->toContain('data-record-menu tabindex="-1"')
        ->toMatch('~data-module="projects"[^>]*tabindex="-1"~')
        ->toMatch('~data-module="help" tabindex="-1"~')
        ->toMatch('~data-module="settings"[^>]*tabindex="-1"~')
        ->toMatch('~class="fi-user-menu-trigger v2-um-trigger" aria-label="[^"]*" tabindex="-1">~');
    expect(substr_count($railOf('/app/settings'), 'tabindex="0"'))->toBe(1)
        ->and($railOf('/app/settings'))->toMatch('~aria-current="page"[^>]*data-module="settings"[^>]*tabindex="0"~');

    // Home is no place: the logo is the stop.
    $home = $railOf('/app');
    expect(substr_count($home, 'tabindex="0"'))->toBe(1)->and($home)->toMatch('~class="v2-rail-logo is-on"[^>]*tabindex="0"~');
});

it('keeps the help script\'s rules as its Node test says (the rail\'s keys, the redraws, the table boxes)', function () {
    $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/a11y-core.test.mjs']);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');

it('installs the keyboard and screen-reader help in the new console only, never in the admin panel', function () {
    a11yConsole(true);
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())
        ->toContain('window.ElA11yCore.install(document, window);')->toContain('function markGroupHeaders(doc)');
    a11yConsole(false);
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())->not->toContain('ElA11yCore');
    expect((string) view('filament.v2.head', ['sheet' => 'admin'])->render())->not->toContain('ElA11yCore');
});

it('keeps the fixes for size and contrast in the new design\'s styles', function () {
    $console = (string) file_get_contents(resource_path('css/v2/console.css'));
    $values = (string) file_get_contents(resource_path('css/v2/values.css'));
    $rule = fn (string $css, string $selector): string => preg_match('~(?:^|\n)'.preg_quote($selector, '~').' \{([^}]*)\}~', $css, $m) === 1 ? $m[1] : '';

    // Record's menu: a 24 px target whose 20 px circle stays at its corner.
    expect($rule($console, '.v2-rail-rec-more'))->toContain('width: var(--el-space-24);')->toContain('height: var(--el-space-24);')
        ->and($rule($console, '.v2-rail-rec-more::before'))->toContain('inset-block: 0 var(--el-space-4);');
    // Previous and Next keep clear of the title's Rename.
    expect($rule($console, '.v2-viewer-head[data-recording-nav]'))->toContain('margin-block-end: var(--el-space-12);');
    // Its focus ring goes round the circle, not the target.
    expect($rule($console, '.v2-rail-rec-more:focus-visible'))->toContain('outline: none;')
        ->and($rule($console, '.v2-rail-rec-more:focus-visible::before'))->toContain('outline: var(--el-focus-width) solid var(--el-role-accent-focus);');
    // The plain tables' headings whole, in the secondary text colour.
    expect($rule($console, '.v2-plain-thead'))->toContain('opacity: 1 !important;')->toContain('color: var(--el-role-text-secondary);');
    // Dark badges write in the accent's text colour, not its soft fill.
    expect($rule($values, 'html.dark:root:root'))->toContain('--primary-100: var(--el-role-accent-text);');
});

it('marks the Workspace page\'s and Usage\'s plain tables\' heading rows for full strength under the new design only', function () {
    WorkspaceInvitation::query()->create(['workspace_id' => (int) $this->owner->current_workspace_id, 'email' => 'hamza@example.com', 'role' => WorkspaceRole::Member->value,
        'token_hash' => hash('sha256', Str::random(40)), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDays(7)]);
    UsageLedgerEntry::query()->create(['workspace_id' => $this->owner->current_workspace_id, 'user_id' => $this->owner->id, 'source' => 'server', 'report_id' => 'a11y-usage',
        'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => 60000, 'channels' => 1, 'billed_ms' => 60000,
        'unit_price_usd' => 0, 'cost_usd' => 0, 'billable' => true, 'occurred_at' => now()]);
    $marked = '<tr style="text-align:left;opacity:.7" class="v2-plain-thead">';

    a11yConsole(true);
    expect(substr_count((string) $this->get('/app/workspace')->assertOk()->getContent(), $marked))->toBe(1, 'the invitations\' heading row')
        ->and((string) $this->get('/app/usage')->assertOk()->getContent())->toContain('<thead>'.$marked.'<th style="padding:.5rem .75rem">Person</th>');

    // Classic, and the admin panel's copy of By person (which never says v2), keep their rows as they were.
    a11yConsole(false);
    expect((string) $this->get('/app/workspace')->assertOk()->getContent())->toContain('<tr style="text-align:left;opacity:.7">')->not->toContain('v2-plain-thead')
        ->and((string) $this->get('/app/usage')->assertOk()->getContent())->toContain('<thead><tr style="text-align:left;opacity:.7"><th')->not->toContain('v2-plain-thead');
    a11yConsole(true);
    expect((string) view('filament.pages.usage-people', ['people' => [['name' => 'Saif', 'email' => 'saif@example.com', 'minutes' => 1.0, 'input_tokens' => 1,
        'output_tokens' => 1, 'cost_usd' => 0.0, 'plan_percent' => null]]])->render())->not->toContain('v2-plain-thead');
});
