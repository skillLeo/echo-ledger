<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Support\SettingsContext;
use App\Models\CaptureSession;
use App\Services\InterfaceFlags;
use Filament\Actions\Action;
use Livewire\Livewire;

/*
 * Urdu package 1: the recording page's own words in the console's language. Its chips and banners
 * (filament.recording.chips), the actions, their windows and notices (ViewCaptureSession), the details card of the
 * classic Library inside the new frame (CaptureSessionInfolist), the Download window (filament.library.download-panel)
 * and the Library's drawer (filament.library.detail) read in Urdu; in English the same places read exactly as before,
 * and classic keeps its saved HTML (ClassicGoldenTest).
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
    $recordings = CaptureSession::query()->where('user_id', $this->owner->id)->get();
    // Filed under Qureshi Textiles, with its audio and a finished transcript.
    $this->call = $recordings->firstWhere('title', 'Payment terms for the March shipment');
    // Its transcript failed: nothing was heard.
    $this->silent = $recordings->firstWhere('title', 'Samples to the courier');
});

/** Signs the person in with the console in this language: the new design, or the classic Library inside the new frame. */
function recordingUrduAs(object $test, string $language, bool $library = true): void
{
    webParityDesign(true);
    if (! $library) {
        app(InterfaceFlags::class)->set('ui_v2.library', false, 0, [], [], 'off');
    }
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $test->actingAs($test->owner->fresh(), 'web');
    app()->setLocale($language);
}

/** Text without the invisible direction marks Urdu puts around names and numbers (B8). */
function recordingUrduBare(string $text): string
{
    return preg_replace('/[\x{2066}-\x{2069}]/u', '', $text) ?? $text;
}

/** A page as drawn, without those marks. */
function recordingUrduHtml(object $test, string $url): string
{
    return recordingUrduBare((string) $test->get($url)->assertOk()->getContent());
}

/** The Download window of the recording page, as Livewire sends it (the modal is a partial of the response). */
function recordingUrduDownload(object $test): string
{
    $page = Livewire::test(ViewCaptureSession::class, ['record' => $test->call->id])->mountAction('download');

    return recordingUrduBare(implode('', array_map('strval', (array) ($page->effects['partials'] ?? []))));
}

it('words the chips, the banners, the details card and the drawer in Urdu', function () {
    recordingUrduAs($this, 'ur');
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))
        ->toContain('aria-label="ترتیب"')
        ->toContain('aria-label="Qureshi Textiles۔ پروجیکٹ بدلیں"')
        ->toContain('aria-label="زبان: انگریزی۔ کسی اور زبان میں دوبارہ ٹرانسکرائب کریں"')
        ->toContain('title="رکھی گئی ریکارڈنگز کبھی خود بخود حذف نہیں ہوتیں"')
        ->not->toContain('Kept recordings are never deleted automatically');
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->silent->id))
        ->toContain('<strong>آڈیو نہیں۔</strong> اس ریکارڈنگ میں کچھ سنائی نہیں دیا، اس لیے چلانے یا ٹرانسکرائب کرنے کو کچھ نہیں۔')
        ->not->toContain('Nothing was heard in this recording');
    expect(recordingUrduHtml($this, '/app/sessions?selected='.$this->call->id))
        ->toContain('aria-label="بند کریں (Esc)"')->not->toContain('Close (Esc)');

    recordingUrduAs($this, 'ur', library: false);
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))->toMatch('~>\s*دورانیہ\s*</~u')->not->toMatch('~>\s*Duration\s*</~');
});

it('words the actions, their windows and their notices in Urdu', function () {
    recordingUrduAs($this, 'ur');
    $page = Livewire::test(ViewCaptureSession::class, ['record' => $this->call->id])
        ->assertActionExists('fileUnder', fn (Action $a): bool => $a->getModalHeading() === 'کسی پروجیکٹ میں رکھیں')
        ->assertActionExists('history', fn (Action $a): bool => $a->getModalHeading() === 'ٹرانسکرپٹ کا تاریخچہ'
            && $a->getModalSubmitActionLabel() === 'اصل واپس لائیں')
        ->assertActionExists('delete', fn (Action $a): bool => $a->getModalDescription() === 'ریکارڈنگ اور ٹرانسکرپٹ ہر جگہ (تمام ڈیوائسز) سے حذف ہو جاتے ہیں۔ اسے واپس نہیں لایا جا سکتا۔');

    $page->call('togglePin')->assertNotified('لائبریری میں سب سے اوپر پن ہو گئی');
    $page->callAction('replace', data: ['find' => 'proforma', 'replace' => 'pro forma'])->assertNotified('1 بار بدلا گیا');
    // The project's name keeps its own direction inside the Urdu line.
    $page->callAction('fileUnder', data: ['client_id' => $this->call->client_id])->assertNotified("\u{2068}Qureshi Textiles\u{2069} میں رکھ دی گئی");
});

it('words the Download window in Urdu', function () {
    recordingUrduAs($this, 'ur');
    expect(recordingUrduDownload($this))
        ->toContain('<h3 class="el-download-heading">آڈیو</h3>')
        ->toContain('آڈیو (.')
        ->toContain('متن (.txt)')
        ->toContain('میٹنگ پیک (پرنٹ)')
        ->toContain('PDF (پرنٹ) ایک صفحہ کھولتا ہے جسے براؤزر کے پرنٹ ڈائیلاگ سے PDF کے طور پر محفوظ کیا جا سکتا ہے۔')
        ->toContain('ٹرانسکرپٹ اور اس کی اسکرینز، ہر ایک اپنے لمحے پر، “[Screen 7 at 14:32]” کے لیبل')
        ->not->toContain('Meeting pack (print)')->not->toContain('The print page opens in a new tab');
});

it('keeps the English words exactly as they were', function () {
    recordingUrduAs($this, 'en');
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))
        ->toContain('aria-label="Organise"')
        ->toContain('aria-label="Qureshi Textiles. Change project"')
        ->toContain('aria-label="Language: English. Transcribe again in another language"')
        ->toContain('title="Kept recordings are never deleted automatically"');
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->silent->id))
        ->toContain('<strong>No audio.</strong> Nothing was heard in this recording, so there is nothing to play or transcribe. On the phone run Setup → Call capture → Test call capture; then delete this recording.</p>');
    expect(recordingUrduHtml($this, '/app/sessions?selected='.$this->call->id))->toContain('aria-label="Close (Esc)" title="Close (Esc)"');

    expect(recordingUrduDownload($this))
        ->toContain('<h3 class="el-download-heading">Audio</h3>')
        ->toContain('Text (.txt)')
        ->toContain('Meeting pack (print)')
        ->toContain('PDF (print) opens a page to save as PDF from the browser')
        ->toContain('The transcript with its screens placed at their moments, labelled “[Screen 7 at 14:32]” with what each shows.');

    $page = Livewire::test(ViewCaptureSession::class, ['record' => $this->call->id])
        ->assertActionExists('fileUnder', fn (Action $a): bool => $a->getModalHeading() === 'File under a project')
        ->assertActionExists('history', fn (Action $a): bool => $a->getModalHeading() === 'Transcript history' && $a->getModalSubmitActionLabel() === 'Restore original')
        ->assertActionExists('delete', fn (Action $a): bool => $a->getModalDescription() === 'Deletes the recording and transcript everywhere (all devices). This cannot be undone.');
    $page->call('togglePin')->assertNotified('Pinned to the top of the Library');
    $page->callAction('replace', data: ['find' => 'proforma', 'replace' => 'pro forma'])->assertNotified('Replaced 1 time');
    $page->callAction('fileUnder', data: ['client_id' => $this->call->client_id])->assertNotified('Filed under Qureshi Textiles');

    // The counted lines, as the old code wrote them.
    expect(trans_choice('Replaced 1 time|Replaced :count times', 3, ['count' => 3]))->toBe('Replaced 3 times')
        ->and(trans_choice('The transcript with its screen placed at their moments, labelled “[Screen 7 at 14:32]” with what each shows.|The transcript with its :count screens placed at their moments, labelled “[Screen 7 at 14:32]” with what each shows.', 2, ['count' => 2]))
        ->toBe('The transcript with its 2 screens placed at their moments, labelled “[Screen 7 at 14:32]” with what each shows.');

    recordingUrduAs($this, 'en', library: false);
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))->toMatch('~>\s*Duration\s*</~');
});

it('reads "With" in the details as the person the call was with, from lang/*/recording_context.php; lang/ur.json keeps its general meaning', function () {
    recordingUrduAs($this, 'ur');
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))->toContain('<dt>کس کے ساتھ</dt>')
        ->and(__('With'))->toBe('کے ساتھ');
    recordingUrduAs($this, 'ur', library: false);
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))->toMatch('~>\s*کس کے ساتھ\s*</~u');

    recordingUrduAs($this, 'en');
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))->toContain('<dt>With</dt>')
        ->and(__('recording_context.with_person'))->toBe('With');
    recordingUrduAs($this, 'en', library: false);
    expect(recordingUrduHtml($this, '/app/sessions/'.$this->call->id))->toMatch('~>\s*With\s*</~');
});
