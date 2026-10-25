<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\User;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/** @return array{0: string, 1: User, 2: list<string>, 3: User} alice token, alice, alice session ids, bob */
function secLibrary(): array
{
    $alice = Fx::user();
    $bob = Fx::user();
    $device = Fx::device($alice);
    $client = Fx::client($alice, 'Alice Client');
    $ids = [
        Fx::session($alice, $device, ['title' => 'A one', 'source_app' => 'zoom', 'client_id' => $client->id])->id,
        Fx::session($alice, $device, ['title' => 'A two', 'source_app' => 'whatsapp'])->id,
        Fx::session($alice, $device, ['title' => 'A three', 'source_app' => 'voice_note'])->id,
    ];
    Fx::session($bob, null, ['title' => 'BOB PRIVATE ONE']);
    Fx::session($bob, null, ['title' => 'BOB PRIVATE TWO']);

    return [Fx::token($device), $alice, $ids, $bob];
}

dataset('sql payloads', [
    'quote or true' => ["' OR '1'='1"],
    'comment terminator' => ["'; --"],
    'stacked drop' => ['1; DROP TABLE capture_sessions; --'],
    'union select' => ["' UNION SELECT id, password FROM users --"],
    'sleep' => ["' OR SLEEP(5) --"],
    'like wildcards' => ['%%%%%%%%%%%%%%%%%%%%%%%%%%'],
    'underscore wildcards' => ['__________________________'],
    'backslash escape' => ['\\\\\\\\\\\\\\\\\\\\\\\\\\'],
    'double quote' => ['" OR ""="'],
    'null byte' => ["abc\0def"],
    'unicode quote' => ["\u{02BC} OR \u{02BC}1\u{02BC}=\u{02BC}1"],
    'json injection' => ['{"$ne":null}'],
]);

it('treats SQL meta characters as data in every library filter', function (string $payload) {
    [$token, , $ids, $bob] = secLibrary();
    $failures = [];

    foreach (['source', 'source_app', 'client_id', 'status', 'since', 'cursor', 'limit'] as $param) {
        $response = $this->api($token)->getJson('/api/library?'.http_build_query([$param => $payload]));
        $status = $response->getStatusCode();
        if (! in_array($status, [200, 422], true)) {
            $failures[] = "{$param}: HTTP {$status}";

            continue;
        }
        if ($status === 200) {
            foreach ($response->json('data') as $item) {
                if (! in_array($item['id'], $ids, true)) {
                    $failures[] = "{$param}: returned a foreign row {$item['id']}";
                }
            }
            if (str_contains($response->getContent(), 'BOB PRIVATE')) {
                $failures[] = "{$param}: leaked another owner's data";
            }
        }
    }

    expect($failures)->toBe([]);
    expect(CaptureSession::query()->count())->toBe(5)->and(User::query()->count())->toBe(2);
})->with('sql payloads');

it('matches client_id exactly and never as a LIKE pattern', function () {
    [$token, , $ids] = secLibrary();

    $this->api($token)->getJson('/api/library?client_id='.str_repeat('%', 26))->assertOk()->assertJsonPath('data', []);
    $this->api($token)->getJson('/api/library?client_id='.str_repeat('_', 26))->assertOk()->assertJsonPath('data', []);
    $this->api($token)->getJson('/api/library?client_id=%')->assertStatus(422);
    expect($this->api($token)->getJson('/api/library')->json('data'))->toHaveCount(3);
});

it('returns nothing for a source filter made only of unknown values', function () {
    [$token] = secLibrary();

    foreach (['%', "phone' OR '1'='1", 'PHONE', ',,,', 'phone;web', '*'] as $source) {
        $count = count($this->api($token)->getJson('/api/library?source='.urlencode($source))->assertOk()->json('data'));
        expect($count)->toBe(0, "source={$source}");
    }
    expect(count($this->api($token)->getJson('/api/library?source=phone,desktop,web')->json('data')))->toBe(3);
});

it('pages through exactly the owner\'s items without duplicates or gaps', function () {
    [$token, , $ids] = secLibrary();
    $seen = [];
    $cursor = null;

    for ($page = 0; $page < 10; $page++) {
        $query = 'limit=1'.($cursor ? '&cursor='.urlencode($cursor) : '');
        $response = $this->api($token)->getJson('/api/library?'.$query)->assertOk();
        array_push($seen, ...array_column($response->json('data'), 'id'));
        $cursor = $response->json('next_cursor');
        if ($cursor === null) {
            break;
        }
    }

    sort($seen);
    $expected = $ids;
    sort($expected);
    expect($seen)->toBe($expected);
});

it('survives hand-crafted pagination cursors and never serves another owner\'s rows through them', function () {
    [$token, , $ids, $bob] = secLibrary();
    $bobSessionId = CaptureSession::query()->where('user_id', $bob->id)->value('id');
    $crafted = [
        'empty' => [],
        'unrelated keys' => ['foo' => 'bar'],
        'missing id' => ['updated_at' => '2026-01-01 00:00:00'],
        'sql in value' => ['updated_at' => "x' OR 1=1 --", 'id' => "1' OR '1'='1", '_pointsToNextItems' => true],
        'array values' => ['updated_at' => ['a'], 'id' => ['b'], '_pointsToNextItems' => true],
        'object values' => ['updated_at' => ['k' => 'v'], 'id' => (object) ['k' => 'v'], '_pointsToNextItems' => true],
        'bob pointer' => ['updated_at' => '2999-01-01 00:00:00', 'id' => $bobSessionId, '_pointsToNextItems' => true],
        'bob pointer backwards' => ['updated_at' => '1970-01-01 00:00:00', 'id' => $bobSessionId, '_pointsToNextItems' => false],
        'huge numbers' => ['updated_at' => PHP_INT_MAX, 'id' => PHP_INT_MAX, '_pointsToNextItems' => true],
        'non boolean direction' => ['updated_at' => '2026-01-01', 'id' => 'x', '_pointsToNextItems' => 'yes'],
    ];
    $failures = [];

    foreach ($crafted as $label => $parameters) {
        $cursor = rtrim(strtr(base64_encode(json_encode($parameters, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $response = $this->api($token)->getJson('/api/library?cursor='.$cursor);
        if ($response->getStatusCode() >= 500) {
            config(['app.debug' => true]);
            $detail = $this->api($token)->getJson('/api/library?cursor='.$cursor)->json();
            config(['app.debug' => false]);
            $failures[] = "{$label}: HTTP ".$response->getStatusCode().' ['.($detail['exception'] ?? '').': '.mb_substr((string) ($detail['message'] ?? ''), 0, 90).']';

            continue;
        }
        if (str_contains($response->getContent(), 'BOB PRIVATE') || str_contains($response->getContent(), (string) $bobSessionId)) {
            $failures[] = "{$label}: leaked another owner's row";
        }
    }

    expect($failures)->toBe([]);
});

it('survives a hand-crafted pagination cursor whose fields are null', function () {
    [$token, , , $bob] = secLibrary();
    $bobSessionId = (string) CaptureSession::query()->where('user_id', $bob->id)->value('id');
    $cursor = rtrim(strtr(base64_encode(json_encode(['updated_at' => null, 'id' => null, '_pointsToNextItems' => false], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    $response = $this->api($token)->getJson('/api/library?cursor='.$cursor);

    expect($response->getStatusCode())->toBeLessThan(500);
    expect($response->getContent())->not->toContain('BOB PRIVATE')->not->toContain($bobSessionId);
});

it('rejects since values that are not real dates and never throws on extreme ones', function () {
    [$token] = secLibrary();
    $failures = [];

    foreach (['yesterday', 'now', '@0', '0', '0000-00-00', '9999-12-31', '99999-01-01', '2026-02-30', '2026-13-45', '1e10', '-1', 'NaN', "2026-01-01\0", '2026-01-01T25:61:61Z', str_repeat('9', 400)] as $since) {
        $status = $this->api($token)->getJson('/api/library?since='.urlencode($since))->getStatusCode();
        if ($status >= 500) {
            $failures[] = "since={$since} => {$status}";
        }
    }

    expect($failures)->toBe([]);
});

it('bounds the page size', function () {
    [$token] = secLibrary();

    foreach (['0', '-1', '101', '1e2', 'abc', '99999999999999999999', '1.5', '5;DROP'] as $limit) {
        $this->api($token)->getJson('/api/library?limit='.urlencode($limit))->assertStatus(422);
    }
    $this->api($token)->getJson('/api/library?limit=100')->assertOk();
    $this->api($token)->getJson('/api/library?limit[]=5')->assertStatus(422);
});
