<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\LibraryFilterBar;
use App\Models\CaptureSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\InterfaceFlags;
use App\Services\Library\LibraryFacets;
use App\Services\Library\LibraryFilters;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| BE-01 (docs/PLAN_V2_1_POLISH.md section 1, "Counts that add up"): each state has one definition on the server
| (LibraryFilters::status and ::state), and the console's places, its rows and the API count with it. "Not transcribed"
| is now "Audio only", failed recordings are in Needs you, every place's count is the number of rows it lists, and the
| rows that say "Audio only" under All are the Audio only place's count. Five seeded accounts, each with a teammate and
| another workspace beside it, and nothing of a recording in the log.
*/

uses(ApiClient::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
});

/** One recording of [$owner] in [$state], [$hoursAgo] before now, titled so it can be found in the log. */
function stateRecording(User $owner, string $state, int $hoursAgo, string $title): CaptureSession
{
    $at = now()->subHours($hoursAgo);
    $session = Fx::session($owner, null, ['title' => $title, 'started_at' => $at, 'ended_at' => $at->copy()->addMinutes(4),
        'status' => match ($state) {
            'audio_only_chosen' => SessionStatus::AudioOnly,
            'uploading' => SessionStatus::Uploading,
            'waiting' => SessionStatus::Recording,
            'failed_session' => SessionStatus::Failed,
            default => SessionStatus::Completed,
        }]);
    if ($state === 'waiting') {
        $session->update(['ended_at' => null]);
    }
    match ($state) {
        'transcribed' => Fx::transcript($session, "Words of {$title}."),
        'transcribing', 'stuck' => $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]),
        'failed_transcript' => $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error_code' => 'engine_error']),
        'failed_upload' => Fx::pendingRecording($session, 'audio')->update(['upload_status' => UploadStatus::Failed, 'received_chunks' => []]),
        default => null,
    };

    return $session;
}

/** The five seeded accounts, by what their recordings are in. */
const STATE_ACCOUNTS = [
    'every state' => ['transcribed', 'transcribed', 'audio_only_chosen', 'audio_only_kept', 'uploading', 'transcribing', 'stuck',
        'failed_transcript', 'failed_session', 'failed_upload', 'waiting'],
    'all transcribed' => ['transcribed', 'transcribed', 'transcribed'],
    'audio only' => ['audio_only_chosen', 'audio_only_kept', 'audio_only_kept', 'audio_only_chosen'],
    'failures' => ['failed_upload', 'failed_transcript', 'transcribed', 'waiting'],
    'nothing yet' => [],
];

/**
 * Each account's owner and a token of theirs, with a teammate in the owner's workspace and a stranger in another, each
 * with one recording in every state, so neither may be counted with the owner's.
 *
 * @return array<string, array{0: User, 1: string, 2: list<string>}>
 */
function stateAccounts(): array
{
    $accounts = [];
    $everyState = STATE_ACCOUNTS['every state'];
    foreach (STATE_ACCOUNTS as $name => $states) {
        $owner = Fx::user();
        $titles = [];
        foreach ($states as $i => $state) {
            $hours = $state === 'stuck' ? 30 : $i + 1;
            stateRecording($owner, $state, $hours, $titles[] = "{$name} {$state} {$i}");
        }
        CaptureSession::query()->where('user_id', $owner->id)->where('title', 'like', '%stuck%')->first()?->transcripts()->update(['created_at' => now()->subHours(30), 'updated_at' => now()->subHours(30)]);

        $teammate = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $teammate);
        $teammate->forceFill(['current_workspace_id' => $owner->current_workspace_id])->save();
        $stranger = Fx::user();
        foreach ([$teammate, $stranger] as $other) {
            foreach ($everyState as $i => $state) {
                stateRecording($other->fresh(), $state, $i + 2, "not {$name}'s {$state} {$i}");
            }
        }
        $accounts[$name] = [$owner->fresh(), Fx::token(Fx::device($owner)), $titles];
    }

    return $accounts;
}

/** The rows the console draws, one string each (spaces as one). */
function statesRows(string $html): array
{
    preg_match_all('~<div class="v2-lib-row".*?</time>~s', $html, $m);

    return array_map(fn (string $row): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags($row))), $m[0]);
}

/** The sidebar's count of each Library place, as it draws them (All by the facets, as the header says it). */
function statesPlaceCounts(User $owner): array
{
    $counts = collect(LibraryFilterBar::sidebar('/app/sessions')['places'])->pluck('count', 'key')->all();
    $counts['all'] = app(LibraryFacets::class)->libraryCounts((int) $owner->current_workspace_id, $owner->id)['all'];

    return $counts;
}

it('lists as many rows in each console place as its count says, and the Audio only rows under All are Audio only\'s count', function () {
    $expected = [
        'every state' => ['all' => 10, 'needs_you' => 5, 'audio_only' => 2],
        'all transcribed' => ['all' => 3, 'needs_you' => 0, 'audio_only' => 0],
        'audio only' => ['all' => 4, 'needs_you' => 0, 'audio_only' => 4],
        'failures' => ['all' => 3, 'needs_you' => 3, 'audio_only' => 0],
        'nothing yet' => ['all' => 0, 'needs_you' => 0, 'audio_only' => 0],
    ];
    foreach (stateAccounts() as $name => [$owner]) {
        $this->actingAs($owner, 'web');
        $counts = statesPlaceCounts($owner);
        expect(array_intersect_key($counts, $expected[$name]))->toBe($expected[$name], $name);

        foreach (['all', 'needs_you', 'unread', 'important', 'audio_only'] as $place) {
            $rows = statesRows(Livewire::withQueryParams($place === 'all' ? [] : ['tab' => $place])->test(ListCaptureSessions::class)->html());
            expect(count($rows))->toBe($counts[$place], "{$name}: {$place}");
            if ($place === 'audio_only') {
                expect(array_filter($rows, fn (string $row): bool => ! str_contains($row, 'Audio only')))->toBe([], "{$name}: every Audio only row says so");
            }
            if ($place === 'needs_you') {
                $failed = array_filter($rows, fn (string $row): bool => str_contains($row, 'Failed · Retry'));
                expect(count($failed))->toBe(app(LibraryFacets::class)->needsYou((int) $owner->current_workspace_id, $owner->id)['failed'], "{$name}: the failed are in Needs you");
            }
            if ($place === 'all') {
                $audioOnly = array_filter($rows, fn (string $row): bool => str_contains($row, 'Audio only'));
                expect(count($audioOnly))->toBe($counts['audio_only'], "{$name}: Audio only rows under All")
                    ->and(implode("\n", $rows))->not->toContain('Untranscribed')->not->toContain('Not transcribed');
            }
        }
    }
});

it('lists as many items in each API view as the Library\'s counts say, the owner\'s only', function () {
    foreach (stateAccounts() as $name => [$owner, $token, $titles]) {
        $library = $this->api($token)->getJson('/api/library/facets')->assertOk()->json('library');
        $listed = fn (array $query): array => $this->api($token)->getJson('/api/library?'.http_build_query(['limit' => 100] + $query))->assertOk()->json('data');
        $views = [
            'all' => [],
            'needs_you' => ['view' => 'needs_you'],
            'unread' => ['view' => 'unread'],
            'important' => ['important' => 1],
            'not_transcribed' => ['filters' => json_encode(['status' => ['op' => 'is', 'values' => ['not_transcribed']]])],
            'audio_only' => ['status' => 'audio_only'],
        ];
        foreach ($views as $view => $query) {
            $items = $listed($query);
            expect(count($items))->toBe($library[$view], "{$name}: {$view}");
            foreach ($items as $item) {
                expect($item['title'])->toBeIn($titles, "{$name}: {$view} lists only the owner's");
            }
        }
        // The glossary's Audio only is the Status not_transcribed: the same recordings on the API and on the console.
        $this->actingAs($owner, 'web');
        expect(statesPlaceCounts($owner)['audio_only'])->toBe($library['not_transcribed'], $name);
    }
});

it('gives every library item its one state, and the facets count each state, adding up to the total (2.0.0-r)', function () {
    $expected = [
        'every state' => ['transcribed' => 2, 'audio_only' => 2, 'transcribing' => 2, 'waiting_to_upload' => 1, 'failed' => 3, 'waiting' => 0],
        'all transcribed' => ['transcribed' => 3, 'audio_only' => 0, 'transcribing' => 0, 'waiting_to_upload' => 0, 'failed' => 0, 'waiting' => 0],
        'audio only' => ['transcribed' => 0, 'audio_only' => 4, 'transcribing' => 0, 'waiting_to_upload' => 0, 'failed' => 0, 'waiting' => 0],
        'failures' => ['transcribed' => 1, 'audio_only' => 0, 'transcribing' => 0, 'waiting_to_upload' => 0, 'failed' => 2, 'waiting' => 0],
        'nothing yet' => ['transcribed' => 0, 'audio_only' => 0, 'transcribing' => 0, 'waiting_to_upload' => 0, 'failed' => 0, 'waiting' => 0],
    ];
    foreach (stateAccounts() as $name => [$owner, $token, $titles]) {
        $answer = $this->api($token)->getJson('/api/library/facets')->assertOk()->json();
        expect($answer['states'])->toBe($expected[$name], $name)
            ->and(array_sum($answer['states']))->toBe($answer['total'], "{$name}: the states add up");

        // Each item says its state; together they are the facets' counts, and only the owner's are there.
        $items = $this->api($token)->getJson('/api/library?limit=100')->assertOk()->json('data');
        $byState = array_fill_keys(LibraryFilters::STATE_NAMES, 0);
        foreach ($items as $item) {
            expect($item['title'])->toBeIn($titles, $name)
                ->and($item['facets']['state'])->toBe(LibraryFilters::state($item['facets']['status'], $item['status']), "{$name}: {$item['title']}");
            $byState[$item['facets']['state']]++;
        }
        expect($byState)->toBe($answer['states'], $name);

        // The call waiting for the person's choice is `waiting`, in Needs you only.
        foreach ($this->api($token)->getJson('/api/library?view=needs_you&limit=100')->assertOk()->json('data') as $item) {
            expect($item['facets']['state'])->toBeIn(['failed', 'waiting', 'transcribing'], "{$name}: {$item['title']} in Needs you");
        }
    }
});

it('writes nothing of a recording to the log while it counts and lists', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    foreach (stateAccounts() as [$owner, $token]) {
        $this->api($token)->getJson('/api/library/facets')->assertOk();
        $this->api($token)->getJson('/api/library?view=needs_you')->assertOk();
        $this->actingAs($owner, 'web');
        Livewire::withQueryParams(['tab' => 'audio_only'])->test(ListCaptureSessions::class);
        $this->get('/app/sessions')->assertOk();
    }

    $text = implode("\n", $logged);
    foreach (CaptureSession::query()->withoutGlobalScopes()->pluck('title') as $title) {
        expect($text)->not->toContain((string) $title);
    }
    expect($text)->not->toContain('Words of');
});

it('uses the glossary\'s words in the console: the place, the rows and the Status filter, in English and Urdu', function () {
    [$owner] = stateAccounts()['every state'];
    $this->actingAs($owner, 'web');
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toContain('Audio only')->toContain('Transcribing…')->toContain('Failed · Retry')->toContain('Waiting to upload')
        ->not->toContain('Untranscribed')->not->toContain('Not transcribed');
    $status = collect(collect(Livewire::test(ListCaptureSessions::class)->instance()->filterPanel())->firstWhere('key', 'status')['options'])->pluck('label', 'value')->all();
    // Status `processing` holds uploads on their way and transcripts being written: named for both (In progress).
    expect($status['not_transcribed'])->toBe('Audio only')->and($status['processing'])->toBe('In progress');

    $prefs = $owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($owner->fresh(), 'web');
    $urdu = (string) $this->get('/app/sessions')->assertOk()->getContent();
    foreach (['Audio only', 'Transcribing…', 'Failed · Retry', 'Waiting to upload'] as $word) {
        expect($urdu)->toContain(__($word, [], 'ur'));
        expect(__($word, [], 'ur'))->not->toBe($word, "{$word} has its Urdu");
    }
    expect(__('In progress', [], 'ur'))->not->toBe('In progress');
});

it('shows what In progress holds from the glossary\'s states, while the Status filter keeps it (2.0.0-r)', function () {
    [$owner] = stateAccounts()['every state'];
    $this->actingAs($owner, 'web');
    $processing = fn (array $filters = []): array => collect(collect(Livewire::test(ListCaptureSessions::class)->call('applyFilters', $filters)->instance()->filterPanel())
        ->firstWhere('key', 'status')['options'])->firstWhere('value', 'processing');

    expect($processing())->toMatchArray(['label' => 'In progress', 'count' => 3, 'parts' => '2 transcribing · 1 waiting to upload'])
        ->and($processing(['status' => ['op' => 'is', 'values' => ['processing']]])['parts'])->toBe('2 transcribing · 1 waiting to upload')
        // Status is Failed: In progress counts other recordings than the list, so its parts are not given.
        ->and($processing(['status' => ['op' => 'is', 'values' => ['failed']]])['parts'])->toBeNull();
    expect((string) $this->get('/app/sessions?f=1')->getContent())->toContain('data-parts');

    $prefs = $owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($owner->fresh(), 'web');
    app()->setLocale('ur');
    expect($processing()['parts'])->toBe(trans_choice('1 transcribing|:count transcribing', 2).' · '.trans_choice('1 waiting to upload|:count waiting to upload', 1))
        ->and($processing()['parts'])->not->toContain('transcribing');
});

it('never says "Not transcribed" or "Untranscribed" to a customer: guides, help, views, every language, translated strings', function () {
    $retired = '/not transcribed|untranscribed/i';
    $files = [dirname(base_path()).'/docs/USER_GUIDE.md', ...glob(resource_path('help/*.md')) ?: [], ...glob(lang_path('*.json')) ?: []];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = (string) $file;
    }
    foreach ($files as $file) {
        expect(preg_match($retired, (string) file_get_contents($file)))->toBe(0, "{$file} still says it");
    }
    // In the code only what is translated reaches people: the old addresses (?tab=untranscribed) and values stay.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        preg_match_all('/\\b(?:__|trans_choice|trans)\\(\\s*([\'"])((?:(?!\\1).)*)\\1/s', (string) file_get_contents((string) $file), $calls);
        foreach ($calls[2] as $text) {
            expect(preg_match($retired, $text))->toBe(0, "{$file}: {$text}");
        }
    }
});
