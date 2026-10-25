<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\LibrarySearch;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "GET /api/search" (since v1.1): titles and transcripts, filters, a hit per matching segment with a
 * highlighted snippet, paging, and the LIKE engine used by SQLite (MySQL adds the FULLTEXT index).
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
});

/** @param list<array{0: string, 1: string, 2?: int}> $lines speaker, text, start */
function searchable(object $test, string $title, array $lines, array $session = [], TranscriptKind $kind = TranscriptKind::Final): CaptureSession
{
    $model = CaptureSession::factory()->create(array_merge([
        'user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'title' => $title,
        'status' => SessionStatus::Completed, 'started_at' => now()->subHour(),
    ], $session));
    $segments = [];
    foreach ($lines as $i => $line) {
        $start = $line[2] ?? $i * 5000;
        $segments[] = ['channel' => $line[0] === 'Client' ? 2 : 1, 'speaker' => $line[0], 'start_ms' => $start, 'end_ms' => $start + 4000, 'text' => $line[1]];
    }
    $model->transcripts()->create([
        'kind' => $kind, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed,
        'text' => implode("\n", array_map(fn (array $s) => $s['speaker'].': '.$s['text'], $segments)), 'segments' => $segments,
    ]);

    return $model;
}

it('finds the moment in a transcript with a highlighted snippet', function () {
    $hit = searchable($this, 'Acme kickoff', [['Me', 'Hello there.'], ['Client', 'The invoice is due on Friday.', 65_000]]);
    searchable($this, 'Standup', [['Me', 'Nothing about money.']]);

    $response = $this->getJson('/api/search?q=invoice')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.session.id'))->toBe($hit->id)
        ->and($response->json('data.0.hit_count'))->toBe(1)
        ->and($response->json('data.0.hits.0.start_ms'))->toBe(65_000)
        ->and($response->json('data.0.hits.0.speaker'))->toBe('Client')
        ->and($response->json('data.0.hits.0.segment_index'))->toBe(1)
        ->and($response->json('data.0.hits.0.snippet'))->toBe('The invoice is due on Friday.')
        ->and($response->json('data.0.hits.0.highlights'))->toBe([[4, 7]])
        ->and($response->json('engine'))->toBe('like');
});

it('needs every word, keeps quoted phrases together and ignores case', function () {
    $both = searchable($this, 'A', [['Client', 'Send the INVOICE before the deadline.']]);
    searchable($this, 'B', [['Client', 'The invoice is late.']]);

    expect(collect($this->getJson('/api/search?q=invoice+deadline')->json('data'))->pluck('session.id')->all())->toBe([$both->id]);
    expect($this->getJson('/api/search?q="invoice is late"')->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/search?q="late invoice"')->json('data'))->toHaveCount(0);
});

it('matches titles too, without hits', function () {
    $titled = searchable($this, 'Quarterly roadmap', [['Me', 'Hello.']]);

    $response = $this->getJson('/api/search?q=roadmap')->assertOk();

    expect($response->json('data.0.session.id'))->toBe($titled->id)
        ->and($response->json('data.0.hits'))->toBe([]);
});

it('treats % and _ literally', function () {
    searchable($this, 'A', [['Me', 'We agreed on 50% upfront.']]);
    searchable($this, 'B', [['Me', 'Fifty upfront.']]);

    expect($this->getJson('/api/search?q='.urlencode('50%'))->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/search?q='.urlencode('a_b'))->json('data'))->toHaveCount(0);
});

it('finds Urdu and Hindi text', function () {
    searchable($this, 'Urdu call', [['Client', 'قیمت ٹھیک ہے، کل بات کریں گے']]);
    searchable($this, 'Hindi call', [['Client', 'कीमत ठीक है']]);

    expect($this->getJson('/api/search?q='.urlencode('قیمت'))->json('data.0.session.title'))->toBe('Urdu call');
    expect($this->getJson('/api/search?q='.urlencode('कीमत'))->json('data.0.session.title'))->toBe('Hindi call');
});

it('applies the filters', function () {
    $client = Client::factory()->create(['user_id' => $this->device->user_id]);
    $match = searchable($this, 'Match', [['Me', 'budget talk']], ['client_id' => $client->id, 'source_app' => 'zoom', 'started_at' => Carbon::parse('2026-10-20 10:00')]);
    $match->markers()->create(['at_ms' => 1, 'tag' => 'Budget', 'source' => 'device']);
    searchable($this, 'Other client', [['Me', 'budget talk']], ['source_app' => 'zoom', 'started_at' => Carbon::parse('2026-10-20 10:00')]);
    searchable($this, 'Too old', [['Me', 'budget talk']], ['client_id' => $client->id, 'source_app' => 'zoom', 'started_at' => Carbon::parse('2026-09-01 10:00')]);

    $ids = fn (string $query) => collect($this->getJson('/api/search?q=budget&'.$query)->assertOk()->json('data'))->pluck('session.id')->all();

    expect($ids("client_id={$client->id}&from=2026-10-01&to=2026-10-31"))->toBe([$match->id])
        ->and($ids('tag=Budget'))->toBe([$match->id])
        ->and($ids('bookmarked=1'))->toBe([$match->id])
        ->and($ids('source_app=whatsapp'))->toBe([])
        ->and($ids('source_app=zoom'))->toHaveCount(3);
});

it('pages through results newest first', function () {
    foreach (range(1, 5) as $i) {
        searchable($this, "Call {$i}", [['Me', 'contract review']], ['started_at' => now()->subDays($i)]);
    }

    $first = $this->getJson('/api/search?q=contract&limit=2')->assertOk();
    $last = $this->getJson('/api/search?q=contract&limit=2&page=3')->assertOk();

    expect(collect($first->json('data'))->pluck('session.title')->all())->toBe(['Call 1', 'Call 2'])
        ->and($first->json('next_page'))->toBe(2)
        ->and(collect($last->json('data'))->pluck('session.title')->all())->toBe(['Call 5'])
        ->and($last->json('next_page'))->toBeNull();
});

it('reports a live caption only once when the final transcript says the same', function () {
    $session = searchable($this, 'Call', [['Client', 'Ship it on Monday.']]);
    $session->transcripts()->create([
        'kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x',
        'segments' => [['channel' => 2, 'speaker' => 'Client', 'start_ms' => 0, 'end_ms' => 4000, 'text' => 'Ship it on Monday.']],
    ]);

    $response = $this->getJson('/api/search?q=monday')->assertOk();

    expect($response->json('data.0.hit_count'))->toBe(1)
        ->and($response->json('data.0.hits.0.kind'))->toBe('final');
});

it('shows at most three hits per recording with the total count', function () {
    searchable($this, 'Long call', array_map(fn (int $i) => ['Me', "price point {$i}"], range(1, 6)));

    $response = $this->getJson('/api/search?q=price')->assertOk();

    expect($response->json('data.0.hits'))->toHaveCount(3)->and($response->json('data.0.hit_count'))->toBe(6);
});

it('never shows other owners, deleted or still-recording sessions', function () {
    [$other] = $this->enroll();
    searchable((object) ['device' => $other], 'Foreign', [['Me', 'secret plan']]);
    searchable($this, 'Deleted', [['Me', 'secret plan']])->delete();
    searchable($this, 'Open', [['Me', 'secret plan']], ['status' => SessionStatus::Recording]);

    expect($this->getJson('/api/search?q=secret')->assertOk()->json('data'))->toBe([]);
});

it('validates the query', function (string $query) {
    $this->getJson('/api/search?'.$query)->assertUnprocessable();
})->with([
    'missing' => [''],
    'one character' => ['q=a'],
    'too long' => ['q='.str_repeat('a', 201)],
    'bad date' => ['q=ab&from=20-10-2026'],
    'to before from' => ['q=ab&from=2026-10-20&to=2026-10-01'],
    'limit' => ['q=ab&limit=51'],
]);

it('answers nothing for a query made only of operators', function () {
    $this->getJson('/api/search?q='.urlencode('"" ++'))->assertOk()->assertJsonPath('data', []);
});

describe('snippets and terms', function () {
    it('cuts long text around the first match and keeps the highlight positions right', function () {
        $text = str_repeat('word ', 60).'the invoice arrived '.str_repeat('more ', 60);
        [$snippet, $ranges] = LibrarySearch::snippet($text, ['invoice']);

        expect(mb_strlen($snippet))->toBeLessThanOrEqual(LibrarySearch::SNIPPET_CHARS + 2)
            ->and($snippet)->toStartWith('…')->and($snippet)->toEndWith('…')
            ->and(mb_substr($snippet, $ranges[0][0], $ranges[0][1]))->toBe('invoice');
    });

    it('splits terms, keeps phrases and drops duplicates and operators', function () {
        expect(LibrarySearch::terms('Invoice "due date" invoice +friday -'))->toBe(['Invoice', 'due date', 'friday'])
            ->and(LibrarySearch::booleanQuery(['invoice', 'due date']))->toBe('+invoice +"due date"');
    });
});
