<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\SettingsRepository;
use App\Support\UnsureMarkers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Unsure words survive copying (v1.3, plan A4): on screen they are underlined; in copied and exported text they are
 * "[?word]" (or "word(?)", or plain), with a note line that says what the marks mean.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

function unsureTranscript(object $test): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => 'en', 'status' => 'completed']);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'language' => 'en',
        'text' => 'Me: Send the invoice to Omar', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2000, 'text' => 'Send the invoice to Omar', 'words' => [
            ['text' => 'Send', 'start_ms' => 0, 'end_ms' => 300, 'confidence' => 0.98],
            ['text' => 'the', 'start_ms' => 300, 'end_ms' => 500, 'confidence' => 0.95],
            ['text' => 'invoice', 'start_ms' => 500, 'end_ms' => 900, 'confidence' => 0.31],
            ['text' => 'to', 'start_ms' => 900, 'end_ms' => 1100, 'confidence' => 0.9],
            ['text' => 'Omar', 'start_ms' => 1100, 'end_ms' => 1500, 'confidence' => 0.44],
        ]]]]);
}

it('marks the words below the threshold, in the chosen style, and leaves [unclear] and edited lines alone', function () {
    $segment = ['text' => 'kal meeting, [unclear]!', 'words' => [['text' => 'kal', 'confidence' => 0.9], ['text' => 'meeting', 'confidence' => 0.2], ['text' => '[unclear]', 'confidence' => 0.1]]];

    expect(UnsureMarkers::mark($segment, 0.5))->toBe('kal [?meeting], [unclear]!')
        ->and(UnsureMarkers::mark($segment, 0.1))->toBe('kal meeting, [unclear]!')
        // The second "the" is the unsure one; punctuation and words without timing stay.
        ->and(UnsureMarkers::mark(['text' => 'the cat saw the dog.', 'words' => [['text' => 'the', 'confidence' => 0.9], ['text' => 'cat', 'confidence' => 0.9], ['text' => 'the', 'confidence' => 0.2], ['text' => 'dog', 'confidence' => 0.9]]], 0.5))->toBe('the cat saw [?the] dog.')
        ->and(UnsureMarkers::mark(['text' => ' Edited line ', 'words' => []], 0.5))->toBe('Edited line')
        ->and(UnsureMarkers::restyle('kal [?meeting] hai', 'suffix'))->toBe('kal meeting(?) hai')
        ->and(UnsureMarkers::restyle('kal [?meeting] hai', 'off'))->toBe('kal meeting hai')
        ->and(UnsureMarkers::note('brackets'))->toBe('Note: [?word] = the transcription is unsure about this word; [unclear] = inaudible audio.')
        ->and(UnsureMarkers::note('suffix'))->toStartWith('Note: word(?) =');
});

it('marks unsure words in TXT and DOCX exports with the note line, and gives JSON every word\'s confidence', function () {
    $transcript = unsureTranscript($this);

    $txt = $this->get("/api/transcripts/{$transcript->id}/export?format=txt")->assertOk()->getContent();
    expect($txt)->toStartWith(UnsureMarkers::note('brackets')."\n\n")->toContain('Send the [?invoice] to [?Omar]');

    $json = json_decode($this->get("/api/transcripts/{$transcript->id}/export?format=json")->assertOk()->getContent(), true);
    expect($json['transcript']['segments'][0]['text'])->toBe('Send the [?invoice] to [?Omar]')
        ->and(array_column($json['transcript']['segments'][0]['words'], 'confidence'))->toBe([0.98, 0.95, 0.31, 0.9, 0.44]);

    $docx = $this->get("/api/transcripts/{$transcript->id}/export?format=docx")->assertOk()->getContent();
    $zip = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $docx);
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);
    expect($xml)->toContain('Note: [?word] = the transcription is unsure')->toContain('Send the [?invoice] to [?Omar]');
});

it('follows the console: word(?) style, a stricter threshold, no note, or plain text', function () {
    $transcript = unsureTranscript($this);
    $settings = app(SettingsRepository::class);

    $settings->setMany(['unsure.style' => 'suffix', 'unsure.threshold' => 0.35, 'unsure.note' => false]);
    $txt = $this->get("/api/transcripts/{$transcript->id}/export?format=txt")->assertOk()->getContent();
    expect($txt)->not->toContain('Note:')->toContain('Send the invoice(?) to Omar');

    $settings->setMany(['unsure.style' => 'off', 'unsure.note' => true]);
    $txt = $this->get("/api/transcripts/{$transcript->id}/export?format=txt")->assertOk()->getContent();
    expect($txt)->not->toContain('Note:')->not->toContain('[?')->toContain('Send the invoice to Omar');
});

it('tells the apps how to mark unsure words', function () {
    app(SettingsRepository::class)->set('unsure.style', 'suffix');

    $this->getJson('/api/config')->assertOk()
        ->assertJsonPath('unsure.style', 'suffix')->assertJsonPath('unsure.threshold', 0.5)->assertJsonPath('unsure.note', true)
        ->assertJsonPath('unsure.note_text', UnsureMarkers::note('suffix'));
});

it('underlines in the console player by the console threshold, and offers Copy for AI with the style and note', function () {
    $transcript = unsureTranscript($this);
    $session = CaptureSession::query()->findOrFail($transcript->capture_session_id);

    $player = TranscriptPlayerEntry::payload($session);
    expect(array_keys(array_filter(array_column($player['segments'][0]['words'], 'u', 't'))))->toBe(['invoice', 'Omar'])
        ->and($player['unsure'])->toMatchArray(['style' => 'brackets', 'note' => true, 'note_text' => UnsureMarkers::note('brackets')]);

    app(SettingsRepository::class)->set('unsure.threshold', 0.35);
    $player = TranscriptPlayerEntry::payload($session);
    expect(array_keys(array_filter(array_column($player['segments'][0]['words'], 'u', 't'))))->toBe(['invoice']);

    $html = (string) file_get_contents(resource_path('views/filament/infolists/transcript-player.blade.php'));
    expect($html)->toContain('copy(false, true)')->toContain('Copy for AI')->toContain('lineParts(i)');
});
