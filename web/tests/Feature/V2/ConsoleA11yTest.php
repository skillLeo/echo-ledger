<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Models\WorkspaceInvitation;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * FIX-06 on the web console: what axe-core (scripts/a11y.mjs) found serious or critical on the new console's main pages
 * (Library, a recording, Activity, Settings, Workspace) in English and Urdu, fixed and held here. A control's name holds
 * the words it shows (label-content-name-mismatch), a target is 24 px (target-size), a heading or a badge reads at
 * 4.5 to 1 (color-contrast), and the console's head installs the keyboard and screen-reader help
 * (resources/js/a11y-core.cjs, tested under Node). Classic keeps every byte (ClassicGoldenTest).
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

it('names the workspace button with the words it shows, its letters drawn, in the new console only', function () {
    a11yConsole(true);
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toMatch('~class="el-tb-workspace-btn" aria-label="Workspace: Personal, Owner"~')
        ->toContain('<span class="el-tb-workspace-mark" aria-hidden="true" data-initials="P"></span>')
        ->toMatch('~<span class="el-tb-workspace-name">Personal</span>\s*(<!--\[if BLOCK\]><!\[endif\]-->)?\s*<span class="el-tb-workspace-role">Owner</span>~');

    // In Urdu too (each name set apart for its own direction).
    a11yConsole(true, 'ur');
    preg_match('~class="el-tb-workspace-btn" aria-label="([^"]*)"~', (string) $this->get('/app/sessions')->assertOk()->getContent(), $m);
    expect(str_replace(["\u{2068}", "\u{2069}"], '', $m[1] ?? ''))->toBe('ورک اسپیس: ذاتی، مالک');

    a11yConsole(false);
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())->toContain('aria-label="Workspace: Personal"')
        ->toContain('<span class="el-tb-workspace-mark" aria-hidden="true">P</span>')->not->toContain('data-initials="P"');
});

it('names the recording\'s project chip with the words it shows, in the new console only', function () {
    $recording = Fx::session($this->owner, Fx::device($this->owner));
    a11yConsole(true);
    Livewire::test(ViewCaptureSession::class, ['record' => $recording->id])->assertSeeHtml('aria-label="Project: No project. Change"');
    $recording->update(['client_id' => Fx::client($this->owner, 'Acme <Ltd>')->id]);
    Livewire::test(ViewCaptureSession::class, ['record' => $recording->id])->assertSeeHtml('aria-label="Project: Acme &lt;Ltd&gt;. Change"');

    a11yConsole(false);
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
    // The plain tables' headings whole, in the secondary text colour.
    expect($rule($console, ".fi-main table > thead > tr[style*='opacity']"))->toContain('opacity: 1 !important;')->toContain('color: var(--el-role-text-secondary);');
    // Dark badges write in the accent's text colour, not its soft fill.
    expect($rule($values, 'html.dark:root:root'))->toContain('--primary-100: var(--el-role-accent-text);');
});

it('keeps the Workspace page\'s plain tables\' headings at full strength under the new design', function () {
    WorkspaceInvitation::query()->create(['workspace_id' => (int) $this->owner->current_workspace_id, 'email' => 'hamza@example.com', 'role' => WorkspaceRole::Member->value,
        'token_hash' => hash('sha256', Str::random(40)), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDays(7)]);
    a11yConsole(true);
    // The rule that lifts them matches these rows: a table in the page, its header row's inline opacity.
    expect((string) $this->get('/app/workspace')->assertOk()->getContent())->toContain('<tr style="text-align:left;opacity:.7">');
});
