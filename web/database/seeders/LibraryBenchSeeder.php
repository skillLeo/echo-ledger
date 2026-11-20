<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use App\Support\UrduText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A large library to measure the Library API against (plan B6): BENCH_PEOPLE people (20 by default) with
 * BENCH_RECORDINGS recordings each (2,500), so one person's library sits among many others as on a shared server.
 * The first person is bench@echoledger.local. The mix: most recordings transcribed, some live text, labels, bookmarks,
 * pins, keeps, important ones, opened ones, a few with screens, a few failed, waiting or in Trash.
 *
 *   php artisan db:seed --class=LibraryBenchSeeder     (local or testing only; never on a server with real people)
 *   php artisan echoledger:library-bench               (measures it)
 */
final class LibraryBenchSeeder extends Seeder
{
    public const EMAIL = 'bench@echoledger.local';

    private const LABELS = ['Invoice', 'Follow up', 'Contract', 'Urgent', 'Family', 'Supplier', 'Quote', 'Hiring',
        'Legal', 'Travel', 'Bank', 'Doctor', 'School', 'Rent', 'Ideas'];

    private const APPS = [SourceApp::WhatsApp, SourceApp::WhatsApp, SourceApp::WhatsApp, SourceApp::WhatsAppBusiness,
        SourceApp::PhoneCall, SourceApp::PhoneCall, SourceApp::VoiceNote, SourceApp::GoogleMeet, SourceApp::Zoom,
        SourceApp::MicrosoftTeams];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The library bench seeds only a local or testing database.');
        }
        $people = max(1, (int) (getenv('BENCH_PEOPLE') ?: 20));
        $perPerson = max(1, (int) (getenv('BENCH_RECORDINGS') ?: 2500));
        mt_srand(2026);

        for ($p = 0; $p < $people; $p++) {
            $user = User::query()->where('email', $p === 0 ? self::EMAIL : "bench{$p}@echoledger.local")->first()
                ?? User::factory()->create(['email' => $p === 0 ? self::EMAIL : "bench{$p}@echoledger.local", 'name' => 'Bench '.($p + 1)]);
            // The measured person's transcripts are as long as their calls; the others' stay short to keep the database small.
            // Run again, it leaves a seeded person as they are: the measured library stays the same size.
            if (DB::table('capture_sessions')->where('user_id', $user->id)->exists()) {
                $this->command?->info("{$user->email}: already seeded");

                continue;
            }
            $this->library($user, $perPerson, $p === 0);
            $this->command?->info("{$user->email}: {$perPerson} recordings");
        }
    }

    private function library(User $user, int $count, bool $full): void
    {
        $workspaceId = (int) $user->current_workspace_id;
        // A run that stopped half way left its devices, projects and colours: reuse them rather than add more.
        $device = fn (string $platform, string $name): Device => Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('name', $name)->first()
            ?? Device::factory()->create(['user_id' => $user->id, 'platform' => $platform, 'name' => $name]);
        $phone = $device('android', 'Phone');
        $laptop = $device('windows', 'Laptop');
        $clients = collect(range(1, 12))->map(fn (int $i): Client => Client::acrossPeople()->firstOrCreate(
            ['workspace_id' => $workspaceId, 'user_id' => $user->id, 'name' => "Project {$i}"],
            ['color' => '#2563eb', 'retention_days' => $i === 1 ? 14 : null],
        ))->pluck('id')->all();
        foreach (array_slice(self::LABELS, 0, 5) as $i => $label) {
            DB::table('label_colors')->upsert([['workspace_id' => $workspaceId, 'user_id' => $user->id, 'label_key' => mb_strtolower($label),
                'color' => ['#dc2626', '#16a34a', '#2563eb', '#ca8a04', '#db2777'][$i], 'created_at' => now(), 'updated_at' => now()]],
                ['workspace_id', 'user_id', 'label_key'], ['color', 'updated_at']);
        }

        $now = Carbon::now()->utc();
        foreach (array_chunk(range(1, $count), 250) as $chunk) {
            $sessions = $transcripts = $markers = $labels = $opens = $screens = [];
            foreach ($chunk as $n) {
                $id = (string) Str::ulid();
                $started = $now->copy()->subMinutes(mt_rand(5, 2 * 365 * 24 * 60));
                // Most calls are a few minutes; one in ten runs up to an hour.
                $duration = (mt_rand(1, 10) === 1 ? mt_rand(600, 3600) : mt_rand(20, 600)) * 1000;
                $roll = mt_rand(1, 100);
                $status = match (true) {
                    $roll === 1 => SessionStatus::Recording,
                    $roll === 2 => SessionStatus::Failed,
                    default => SessionStatus::Completed,
                };
                $app = self::APPS[mt_rand(0, count(self::APPS) - 1)];
                $updated = $started->copy()->addSeconds(intdiv($duration, 1000) + mt_rand(30, 86400));
                $sessions[] = [
                    'id' => $id, 'workspace_id' => $workspaceId, 'user_id' => $user->id,
                    'device_id' => $app === SourceApp::GoogleMeet || $app === SourceApp::Zoom || $app === SourceApp::MicrosoftTeams ? $laptop->id : $phone->id,
                    'client_id' => mt_rand(1, 100) <= 40 ? $clients[mt_rand(0, count($clients) - 1)] : null,
                    'client_ref' => (string) Str::uuid(),
                    'source_device' => in_array($app, [SourceApp::GoogleMeet, SourceApp::Zoom, SourceApp::MicrosoftTeams], true) ? SourceDevice::Desktop->value : SourceDevice::Phone->value,
                    'source_app' => $app->value, 'title' => mt_rand(1, 100) <= 30 ? 'Call '.$n : null,
                    'started_at' => $started, 'ended_at' => $started->copy()->addMilliseconds($duration), 'duration_ms' => $duration,
                    'status' => $status->value, 'final_provider' => 'assemblyai',
                    'pinned' => mt_rand(1, 100) <= 2, 'keep_forever' => mt_rand(1, 100) <= 5, 'important' => mt_rand(1, 100) <= 3,
                    'created_at' => $started, 'updated_at' => $updated,
                    'deleted_at' => mt_rand(1, 100) === 1 ? $updated->copy()->addHour() : null,
                ];
                if ($status === SessionStatus::Completed && mt_rand(1, 100) <= 92) {
                    $failed = mt_rand(1, 100) <= 3;
                    // About 150 words a minute, as people speak on a call.
                    $words = $full ? max(30, intdiv($duration, 400)) : mt_rand(80, 600);
                    $transcripts[] = $this->transcript($id, TranscriptKind::Final, $failed ? TranscriptStatus::Failed : TranscriptStatus::Completed,
                        $failed ? null : $words, $started, $full && mt_rand(1, 100) <= 40);
                }
                if (mt_rand(1, 100) <= 30) {
                    $transcripts[] = $this->transcript($id, TranscriptKind::Live, TranscriptStatus::Completed, $full ? max(20, intdiv($duration, 500)) : mt_rand(40, 300), $started, false);
                }
                $bookmarks = mt_rand(1, 100) <= 20 ? mt_rand(1, 3) : 0;
                for ($m = 0; $m < $bookmarks; $m++) {
                    $markers[] = ['id' => (string) Str::ulid(), 'capture_session_id' => $id, 'at_ms' => mt_rand(0, $duration), 'tag' => ['Decision', 'Price', 'Todo'][mt_rand(0, 2)], 'note' => null, 'created_at' => $started, 'updated_at' => $started];
                }
                if (mt_rand(1, 100) <= 25) {
                    foreach (array_unique([self::LABELS[mt_rand(0, 14)], self::LABELS[mt_rand(0, 14)]]) as $label) {
                        $labels[] = ['capture_session_id' => $id, 'label' => $label];
                    }
                }
                if (mt_rand(1, 100) <= 70) {
                    $opens[] = ['capture_session_id' => $id, 'user_id' => $user->id, 'opened_at' => $updated];
                }
                if ($app === SourceApp::GoogleMeet && mt_rand(1, 100) <= 30) {
                    foreach ([1, 2] as $s) {
                        $screens[] = ['id' => (string) Str::ulid(), 'workspace_id' => $workspaceId, 'capture_session_id' => $id, 'at_ms' => $s * 60000,
                            'width' => 1280, 'height' => 720, 'trigger' => 'change', 'sha256' => hash('sha256', $id.$s), 'mime' => 'image/webp',
                            'bytes' => 40000, 'created_at' => $started, 'updated_at' => $started];
                    }
                }
            }
            DB::table('capture_sessions')->insert($sessions);
            foreach ([['transcripts', $transcripts], ['session_markers', $markers], ['session_labels', $labels], ['session_opens', $opens], ['screens', $screens]] as [$table, $rows]) {
                foreach (array_chunk($rows, 200) as $part) {
                    DB::table($table)->insert($part);
                }
            }
        }
    }

    /**
     * A transcript of [$words] words in lines of about twelve, each with its time and speaker, and when [$views] the
     * Roman Urdu, English and Urdu views stored beside it as the app keeps them.
     *
     * @return array<string, mixed>
     */
    private function transcript(string $sessionId, TranscriptKind $kind, TranscriptStatus $status, ?int $words, Carbon $at, bool $views): array
    {
        $lines = [];
        for ($i = 0, $left = (int) $words; $left > 0; $i++, $left -= 12) {
            $lines[] = ['channel' => $i % 2 + 1, 'speaker' => $i % 2 === 0 ? 'Me' : 'Them', 'start_ms' => $i * 4800, 'end_ms' => $i * 4800 + 4600, 'text' => $this->words(min(12, $left))];
        }
        $text = $lines === [] ? null : implode("\n", array_column($lines, 'text'));
        $meta = $views && $lines !== [] ? ['views' => ['status' => 'ready', 'fingerprint' => sha1($sessionId), 'made_at' => $at->toAtomString(),
            'roman' => array_column($lines, 'text'), 'english' => array_column($lines, 'text'), 'urdu' => array_column($lines, 'text')]] : null;

        return [
            'id' => (string) Str::ulid(), 'capture_session_id' => $sessionId, 'kind' => $kind->value, 'provider' => $kind === TranscriptKind::Live ? 'deepgram' : 'assemblyai',
            'status' => $status->value, 'language' => 'ur', 'text' => $text, 'search_text' => $text === null ? null : UrduText::fold($text),
            'segments' => $lines === [] ? null : json_encode($lines), 'meta' => $meta === null ? null : json_encode($meta),
            'error_code' => $status === TranscriptStatus::Failed ? 'no_audio' : null,
            'created_at' => $at, 'updated_at' => $at,
        ];
    }

    private function words(int $count): string
    {
        static $pool = ['acha', 'theek', 'hai', 'payment', 'kal', 'invoice', 'bhai', 'meeting', 'price', 'order', 'delivery',
            'jee', 'nahi', 'bilkul', 'shipment', 'contract', 'office', 'call', 'karna', 'hoga', 'please', 'confirm', 'today'];
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $pool[mt_rand(0, count($pool) - 1)];
        }

        return ucfirst(implode(' ', $out)).'.';
    }
}
