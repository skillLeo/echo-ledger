<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Views\TranscriptViews;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Fills the demo library (DemoSeeder) with what the console's pages show when they are in use: a client workspace
 * (brief, memory, call notes, files), a suggested fix, a call waiting for Keep or Discard, a failed transcription and
 * an Urdu call with its Show as views. For local screenshots and walk-throughs only:
 * `php artisan db:seed --class=ConsoleTourSeeder` after `php artisan db:seed`.
 */
class ConsoleTourSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('is_super_admin', true)->firstOrFail();
        $acme = Client::query()->where('user_id', $user->id)->where('name', 'Acme Holdings')->first();
        $checkIn = CaptureSession::query()->where('user_id', $user->id)->where('title', 'Retainer check-in')->first();
        if ($acme === null || $checkIn === null || ClientBrief::query()->where('client_id', $acme->id)->exists()) {
            return; // needs the demo library, and runs once
        }
        $workspace = $acme->workspace_id;

        $acme->update(['color' => '#12A594', 'replacements' => [['from' => 'acme limited', 'to' => 'Acme Holdings', 'match_case' => false, 'whole_word' => true]]]);
        Client::query()->where('user_id', $user->id)->where('name', 'Nova Clinics')->update(['color' => '#3E63DD']);

        ClientBrief::query()->create([
            'workspace_id' => $workspace, 'client_id' => $acme->id, 'status' => 'ready', 'edited' => false, 'model' => 'claude-haiku-5-5', 'generated_at' => now()->subDay(),
            'sections' => ClientBrief::clean([
                'goal' => 'Onboard the Karachi office onto the Q3 retainer and ship the client portal before the year ends.',
                'scope' => ['Karachi office onboarding (phase one)', 'Client portal with invoices and call notes', 'Monthly report to Farah Khan'],
                'constraints' => ['No work on weekends', 'Invoices in PKR and USD'],
                'deadlines' => ['Sign-off by Friday', 'Portal beta on Dec 15'],
                'budget' => ['Q3 retainer: $4,800 a month'],
                'people' => ['Farah Khan: operations lead, signs off', 'Omar: finance'],
                'glossary' => ['Phase one: the Karachi office only'],
            ]),
        ]);

        // The check-in's transcript with a word the engine likely misheard, and the fix the client's words suggest.
        $final = $checkIn->finalTranscript()->firstOrFail();
        $segments = $final->segments ?? [];
        if (isset($segments[2])) {
            $segments[2]['text'] = 'Sure. We kept the Karachi office on boarding in phase one.';
            unset($segments[2]['words']);
        }
        $final->forceFill(['segments' => $segments, 'meta' => ['demo' => true, 'suggestions' => [
            ['line' => 2, 'from' => 'on boarding', 'to' => 'onboarding', 'reason' => 'As written in the brief', 'status' => 'open'],
        ]]])->save();

        $notes = [
            'asked_for' => [['text' => 'Confirm the Q3 retainer scope in writing', 'verify' => false, 'lines' => [1], 'at_ms' => (int) ($segments[1]['start_ms'] ?? 0)]],
            'decisions' => [['text' => 'Karachi office onboarding stays in phase one', 'verify' => false, 'lines' => [2], 'at_ms' => (int) ($segments[2]['start_ms'] ?? 0)]],
            'to_confirm' => [['text' => 'Sign-off by Friday', 'verify' => true, 'lines' => [3], 'at_ms' => (int) ($segments[3]['start_ms'] ?? 0)]],
            'my_actions' => [['text' => 'Send the updated scope to Farah', 'verify' => false, 'lines' => [1, 2], 'at_ms' => (int) ($segments[1]['start_ms'] ?? 0)]],
        ];
        CallNote::query()->create(['workspace_id' => $workspace, 'client_id' => $acme->id, 'capture_session_id' => $checkIn->id, 'status' => 'ready', 'language' => 'en', 'model' => 'claude-haiku-5-5', 'sections' => $notes]);

        $day = $checkIn->started_at->toDateString();
        foreach ([
            ['decision', 'Karachi office onboarding stays in phase one', 'call', (int) ($segments[2]['start_ms'] ?? 0), true],
            ['decision', 'Invoices go out in PKR and USD', 'user', null, false],
            ['question', 'Sign-off by Friday (verify)', 'call', (int) ($segments[3]['start_ms'] ?? 0), false],
            ['change', 'Added: monthly report to Farah Khan', 'call', (int) ($segments[1]['start_ms'] ?? 0), false],
            ['scope', 'Client portal with invoices and call notes', 'user', null, false],
        ] as [$kind, $text, $source, $at, $pinned]) {
            ClientMemoryItem::query()->create(['workspace_id' => $workspace, 'client_id' => $acme->id, 'kind' => $kind, 'text' => $text, 'happened_on' => $day,
                'pinned' => $pinned, 'source_type' => $source, 'source_id' => $source === 'call' ? $checkIn->id : null, 'at_ms' => $at]);
        }

        ClientFile::query()->create(['workspace_id' => $workspace, 'user_id' => $user->id, 'client_id' => $acme->id, 'name' => 'Statement of work.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 482_133, 'path' => 'client-files/demo/statement-of-work.elr', 'status' => 'ready', 'pages' => 6,
            'text' => 'Statement of work for the Q3 retainer.']);
        ClientFile::query()->create(['workspace_id' => $workspace, 'user_id' => $user->id, 'client_id' => $acme->id, 'name' => 'Scope from the email',
            'mime' => 'text/plain', 'size_bytes' => 1_204, 'path' => null, 'status' => 'ready', 'text' => 'Phase one covers the Karachi office only.']);

        // A WhatsApp call still waiting for Keep or Discard on the phone.
        CaptureSession::query()->create(['user_id' => $user->id, 'client_ref' => (string) Str::uuid(), 'source_device' => SourceDevice::Phone,
            'source_app' => SourceApp::WhatsApp, 'started_at' => now()->subHours(2), 'duration_ms' => 125_000, 'status' => SessionStatus::Recording]);

        // A phone call whose transcription failed (the activity feed posts "Transcription failed" for it).
        $failed = CaptureSession::query()->create(['user_id' => $user->id, 'client_ref' => (string) Str::uuid(), 'source_device' => SourceDevice::Phone,
            'source_app' => SourceApp::PhoneCall, 'caller_number' => '+92 300 1234567', 'started_at' => now()->subHours(5), 'duration_ms' => 61_000, 'status' => SessionStatus::Failed]);
        Transcript::query()->create(['capture_session_id' => $failed->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai',
            'status' => TranscriptStatus::Failed, 'error' => 'The engine could not be reached. Try again in a minute.']);

        // An Urdu call with its Roman Urdu, اردو and English views made.
        $urdu = CaptureSession::query()->create(['user_id' => $user->id, 'client_id' => $acme->id, 'client_ref' => (string) Str::uuid(), 'source_device' => SourceDevice::Phone,
            'source_app' => SourceApp::WhatsApp, 'title' => 'Invoice questions', 'started_at' => now()->subDays(12), 'duration_ms' => 9_000, 'status' => SessionStatus::Completed]);
        $lines = [
            [1, 'Me', 0, 'آپ کیسے ہیں؟', 'Aap kaise hain?', 'How are you?'],
            [2, 'Client', 3_000, 'میں ٹھیک ہوں، invoice جمعہ تک بھیج دوں گا', 'Main theek hoon, invoice Jumma tak bhej doon ga', 'I am fine, I will send the invoice by Friday'],
        ];
        $transcript = Transcript::query()->create(['capture_session_id' => $urdu->id, 'kind' => TranscriptKind::Final, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Completed,
            'language' => 'ur', 'segments' => array_map(fn (array $l): array => ['channel' => $l[0], 'speaker' => $l[1], 'start_ms' => $l[2], 'end_ms' => $l[2] + 2_500, 'text' => $l[3]], $lines)]);
        $transcript->forceFill(['meta' => ['views' => [
            'status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($transcript), 'model' => 'claude-haiku-5-5', 'made_at' => now()->toAtomString(), 'kept' => [],
            'roman' => array_column($lines, 4), 'urdu' => array_column($lines, 3), 'english' => array_column($lines, 5),
        ]]])->save();
    }
}
