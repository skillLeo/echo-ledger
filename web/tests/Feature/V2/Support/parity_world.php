<?php

declare(strict_types=1);

// The web parity audit's world and control reader, shared by WebParityTest and the sheet's generator
// (docs/v2/audit/parity.csv). One person with what every classic page shows when it has something to show.

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\FilingRule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Accounts\SettingsRegistry;
use App\Services\ActivityFeed;
use App\Services\InterfaceFlags;
use App\Services\ShareLinks;
use App\Services\Sharing\RequestLinks;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

if (defined('WEB_PARITY_SCOPES')) {
    return;
}

/**
 * The person, page => URL, and the ids the pages show.
 *
 * @return array{0: User, 1: array<string, string>}
 */
function webParityWorld(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $owner = Fx::user(['name' => 'Bilal Qureshi', 'email' => 'bilal@qureshi-textiles.example']);
    $device = Fx::device($owner, ['name' => 'Galaxy S25']);
    $workspaceId = (int) $owner->current_workspace_id;
    // A second workspace of the person (the switcher), a teammate and an invitation in the first (Workspace page).
    app(Workspaces::class)->createFor($owner, 'Qureshi Textiles team');
    $owner->forceFill(['current_workspace_id' => $workspaceId])->save();
    $mate = Fx::user(['name' => 'Sana Malik', 'email' => 'sana@qureshi-textiles.example']);
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($workspaceId), $mate);
    WorkspaceInvitation::query()->create(['workspace_id' => $workspaceId, 'email' => 'omar@qureshi-textiles.example', 'role' => 'member',
        'token_hash' => hash('sha256', 'invite'), 'invited_by' => $owner->id, 'expires_at' => now()->addDays(7)]);

    $client = Fx::client($owner, 'Qureshi Textiles');
    FilingRule::query()->create(['workspace_id' => $workspaceId, 'user_id' => $owner->id, 'client_id' => $client->id, 'kind' => 'title', 'value' => 'shipment', 'match_value' => 'shipment']);
    $rec = [];
    foreach ([
        ['Payment terms for the March shipment', SourceApp::WhatsApp, 1_104_000, $client->id, SessionStatus::Completed],
        ['Rate for 12 mm rebar', SourceApp::PhoneCall, 452_000, null, SessionStatus::Completed],
        ['Umrah group booking', SourceApp::Zoom, 348_000, $client->id, SessionStatus::Completed],
        ['Samples to the courier', SourceApp::VoiceNote, 64_000, null, SessionStatus::Failed],
        ['Old call to delete', SourceApp::WhatsApp, 90_000, null, SessionStatus::Completed],
    ] as $i => [$title, $app, $ms, $clientId, $status]) {
        $started = Carbon::parse('2026-11-17 09:00:00')->subHours($i * 20);
        $rec[] = Fx::session($owner, $device, ['client_ref' => 'parity-'.$i, 'title' => $title, 'source_app' => $app, 'source_device' => SourceDevice::Phone,
            'client_id' => $clientId, 'started_at' => $started, 'ended_at' => $started->copy()->addMilliseconds($ms), 'duration_ms' => $ms, 'status' => $status]);
    }
    Fx::readyRecording($rec[0], 'audio of the first call');
    $final = Fx::transcript($rec[0], 'Assalam o alaikum Bilal sahib, the proforma is ready. Forty percent advance this time.');
    $final->forceFill(['meta' => ['suggestions' => [1 => ['line' => 0, 'from' => 'proforma', 'to' => 'pro forma', 'reason' => 'A word the engine was unsure of.', 'status' => 'open']]]])->save();
    $rec[3]->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error_code' => 'no_audio']);
    $rec[4]->delete();
    app(ShareLinks::class)->create($rec[0], 7 * 86400, null, false, 'Bilal Qureshi');
    app(RequestLinks::class)->create($workspaceId, $owner->id, $client, 'Send me the March samples', now()->addDays(7), 5, 50);
    app(ActivityFeed::class)->post($owner, 'transcript_ready', 'Transcript ready', 'Payment terms for the March shipment', $rec[0], 'heroicon-o-document-text');
    Filament::setCurrentPanel('app');

    $pages = ['home' => '/app', 'library' => '/app/sessions', 'recording' => '/app/sessions/'.$rec[0]->id, 'projects' => '/app/clients',
        'search' => '/app/search?q=proforma', 'upload' => '/app/upload', 'activity' => '/app/activity', 'live' => '/app/live',
        'shares' => '/app/shares', 'shared-with-me' => '/app/shared-with-me', 'request-links' => '/app/request-links',
        'recently-deleted' => '/app/recently-deleted', 'devices' => '/app/devices', 'usage' => '/app/usage', 'workspace' => '/app/workspace',
        'help' => '/app/help', 'get-the-apps' => '/app/get-the-apps', 'assist-preps' => '/app/live-assist/preps',
        'assist-sessions' => '/app/live-assist/sessions', 'assist-settings' => '/app/live-assist/settings', 'settings' => '/app/settings'];
    foreach (['overview', 'recordings', 'screens', 'files', 'ask', 'rules'] as $tab) {
        $pages['project-'.$tab] = '/app/clients/'.$client->id.'?tab='.$tab;
    }
    foreach (array_keys(SettingsRegistry::PAGES) as $sub) {
        if (! in_array($sub, ['transcription-key', 'workspace'], true)) {
            $pages['settings-'.$sub] = '/app/settings/'.$sub;
        }
    }

    return [$owner->fresh(), $pages];
}

/** Turns the new design on (every ui_v2 flag for everyone) or off (classic). */
function webParityDesign(bool $v2): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, $v2, 0, [], [], $v2 ? 'everyone' : 'off');
    }
    app(InterfaceFlags::class)->set('live_assist', true, 0, [], [], 'everyone');
}

/** The scopes a control is looked for in: the page's own content, or one part of the frame. */
const WEB_PARITY_SCOPES = [
    'main' => '//main',
    'sidebar' => '//*[contains(concat(" ", normalize-space(@class), " "), " v2-sb-inner ") or contains(concat(" ", normalize-space(@class), " "), " fi-sidebar ")]',
    'topbar' => '//*[contains(concat(" ", normalize-space(@class), " "), " fi-topbar ")]',
    'usermenu' => '//*[contains(concat(" ", normalize-space(@class), " "), " fi-user-menu ")]',
    'switcher' => '//*[contains(concat(" ", normalize-space(@class), " "), " el-workspace-switcher ")]',
    'player' => '//*[contains(concat(" ", normalize-space(@class), " "), " el-player ")]',
    'rectabs' => '//*[contains(concat(" ", normalize-space(@class), " "), " v2-rec-tabs ")]',
];

/** What a control's text is compared on: lower case, digits out, spaces as one. */
function webParityKey(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\d+/u', '', mb_strtolower($text))));
}

/**
 * The controls of one scope of a page, as "element|normalised text" => the text as shown. A control is something the
 * person acts on (a link, a button, a field, a tab, a switch); its headings, labels, options and menu items are parts of
 * it, not controls of their own; a menu's items are read as "menuitem|<its button> › <item>". Nothing hidden counts: a closed menu, a modal, a <template>, hidden, aria-hidden,
 * display: none, or under an x-show the page may not be in. In `main`, nothing inside a nav, an aside or the settings menu
 * counts either (those are the frame's and the settings menu's rows).
 *
 * @return array<string, string>
 */
function webParityControls(string $html, string $scope = 'main'): array
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xp = new DOMXPath($doc);
    // Hidden as drawn: a <template>, hidden, aria-hidden, display: none, and anything under an x-show the server cannot
    // know is true (a modal, the selection's bar, a waiting queue). x-cloak alone is not hidden: it only waits for
    // Alpine (Filament's tab headers, its closed menus), and neither is x-show="true || ..." (its toolbar).
    $hidden = 'ancestor-or-self::*[self::template or @hidden or @aria-hidden="true" or contains(translate(@style, " ", ""), "display:none")'
        .' or (@x-show and not(starts-with(normalize-space(@x-show), "true")))]';
    $skip = '[not('.$hidden.')]'.($scope === 'main' ? '[not(ancestor::nav) and not(ancestor::aside) and not(ancestor::*[contains(@class,"el-settings-nav")])]' : '');
    $kinds = 'self::a or self::button or self::select or self::textarea or (self::input and not(@type="hidden"))'
        .' or @role="tab" or @role="switch"';
    $out = [];
    foreach ($xp->query(WEB_PARITY_SCOPES[$scope]) as $root) {
        if (! $root instanceof DOMElement || $xp->evaluate('boolean('.$hidden.')', $root)) {
            continue;
        }
        foreach ($xp->query('.//*['.$kinds.']'.$skip, $root) as $node) {
            if (! $node instanceof DOMElement || ($node->nodeName === 'a' && $xp->evaluate('boolean(ancestor::button)', $node))) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
            if ($text === '' || in_array($node->nodeName, ['select', 'input', 'textarea'], true)) {
                $for = $node->getAttribute('id') !== '' ? $xp->query('//label[@for="'.$node->getAttribute('id').'"]')->item(0) : null;
                $text = trim($node->getAttribute('aria-label') ?: ($for ? (string) preg_replace('/\s+/u', ' ', $for->textContent) : '') ?: $node->getAttribute('title') ?: $node->getAttribute('placeholder'));
            }
            $key = webParityKey($text);
            if ($key === '' || mb_strlen($text) > 80) {
                continue;
            }
            // An item of a menu is part of the menu's button: "menuitem|<button> › <item>".
            $panel = $xp->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " fi-dropdown-panel ")][1]', $node)->item(0);
            if ($panel instanceof DOMElement) {
                $trigger = $xp->query('preceding-sibling::*[contains(@class, "fi-dropdown-trigger")][1]', $panel)->item(0);
                $label = $trigger instanceof DOMElement ? trim((string) preg_replace('/\s+/u', ' ', $trigger->textContent)) : '';
                if ($label === '' && $trigger instanceof DOMElement) {
                    $button = $xp->query('.//*[@aria-label or @title]', $trigger)->item(0);
                    $label = $button instanceof DOMElement ? trim($button->getAttribute('aria-label') ?: $button->getAttribute('title')) : '';
                }
                $out['menuitem|'.(webParityKey($label) ?: 'menu').' › '.$key] ??= Str::limit(($label ?: 'Menu').' › '.$text, 80, '');

                continue;
            }
            $element = in_array($node->getAttribute('role'), ['tab', 'switch'], true) ? $node->getAttribute('role') : ($node->nodeName === 'textarea' ? 'input' : $node->nodeName);
            $out[$element.'|'.$key] ??= Str::limit($text, 80, '');
        }
    }

    return $out;
}

/** Whether the v2.0 frame drew the page (its top bar and sidebar), so a control found is the new design's. */
function webParityIsV2(string $html): bool
{
    return str_contains($html, 'v2-tb') && str_contains($html, 'v2-sb-inner');
}
