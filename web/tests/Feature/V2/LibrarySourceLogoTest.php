<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\LibraryView;
use App\Models\CaptureSession;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * U9b: WEB-LIB-020, each row of the new Library leads with the app's own mark and a badge for the device it came from
 * (EL-Design's filament.v2.components.source-logo, as the desktop's SourceAvatar), named for assistive tools; and the
 * grouping's counts, worked out from the rows' raw columns for a large library, keep the groups' own keys.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $this->owner = Fx::user();
    $device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $this->whatsapp = Fx::session($this->owner, $device, ['title' => 'Payment terms', 'source_app' => SourceApp::WhatsApp, 'source_device' => SourceDevice::Phone]);
    $this->zoom = Fx::session($this->owner, null, ['title' => 'Kick-off', 'source_app' => SourceApp::Zoom, 'source_device' => SourceDevice::Desktop,
        'started_at' => Carbon::parse('2026-11-15 23:30:00'), 'pinned' => true, 'language' => 'ur']);
    $this->other = Fx::session($this->owner, null, ['title' => 'Something else', 'source_app' => SourceApp::Other, 'source_device' => SourceDevice::Web,
        'status' => SessionStatus::Failed]);
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

function logoRow(string $html, string $id): string
{
    preg_match('~<div class="v2-lib-row" data-recording="'.preg_quote($id, '~').'".*?</time>~s', $html, $m);

    return $m[0] ?? '';
}

it('leads each row with the app\'s own mark and its device badge, named "WhatsApp, on a phone"', function () {
    $html = Livewire::test(ListCaptureSessions::class)->html();

    expect(logoRow($html, (string) $this->whatsapp->id))->toContain('class="v2-source"')->toContain('role="img" aria-label="WhatsApp, on a phone"')
        ->toContain('fill="#25D366"')->toContain('class="v2-source-device"')
        ->and(logoRow($html, (string) $this->zoom->id))->toContain('aria-label="Zoom, on a computer"')->toContain('fill="#0B5CFF"')
        // A source without a mark keeps the v2 glyph, with its badge.
        ->and(logoRow($html, (string) $this->other->id))->toContain('aria-label="Other, on the web"')->toContain('class="v2-source-fallback"');
});

it('names the mark in Urdu when the console is in Urdu', function () {
    $prefs = $this->owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $this->owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($this->owner->fresh(), 'web');
    app()->setLocale('ur');

    expect(logoRow(Livewire::test(ListCaptureSessions::class)->html(), (string) $this->whatsapp->id))->toContain('aria-label="واٹس ایپ، فون پر"');
});

it('counts the groups from the rows\' raw columns with the same keys the groups give', function () {
    $columns = ['date' => ['started_at', 'pinned'], 'app' => ['source_app'], 'project' => ['client_id'], 'language' => ['language'],
        'status' => ['status'], 'device' => ['device_id', 'source_device']];
    foreach ($columns as $id => $cols) {
        $raw = LibraryView::rawKeyOf($id);
        $model = LibraryView::keyOf($id);
        foreach (CaptureSession::query()->get() as $session) {
            $row = (array) CaptureSession::query()->whereKey($session->id)->toBase()->select(array_map(fn (string $c): string => 'capture_sessions.'.$c, ['id', ...$cols]))->first();
            expect($raw($row))->toBe($model($session), "group {$id}");
        }
    }
});
