<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\V2RecordingRow;
use App\Models\SessionOpen;
use App\Services\InterfaceFlags;
use App\Services\LibraryOrganiser;
use App\Services\RetentionPolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * WEB-LIB-021 (U9, docs/PLAN_V2_PARITY.md section 3): the new Library's row indicators from contract 2.0.0-i: the
 * unread dot (not opened by this person yet), the important flag, each label's colour dot (one of the eight by its role
 * token, any other colour only as a ringed dot, none the neutral ringed dot), "Audio · N days left" while retention will
 * move it to Trash, and a snippet of what was said. Classic's rows stay as they were (ClassicGoldenTest).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $this->owner = Fx::user(['name' => 'Bilal Qureshi']);
    $this->device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $this->call = Fx::session($this->owner, $this->device, ['title' => 'Payment terms for the March shipment', 'source_app' => SourceApp::WhatsApp,
        'started_at' => Carbon::parse('2026-11-17 09:00:00'), 'duration_ms' => 60_000, 'important' => true, 'created_at' => Carbon::parse('2026-11-17 09:00:00')]);
    Fx::transcript($this->call, 'Assalam o alaikum, the proforma is ready. Forty percent advance this time.');
    $this->kept = Fx::session($this->owner, $this->device, ['title' => 'Rate for 12 mm rebar', 'source_app' => SourceApp::PhoneCall,
        'started_at' => Carbon::parse('2026-11-16 09:00:00'), 'keep_forever' => true]);
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

/** One row as the page draws it, by its recording. */
function indicatorRow(string $html, string $id): string
{
    preg_match('~<div class="v2-lib-row" data-recording="'.preg_quote($id, '~').'".*?</time>~s', $html, $m);

    return $m[0] ?? '';
}

it('dots a recording this person has not opened, and takes the dot away once they open it', function () {
    $row = fn (): string => indicatorRow(Livewire::test(ListCaptureSessions::class)->html(), (string) $this->call->id);
    expect($row())->toContain('data-unread')->toContain('aria-label="Not opened yet"');

    SessionOpen::mark((string) $this->call->id, $this->owner->id);
    expect($row())->not->toContain('data-unread');
    // A teammate's opening is theirs: it does not take this person's dot away.
    $mate = Fx::user();
    SessionOpen::mark((string) $this->kept->id, $mate->id);
    expect(indicatorRow(Livewire::test(ListCaptureSessions::class)->html(), (string) $this->kept->id))->toContain('data-unread');
});

it('flags an important recording, and says so on its row for the selection', function () {
    $html = Livewire::test(ListCaptureSessions::class)->html();
    // In the warning colour (amber): red is for deleting and recording only.
    expect(indicatorRow($html, (string) $this->call->id))->toContain('data-important="1"')->toContain('<span class="v2-status is-warning" role="img" aria-label="Important"')->toContain('data-important-flag')
        ->and(indicatorRow($html, (string) $this->kept->id))->toContain('data-important="0"')->not->toContain('data-important-flag');
});

it('draws each label\'s colour dot by the design\'s rules: the eight by their tokens, another colour ringed, none neutral', function () {
    foreach (['Invoice', 'Urgent', 'Later', 'Zero'] as $label) {
        $this->call->labels()->create(['label' => $label]);
    }
    $organiser = app(LibraryOrganiser::class);
    $organiser->setLabelColor((int) $this->call->workspace_id, $this->owner->id, 'invoice', '#2D9A63');
    $organiser->setLabelColor((int) $this->call->workspace_id, $this->owner->id, 'Urgent', '#123abc');

    $row = indicatorRow(Livewire::test(ListCaptureSessions::class)->html(), (string) $this->call->id);
    preg_match_all('~data-label-dot="([^"]+)"\s+style="([^"]+)"~', $row, $dots, PREG_SET_ORDER);
    $style = collect($dots)->mapWithKeys(fn (array $d): array => [$d[1] => $d[2]]);
    // One of the eight, whatever its case: its role token (neutral until the label tokens are merged).
    expect($style['Invoice'])->toContain('background-color: var(--el-role-label-green-dot, var(--el-role-text-secondary))')->not->toContain('#')
        // Any other colour: only the dot, in that colour, with a hairline ring.
        ->and($style['Urgent'])->toContain('background-color: #123abc')->toContain('var(--el-role-border-strong)')
        // No colour: the neutral dot with the ring.
        ->and($style['Later'])->toContain('background-color: var(--el-role-bg-subtle)')->toContain('var(--el-role-border-strong)')
        // At most three dots on a row, in the labels' order.
        ->and($style->keys()->all())->toBe(['Invoice', 'Later', 'Urgent']);
    expect($row)->toContain('aria-label="Label: Invoice"');
});

it('says "Audio · N days left" only within a week of the audio going, amber from 3 days, rounded up, and never when it is kept forever', function () {
    $expires = app(RetentionPolicy::class)->expiresAt($this->call->fresh());
    $row = fn (string $id): string => indicatorRow(Livewire::test(ListCaptureSessions::class)->html(), $id);
    // More than a week left: no badge on the row (the date stays in the recording's details).
    expect(Carbon::now()->diffInDays($expires))->toBeGreaterThan(7)
        ->and($row((string) $this->call->id))->not->toContain('data-days-left');

    Carbon::setTestNow($expires->copy()->subDays(6)->subHours(2));
    expect($row((string) $this->call->id))->toMatch('~class="v2-status is-neutral" data-days-left>.*Audio · 7 days left~s')
        ->and($row((string) $this->kept->id))->not->toContain('data-days-left');
    Carbon::setTestNow($expires->copy()->subDays(3));
    expect($row((string) $this->call->id))->toMatch('~class="v2-status is-warning" data-days-left>.*Audio · 3 days left~s');
    // On its last hours it says 1 day; past its time, 0 (never below).
    Carbon::setTestNow($expires->copy()->subHours(3));
    expect(V2RecordingRow::daysLeft($this->call->fresh()))->toBe(['text' => 'Audio · 1 day left', 'days' => 1, 'soon' => true]);
    Carbon::setTestNow($expires->copy()->addHours(3));
    expect(V2RecordingRow::daysLeft($this->call->fresh())['text'])->toBe('Audio · 0 days left');
    Carbon::setTestNow($expires->copy()->subDays(8));
    expect(V2RecordingRow::daysLeft($this->call->fresh()))->toBeNull();
});

it('shows a snippet of what was said, and none without a transcript', function () {
    $html = Livewire::test(ListCaptureSessions::class)->html();

    expect(indicatorRow($html, (string) $this->call->id))->toContain('data-snippet>Assalam o alaikum, the proforma is ready. Forty percent advance this time.<')
        ->and(indicatorRow($html, (string) $this->kept->id))->not->toContain('data-snippet');
});

it('reads its indicators in Urdu when the console is in Urdu', function () {
    $prefs = $this->owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $this->owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($this->owner->fresh(), 'web');
    app()->setLocale('ur');

    Carbon::setTestNow(app(RetentionPolicy::class)->expiresAt($this->call->fresh())->subDays(2));
    $row = indicatorRow(Livewire::test(ListCaptureSessions::class)->html(), (string) $this->call->id);
    expect($row)->toContain('aria-label="اہم"')->toContain('aria-label="ابھی کھولی نہیں گئی"')->toContain('آڈیو · 2 دن باقی');
});
