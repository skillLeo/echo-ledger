<?php

declare(strict_types=1);

use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The new recording page's Copy menu (docs/PLAN_V2_PARITY.md section 4): Copy text (without timestamps), with timestamps,
 * and Copy for AI with the apps' header (V2CopyForAi::header); the player writes the text exactly as classic's buttons do (filament.v2.copy, held to
 * classic by tests/js/v2-copy.test.mjs). Classic keeps its player buttons only.
 */

beforeEach(function () {
    $this->owner = Fx::user();
    $this->recording = Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Payment terms', 'caller_name' => 'Ahmed']);
    Fx::transcript($this->recording, 'The proforma is ready.');
    Filament::setCurrentPanel('app');
    $this->actingAs($this->owner->fresh(), 'web');
});

it('offers Copy ▾ beside Share with the three copies, and the header for AI', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }

    $html = (string) $this->get('/app/sessions/'.$this->recording->id)->assertOk()->getContent();

    expect($html)->toContain('window.elV2Copy = ')
        ->toContain('Copy with timestamps')->toContain('Copy for AI')->not->toContain('Copy without timestamps')
        ->toContain("window.elV2Copy('text'")->toContain("window.elV2Copy('ai'")
        // The apps' header (desktop aiHeader, V2CopyForAi): "# Title", the date line, the people who speak, the language.
        ->toContain('# Payment terms\\n')->toContain('People: Me')->not->toContain('Recording: Payment terms');
    expect(strpos($html, '>Share<'))->toBeLessThan(strpos($html, "window.elV2Copy('text'"));
});

it('keeps the header for AI free of isolate marks in Urdu, where the page has them', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
    app('auth')->forgetGuards();
    $this->actingAs($this->owner->fresh(), 'web');

    $html = (string) $this->get('/app/sessions/'.$this->recording->id)->assertOk()->getContent();

    $start = strpos($html, "window.elV2Copy('ai', ");
    expect($start)->not->toBeFalse();
    $header = substr($html, $start, (int) strpos($html, ')', $start) - $start);
    expect($header)->toContain('Payment terms')->toContain('Ahmed')
        ->not->toContain('\u2068')->not->toContain('\u2069')->not->toContain("\u{2068}")->not->toContain("\u{2069}");
});

it('leaves the classic recording page as it was', function () {
    $html = (string) $this->get('/app/sessions/'.$this->recording->id)->assertOk()->getContent();

    expect($html)->not->toContain('window.elV2Copy');
});

it('copies what classic copies (the Node test of the v2 script against the player)', function () {
    $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/v2-copy.test.mjs']);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');
