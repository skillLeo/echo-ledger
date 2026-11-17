<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Screen;
use App\Models\Transcript;
use App\Services\Screens\ScreenAssets;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * Screen moments' names and what was said around them (v1.8 P4.2): S07_14-32_orders-page.webp, and the lines within
 * 15 seconds, the same in the API, the meeting folder and the connector.
 */

/** A screen of [$session] at [$atMs] described as [$description]. */
function assetScreen(CaptureSession $session, int $atMs, ?string $description, string $mime = 'image/webp'): Screen
{
    return Screen::query()->withoutGlobalScopes()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => $atMs,
        'width' => 1, 'height' => 1, 'trigger' => 'change', 'sha256' => hash('sha256', $atMs.$description), 'mime' => $mime, 'bytes' => 1, 'description' => $description]);
}

/** A finished final transcript of [$session] with [$segments] ([speaker, start, end, text]). */
function assetTranscript(CaptureSession $session, array $segments, TranscriptKind $kind = TranscriptKind::Final): Transcript
{
    return Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => $kind, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed,
        'language' => 'en', 'text' => implode(' ', array_column($segments, 3)), 'completed_at' => now(),
        'segments' => array_map(fn (array $s): array => ['channel' => 1, 'speaker' => $s[0], 'start_ms' => $s[1], 'end_ms' => $s[2], 'text' => $s[3], 'words' => []], $segments)]);
}

it('names a screen by its number, its moment and its description', function () {
    $session = Fx::session(Fx::user());
    expect(ScreenAssets::fileName(assetScreen($session, 872000, 'Orders page, client points at Submit'), 7, 12))->toBe('S07_14-32_orders-page-client-points-at-submit.webp')
        ->and(ScreenAssets::fileName(assetScreen($session, 3_872_000, null), 7, 120))->toBe('S007_1-04-32_screen.webp')
        ->and(ScreenAssets::fileName(assetScreen($session, 5000, 'Ein Bild: Übersicht & Kosten / 2026', 'image/png'), 1, 1))->toBe('S01_0-05_ein-bild-ubersicht-kosten-2026.png')
        // A long description: at a word, at most 40 characters, no dangling dash.
        ->and(ScreenAssets::fileName(assetScreen($session, 0, 'The invoice settings page with the tax rate dropdown open and highlighted'), 3, 9))
        ->toBe('S03_0-00_the-invoice-settings-page-with-the-tax.webp');
});

it('gives what was said within 15 seconds of a screen, speaker by speaker, at most 600 characters', function () {
    $session = Fx::session(Fx::user());
    $screen = assetScreen($session, 60000, 'Orders');
    $lines = [
        ['speaker' => 'Omar', 'start_ms' => 30000, 'end_ms' => 44999, 'text' => 'Too early.'],
        ['speaker' => 'Omar', 'start_ms' => 40000, 'end_ms' => 45000, 'text' => 'Look at this page.'],
        ['speaker' => 'Me', 'start_ms' => 61000, 'end_ms' => 63000, 'text' => 'I see the orders.'],
        ['speaker' => 'Omar', 'start_ms' => 75000, 'end_ms' => 80000, 'text' => 'Just in time.'],
        ['speaker' => 'Omar', 'start_ms' => 75001, 'end_ms' => 80000, 'text' => 'Too late.'],
    ];
    expect(ScreenAssets::said($screen, $lines))->toBe("Omar: Look at this page.\nMe: I see the orders.\nOmar: Just in time.")
        ->and(ScreenAssets::said($screen, []))->toBeNull();
    $long = ScreenAssets::said($screen, [['speaker' => 'Me', 'start_ms' => 60000, 'end_ms' => 61000, 'text' => str_repeat('word ', 300)]]);
    expect(mb_strlen((string) $long))->toBeLessThanOrEqual(600)->toBeGreaterThan(590)->and($long)->toEndWith('…');
});

it('lists each screen with its file name and what was said, from the final transcript, else the live one', function () {
    $device = Fx::device();
    $session = Fx::session($device->user, $device);
    assetScreen($session, 14000, 'The login page');
    assetScreen($session, 600000, null);
    assetTranscript($session, [['Me', 1000, 2000, 'Live words.']], TranscriptKind::Live);
    $api = $this->api(Fx::token($device));

    $live = $api->getJson("/api/sessions/{$session->id}/screens")->assertOk()->json('data');
    expect($live[0])->toMatchArray(['file_name' => 'S01_0-14_the-login-page.webp', 'said' => 'Me: Live words.'])
        ->and($live[1])->toMatchArray(['file_name' => 'S02_10-00_screen.webp', 'said' => null]);

    assetTranscript($session, [['Omar', 10000, 13000, 'Sign in here.'], ['Me', 590000, 600500, 'Nothing more.']]);
    $final = $api->getJson("/api/sessions/{$session->id}/screens")->assertOk()->json('data');
    expect($final[0]['said'])->toBe('Omar: Sign in here.')->and($final[1]['said'])->toBe('Me: Nothing more.');
});
