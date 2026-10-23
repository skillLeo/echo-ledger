<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DevicePlatform;
use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use App\Services\RecordingIngestor;
use App\Stt\Support\WavFile;
use App\Stt\TranscriptNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo library mixing phone + desktop + web items, each with a playable two-channel tone recording
 * (left = "Me", right = "Client") and a synced transcript, so the admin player can be verified end to end.
 */
class DemoSeeder extends Seeder
{
    public function run(RecordingIngestor $ingestor): void
    {
        $user = User::query()->where('is_super_admin', true)->firstOrFail();
        if (CaptureSession::query()->where('user_id', $user->id)->exists()) {
            return; // idempotent
        }

        $acme = Client::query()->create(['user_id' => $user->id, 'name' => 'Acme Holdings', 'color' => '#8B5CF6']);
        foreach (['Acme Holdings', 'Farah Khan', 'EchoLedger', 'Q3 retainer', 'Karachi office'] as $term) {
            $acme->keyterms()->create(['term' => $term]);
        }
        $nova = Client::query()->create(['user_id' => $user->id, 'name' => 'Nova Clinics', 'color' => '#22D3EE']);
        foreach (['Nova Clinics', 'Dr. Rehaan', 'telehealth'] as $term) {
            $nova->keyterms()->create(['term' => $term]);
        }

        $phone = Device::query()->create(['user_id' => $user->id, 'name' => 'Galaxy S25 Ultra', 'platform' => DevicePlatform::Android, 'app_version' => '1.0.0', 'last_seen_at' => now()->subMinutes(5)]);
        $laptop = Device::query()->create(['user_id' => $user->id, 'name' => 'Work laptop', 'platform' => DevicePlatform::Windows, 'app_version' => '1.0.0', 'last_seen_at' => now()->subHour()]);

        $scripts = [
            [$phone, SourceApp::WhatsApp, $acme, 'Retainer check-in', [
                [1, 'Hi Farah, thanks for making time today.'], [2, 'Of course. I wanted to confirm the Q3 retainer scope.'],
                [1, 'Sure. We kept the Karachi office onboarding in phase one.'], [2, 'Perfect, Acme Holdings will sign off by Friday.'],
            ]],
            [$laptop, SourceApp::Zoom, $nova, 'Telehealth rollout', [
                [2, 'Dr. Rehaan here, can you hear me clearly?'], [1, 'Yes, perfectly. Shall we review the telehealth timeline?'],
                [2, 'Please. Nova Clinics wants two pilot sites first.'], [1, 'Great, I will send the schedule after this call.'],
            ]],
            [$laptop, SourceApp::GoogleMeet, null, 'Weekly sync', [
                [1, 'Quick status: the desktop overlay is ready for testing.'], [2, 'Nice. Is the click-through working on Windows 11?'],
                [1, 'It compiles and the hit-testing is unit tested; hardware check is next.'],
            ]],
            [$phone, SourceApp::WhatsAppBusiness, $acme, null, [
                [2, 'Following up on the invoice for September.'], [1, 'Got it, I will forward it to finance today.'],
            ]],
            [null, SourceApp::VoiceNote, null, 'Voice note from Ali', [
                [1, 'Hey, just a reminder that the client meeting moved to three PM tomorrow.'],
            ]],
        ];

        foreach ($scripts as $index => [$device, $app, $client, $title, $lines]) {
            $mono = $app === SourceApp::VoiceNote;
            $started = now()->subDays($index * 2 + 1)->setTime(10 + $index, 15);
            [$segments, $durationMs] = $this->segments($lines, $mono);

            $session = CaptureSession::query()->create([
                'user_id' => $user->id,
                'device_id' => $device?->id,
                'client_id' => $client?->id,
                'client_ref' => (string) Str::uuid(),
                'source_device' => $device?->sourceDevice() ?? SourceDevice::Web,
                'source_app' => $app,
                'title' => $title,
                'started_at' => $started,
                'ended_at' => $started->copy()->addMilliseconds($durationMs),
                'duration_ms' => $durationMs,
                'status' => SessionStatus::Completed,
                'final_provider' => 'assemblyai',
            ]);

            $wav = storage_path('app/private/tmp/demo-'.$session->id.'.wav');
            if (! is_dir(dirname($wav))) {
                mkdir(dirname($wav), 0700, true);
            }
            try {
                $this->writeToneWav($wav, $segments, $durationMs, $mono ? 1 : 2);
                $ingestor->ingestLocalFile($session, $wav, RecordingRole::Primary, 'audio/wav',
                    ['codec' => 'pcm_s16le', 'channels' => $mono ? 1 : 2, 'sample_rate' => 16000, 'duration_ms' => $durationMs]);
            } finally {
                @unlink($wav);
            }

            $session->transcripts()->create([
                'kind' => TranscriptKind::Final,
                'provider' => 'assemblyai',
                'status' => TranscriptStatus::Completed,
                'language' => 'en',
                'segments' => $segments,
                'text' => implode("\n", array_map(fn (array $s) => $s['speaker'].': '.$s['text'], $segments)),
                'meta' => ['demo' => true],
                'submitted_at' => $started,
                'completed_at' => $started->copy()->addMinutes(2),
                'provider_deleted_at' => $started->copy()->addMinutes(3),
            ]);
        }
    }

    /**
     * @param  list<array{0: int, 1: string}>  $lines
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function segments(array $lines, bool $mono): array
    {
        $cursor = 400;
        $segments = [];
        foreach ($lines as [$channel, $text]) {
            $words = [];
            foreach (explode(' ', $text) as $word) {
                $length = 180 + mb_strlen($word) * 45;
                $words[] = ['text' => $word, 'start_ms' => $cursor, 'end_ms' => $cursor + $length, 'confidence' => 0.97];
                $cursor += $length + 70;
            }
            $channel = $mono ? 1 : $channel;
            $segments[] = [
                'channel' => $channel,
                'speaker' => TranscriptNormalizer::speakerFor($channel, $mono ? 1 : 2),
                'start_ms' => $words[0]['start_ms'],
                'end_ms' => $words[count($words) - 1]['end_ms'],
                'text' => $text,
                'words' => $words,
            ];
            $cursor += 600;
        }

        return [$segments, $cursor + 400];
    }

    /**
     * Soft tones while each speaker "talks" (left = Me 330 Hz, right = Client 440 Hz) so playback is audible.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    private function writeToneWav(string $path, array $segments, int $durationMs, int $channels): void
    {
        $rate = 16000;
        $frames = (int) ($rate * $durationMs / 1000);
        $out = fopen($path, 'wb');
        if ($out === false) {
            return;
        }
        fwrite($out, WavFile::header($channels, $rate, 16, $frames * $channels * 2));
        $buffer = '';
        for ($i = 0; $i < $frames; $i++) {
            $ms = (int) ($i * 1000 / $rate);
            $sample = [0, 0];
            foreach ($segments as $s) {
                if ($ms >= $s['start_ms'] && $ms <= $s['end_ms']) {
                    $ch = $channels === 1 ? 0 : ((int) $s['channel'] - 1);
                    $freq = $ch === 0 ? 330.0 : 440.0;
                    $sample[$ch] = (int) (2400 * sin(2 * M_PI * $freq * $i / $rate));
                }
            }
            $buffer .= pack('v', $sample[0] & 0xFFFF);
            if ($channels === 2) {
                $buffer .= pack('v', $sample[1] & 0xFFFF);
            }
            if (strlen($buffer) > 65536) {
                fwrite($out, $buffer);
                $buffer = '';
            }
        }
        fwrite($out, $buffer);
        fclose($out);
    }
}
