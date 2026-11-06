<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\RecordingIngestor;
use App\Services\Views\TranscriptConverter;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| The answers this server really gives, kept in docs/api-samples: the phone (data/ApiSamplesTest.kt) and the desktop
| (tests/api_samples.rs) read these same files with their own models. Test fakes are written by hand and repeat what
| their author believes, so a wrong type survived them (workspace.id as text on the phone stopped every sign-in).
|
| This test walks an app's day against the real routes and checks each answer still has the shape of its file: the same
| keys holding the same kinds of value. After a deliberate change of the API:
|   UPDATE_API_SAMPLES=1 php vendor/bin/pest tests/Feature/Api/ApiSamplesTest.php
| and the two apps' tests then say whether they still read it.
*/

/** What kind of value this is: a list's elements together, an object's keys, a scalar's type. Numbers are one kind. */
function sampleShape(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        return get_object_vars($value) === [] ? 'empty object' : array_map('sampleShape', get_object_vars($value));
    }
    if (is_array($value)) {
        if ($value === []) {
            return 'empty';
        }
        if (array_is_list($value)) {
            return ['[]' => array_reduce($value, fn (mixed $all, mixed $item): mixed => mergeShapes($all, sampleShape($item)), null)];
        }

        return array_map('sampleShape', $value);
    }

    return match (true) {
        $value === null => null,
        is_bool($value) => 'bool',
        is_int($value), is_float($value) => 'number',
        default => 'string',
    };
}

/** One shape holding both, for a list whose elements differ: a key set on either, a value known on either. */
function mergeShapes(mixed $a, mixed $b): mixed
{
    if ($a === null || $a === 'empty') {
        return $b;
    }
    if ($b === null || $b === 'empty') {
        return $a;
    }
    if (is_array($a) && is_array($b)) {
        foreach ($b as $key => $value) {
            $a[$key] = array_key_exists($key, $a) ? mergeShapes($a[$key], $value) : $value;
        }
    }

    return $a;
}

/**
 * Where two shapes disagree: a missing or new key, or a different kind of value. A null or an empty list says nothing
 * about the kind, so it matches anything; dynamic maps are the caller's to skip.
 *
 * @return list<string>
 */
function shapeDifferences(mixed $old, mixed $new, string $at = ''): array
{
    if ($old === $new) {
        return [];
    }
    if ($old === null || $new === null || $old === 'empty' || $new === 'empty') {
        return ($old === 'empty' && $new === 'empty object') || ($old === 'empty object' && $new === 'empty')
            ? ["{$at}: an empty list and an empty object are different answers"]
            : [];
    }
    if ($old === 'empty object' || $new === 'empty object') {
        return is_array($old) || is_array($new) ? [] : ["{$at}: was ".json_encode($old).', now '.json_encode($new)];
    }
    if (is_array($old) !== is_array($new)) {
        return ["{$at}: was ".json_encode($old).', now '.json_encode($new)];
    }
    if (! is_array($old)) {
        return $old === $new ? [] : ["{$at}: was {$old}, now {$new}"];
    }
    $found = [];
    foreach (array_diff_key($old, $new) as $key => $_) {
        $found[] = "{$at}.{$key}: gone";
    }
    foreach (array_diff_key($new, $old) as $key => $_) {
        $found[] = "{$at}.{$key}: new";
    }
    foreach (array_intersect_key($old, $new) as $key => $value) {
        array_push($found, ...shapeDifferences($value, $new[$key], "{$at}.{$key}"));
    }

    return $found;
}

/** Nothing that changes per run or could read as a credential stays in a sample. */
function cleanSample(mixed $value, ?string $key = null): mixed
{
    if ($value instanceof stdClass) {
        $out = new stdClass;
        foreach (get_object_vars($value) as $k => $item) {
            $out->{$k} = cleanSample($item, $k);
        }

        return $out;
    }
    if (is_array($value)) {
        return array_map(fn (mixed $item): mixed => cleanSample($item), $value);
    }
    if (is_string($value)) {
        if ($key !== null && preg_match('/token|secret|password|signature|hash/i', $key) === 1) {
            return 'sample-'.$key;
        }

        return preg_replace('/([?&](?:signature|expires|token)=)[^&"]+/', '$1sample', $value);
    }

    return $value;
}

it('keeps the shape of every answer the apps read', function () {
    $dir = dirname(__DIR__, 4).'/docs/api-samples';
    $update = (bool) getenv('UPDATE_API_SAMPLES');
    $seen = [];
    $differences = [];

    $record = function (string $name, TestResponse $response) use ($dir, $update, &$seen, &$differences): array {
        if ($response->status() >= 300) {
            $differences[] = "{$name}: answered {$response->status()} ".substr((string) $response->getContent(), 0, 400);
            if ($update) {
                fwrite(STDERR, end($differences)."\n");
            }

            return [];
        }
        $body = (array) $response->json();
        // Read again as objects: an empty {} and an empty [] are different answers to the apps.
        $real = json_decode((string) $response->getContent());
        $seen[] = $name;
        $file = "{$dir}/{$name}.json";
        if ($update) {
            file_put_contents($file, json_encode(cleanSample($real), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        } elseif (! is_file($file)) {
            $differences[] = "{$name}: no sample yet";
        } else {
            array_push($differences, ...array_map(
                fn (string $d): string => "{$name}{$d}",
                shapeDifferences(sampleShape(json_decode((string) file_get_contents($file))), sampleShape($real)),
            ));
        }

        return $body;
    };

    Fx::fakeRecordingsDisk();
    Http::fake(['*' => Http::response(['token' => 'tmp_tok_abc', 'api_key' => 'tmp_key', 'expires_in_seconds' => 300], 200)]);
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'provider-key-for-the-test');
    app(CredentialVault::class)->put('soniox', 'api_key', 'provider-key-for-the-test');
    app(CredentialVault::class)->put('anthropic', 'api_key', 'provider-key-for-the-test');
    require_once __DIR__.'/Support/client_workspace_helpers.php';
    fakeClientAi();
    app()->instance(TranscriptConverter::class, new class implements TranscriptConverter
    {
        public function convert(array $lines, string $language, array $views): array
        {
            return [
                'lines' => array_map(fn (string $t): array => array_fill_keys($views, $t), $lines),
                'input_tokens' => 10, 'output_tokens' => 20, 'model' => 'claude-opus-5-5',
            ];
        }
    });

    $me = Fx::superAdmin(['email' => 'sample@example.test', 'name' => 'Sample Owner', 'password' => 'Sample-password-1234']);
    app(DemoSeeder::class)->run(app(RecordingIngestor::class));
    $teammate = User::factory()->create(['email' => 'teammate@example.test']);
    $teammate->workspaces()->syncWithoutDetaching([$me->current_workspace_id => ['role' => 'member']]);

    // Signing in.
    $signedIn = $record('auth_password', $this->api()->postJson('/api/auth/password', [
        'email' => 'sample@example.test', 'password' => 'Sample-password-1234', 'name' => 'Sample phone',
        'platform' => 'android', 'app_version' => '1.5.0', 'os_version' => '14',
    ]));
    $token = $signedIn['token'];
    $api = fn () => $this->api($token);

    $record('config', $api()->getJson('/api/config'));
    $record('account', $api()->getJson('/api/account'));
    $record('storage', $api()->getJson('/api/storage'));
    $record('security', $api()->getJson('/api/security'));

    // The library, with the demo recordings and their transcripts.
    $record('library', $api()->getJson('/api/library?limit=100'));
    $record('library_folder', $api()->getJson('/api/library?folder=whatsapp&limit=100'));
    $record('search', $api()->getJson('/api/search?q=retainer'));
    $record('clients', $api()->getJson('/api/clients'));
    $record('activity', $api()->getJson('/api/activity?limit=20'));

    $session = unscoped(fn () => CaptureSession::query()->whereHas('transcripts')->firstOrFail());
    $transcript = unscoped(fn () => Transcript::query()->where('capture_session_id', $session->id)->firstOrFail());
    $record('session_update', $api()->patchJson("/api/sessions/{$session->id}", ['title' => 'Retainer check-in, renamed', 'tags' => ['follow-up']]));
    $record('sessions_bulk', $api()->postJson('/api/sessions/bulk', ['ids' => [$session->id], 'action' => 'label', 'label' => 'Sample']));
    $record('library_views', $api()->getJson('/api/library/views'));
    $record('session_marker', $api()->postJson("/api/sessions/{$session->id}/markers", ['at_ms' => 1000, 'tag' => 'Decision', 'note' => 'They agreed.']));
    $record('session_speakers', $api()->putJson("/api/sessions/{$session->id}/speakers", ['names' => ['Me' => 'Ali']]));
    $api()->postJson("/api/sessions/{$session->id}/opened")->assertSuccessful();
    unscoped(function () use ($session, $transcript): void {
        $session->update(['pauses' => [['at_ms' => 1000, 'resumed_at_ms' => 2000]], 'language_marks' => [['at_ms' => 0, 'language' => 'ur']]]);
        $transcript->update(['meta' => array_merge($transcript->meta ?? [], ['suggestions' => [['line' => 0, 'from' => 'Omer', 'to' => 'Omar', 'reason' => 'name', 'status' => 'open']]])]);
        Screen::query()->create([
            'workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => 5000, 'width' => 1280, 'height' => 720,
            'trigger' => 'change', 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg', 'image_path' => 'screens/sample.jpg', 'thumb_path' => 'screens/sample-thumb.jpg', 'bytes' => 1000, 'stored_bytes' => 1000, 'ocr_text' => 'A slide', 'description' => 'A slide with a price.', 'described_at' => now(),
        ]);
    });
    $record('session', $api()->getJson("/api/sessions/{$session->id}"));
    $record('transcript', $api()->getJson("/api/transcripts/{$transcript->id}"));
    $record('transcript_views_make', $api()->postJson("/api/transcripts/{$transcript->id}/views"));
    $record('transcript_views', $api()->getJson("/api/transcripts/{$transcript->id}/views"));
    $record('transcript_suggestion', $api()->postJson("/api/transcripts/{$transcript->id}/suggestions/0", ['accept' => true]));
    $record('session_screens', $api()->getJson("/api/sessions/{$session->id}/screens"));
    $record('session_notes_make', $api()->postJson("/api/sessions/{$session->id}/notes"));
    $record('session_notes', $api()->getJson("/api/sessions/{$session->id}/notes"));

    // A recording made on this device: an open session, its artifact, the chunks, complete.
    $open = $record('session_open', $api()->postJson('/api/sessions', [
        'client_ref' => (string) Str::uuid(), 'source_app' => 'whatsapp', 'status' => 'recording', 'started_at' => '2026-10-24T09:00:00Z',
    ]));
    $record('stt_token', $api()->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $open['session']['id'], 'channels' => ['me', 'client']]));
    $record('live_translate_token', $api()->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en', 'language_hints' => ['ar']]));
    $plain = random_bytes(2048);
    $finished = $record('session_finish', $api()->postJson('/api/sessions/'.$open['session']['id'].'/finish', [
        'ended_at' => '2026-10-24T09:10:00Z', 'duration_ms' => 600000,
        'artifacts' => [Fx::artifact(['byte_size' => strlen($plain), 'sha256' => hash('sha256', $plain)])],
    ]));
    $recordingId = $finished['recordings'][0]['id'];
    $record('recording_status', $api()->getJson("/api/recordings/{$recordingId}"));
    $record('recording_chunk', $this->putChunk($token, $recordingId, 0, $plain));
    $record('recording_complete', $api()->postJson("/api/recordings/{$recordingId}/complete"));
    $record('session_create', $api()->postJson('/api/sessions', Fx::sessionPayload()));

    // Projects.
    $project = $record('client_create', $api()->postJson('/api/clients', ['name' => 'Sample project', 'color' => '#8B5CF6']));
    $projectId = $project['data']['id'];
    $record('client_update', $api()->patchJson("/api/clients/{$projectId}", ['name' => 'Sample project, renamed']));
    $record('client_rule_create', $api()->postJson("/api/clients/{$projectId}/rules", ['kind' => 'contact', 'value' => '+923001234567']));
    $record('client_rules', $api()->getJson("/api/clients/{$projectId}/rules"));
    $record('client_brief', $api()->putJson("/api/clients/{$projectId}/brief", ['sections' => ['goal' => 'A short brief.', 'scope' => ['Login'], 'people' => ['Omar']]]));
    $record('client_memory', $api()->postJson("/api/clients/{$projectId}/memory", ['kind' => 'decision', 'text' => 'They chose the second plan.']));
    $record('client_instructions', $api()->postJson("/api/clients/{$projectId}/files", ['text' => 'Keep answers short.', 'name' => 'Notes']));
    $record('client_file', $api()->post("/api/clients/{$projectId}/files", ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Some notes.')]));
    $api()->patchJson("/api/sessions/{$session->id}", ['client_id' => $projectId])->assertSuccessful();
    $record('client_workspace', $api()->getJson("/api/clients/{$projectId}/workspace"));
    $record('client_ask', $api()->postJson("/api/clients/{$projectId}/ask", ['question' => 'What did they choose?', 'language' => 'en']));
    $record('client_bundle', $api()->getJson("/api/clients/{$projectId}/bundle?notes=10"));

    // Sharing.
    $record('share_create', $api()->postJson("/api/sessions/{$session->id}/shares", ['audience' => 'public', 'allow_download' => false]));
    $record('shares_list', $api()->getJson("/api/sessions/{$session->id}/shares"));
    $record('client_share_create', $api()->postJson("/api/clients/{$projectId}/shares", ['audience' => 'public', 'allow_download' => false]));
    $record('shares_by_client', $api()->getJson("/api/shares?client_id={$projectId}"));
    $record('send', $api()->postJson("/api/sessions/{$session->id}/send", ['to' => ['teammate@example.test'], 'note' => 'Please look.']));
    $record('request_link_create', $api()->postJson('/api/request-links', ['title' => 'Send me the file']));
    $record('request_links', $api()->getJson('/api/request-links'));

    $theirs = Fx::token(Fx::device($teammate));
    $inbox = $record('shared_with_me', $this->api($theirs)->getJson('/api/shared-with-me'));
    $record('shared_save', $this->api($theirs)->postJson('/api/shared-with-me/'.$inbox['data'][0]['id'].'/save'));

    // Housekeeping.
    $record('activity_read', $api()->postJson('/api/activity/read'));
    $api()->deleteJson("/api/sessions/{$session->id}")->assertSuccessful();
    $record('bin', $api()->getJson('/api/bin'));
    $record('session_restore', $api()->postJson("/api/sessions/{$session->id}/restore"));
    $record('account_export', $api()->postJson('/api/account/export'));
    $record('client_delete', $api()->deleteJson("/api/clients/{$projectId}"));

    expect($differences)->toBe([], "The API's answers changed. If that is meant: UPDATE_API_SAMPLES=1 php vendor/bin/pest tests/Feature/Api/ApiSamplesTest.php\n");
    expect(array_map(fn (string $f): string => basename($f, '.json'), glob("{$dir}/*.json") ?: []))->each->toBeIn($seen);

    Fx::cleanupDisks();
});
