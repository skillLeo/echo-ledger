<?php

declare(strict_types=1);

use App\Models\CallRule;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountDeletion;
use App\Services\CallRules;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\Workspaces;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Call rules (since 2.0.0-w)", B5 (docs/PLAN_CALL_RULES.md): the person's After each call settings and
 * rules per contact, group and Source, for the whole account, behind the `call_rules` flag.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->person = User::query()->findOrFail($this->device->user_id);
    app(InterfaceFlags::class)->set(InterfaceFlags::CALL_RULES, false, 0, [], [$this->person->id]);
    $this->asToken($this->token);
});

function callRule(object $test, array $body): array
{
    return $test->postJson('/api/call-rules', $body)->assertSuccessful()->json();
}

describe('the flag', function () {
    it('hides everything from a person it is off for: no config, every route not there', function () {
        [$other, $token] = $this->enroll();
        $this->asToken($token);

        $config = $this->getJson('/api/config')->assertOk();
        expect($config->json('ui_flags.call_rules'))->toBeFalse()->and($config->json())->not->toHaveKey('call_rules');
        $this->getJson('/api/call-rules')->assertNotFound();
        $this->putJson('/api/call-rules/settings', ['after_call' => 'always_audio'])->assertNotFound();
        $this->postJson('/api/call-rules', ['kind' => 'source', 'source_app' => 'zoom', 'action' => 'always_transcribe'])->assertNotFound();
        $this->patchJson('/api/call-rules/01JC0000000000000000000000', ['action' => 'ask'])->assertNotFound();
        $this->deleteJson('/api/call-rules/01JC0000000000000000000000')->assertNotFound();
        expect(CallRule::query()->count())->toBe(0)->and($other->user->fresh()->call_settings)->toBeNull();
    });

    it('starts off for everyone, and the admin\'s list turns it on', function () {
        expect(InterfaceFlags::names())->toContain('call_rules')
            ->and($this->getJson('/api/config')->json('ui_flags.call_rules'))->toBeTrue()
            ->and($this->getJson('/api/config')->json('call_rules'))->toBe(['after_call' => null, 'ask_when' => 'after_call',
                'short_calls' => 'dont_keep', 'short_call_seconds' => 30, 'version' => 0]);
    });
});

describe('the settings', function () {
    it('takes the first device\'s choice when the account has none, and the last change after that', function () {
        expect($this->getJson('/api/call-rules')->assertOk()->json())->toMatchArray(['after_call' => null, 'version' => 0, 'rules' => []]);

        $this->putJson('/api/call-rules/settings', ['after_call' => 'always_audio'])->assertOk()
            ->assertJson(['after_call' => 'always_audio', 'ask_when' => 'after_call', 'short_calls' => 'dont_keep', 'version' => 1]);
        $this->putJson('/api/call-rules/settings', ['ask_when' => 'at_start', 'short_calls' => 'keep'])->assertOk()
            ->assertJson(['after_call' => 'always_audio', 'ask_when' => 'at_start', 'short_calls' => 'keep', 'version' => 2]);

        expect($this->getJson('/api/config')->json('call_rules'))->toMatchArray(['after_call' => 'always_audio', 'ask_when' => 'at_start', 'version' => 2]);
    });

    it('refuses a value that is not one of the choices, or after_call back to nothing, with codes', function () {
        $this->putJson('/api/call-rules/settings', ['after_call' => 'sometimes'])->assertStatus(422)
            ->assertJsonPath('error_codes.after_call.0.code', 'invalid');
        $this->putJson('/api/call-rules/settings', ['after_call' => null])->assertStatus(422)
            ->assertJsonPath('error_codes.after_call.0.code', 'required');
        $this->putJson('/api/call-rules/settings', ['ask_when' => 'never'])->assertStatus(422);
        expect($this->person->fresh()->call_settings)->toBeNull();
    });

    it('is the same on every device of the account, in any workspace', function () {
        $this->putJson('/api/call-rules/settings', ['after_call' => 'dont_record'])->assertOk();
        callRule($this, ['kind' => 'source', 'source_app' => 'zoom', 'action' => 'always_transcribe']);
        $team = Workspace::query()->findOrFail(Fx::user()->current_workspace_id);
        app(Workspaces::class)->addMember($team, $this->person);
        $other = Fx::device($this->person, ['workspace_id' => $team->id]);
        app('auth')->forgetGuards();

        $answer = $this->withToken(Fx::token($other))->getJson('/api/call-rules')->assertOk();
        expect($answer->json('after_call'))->toBe('dont_record')->and($answer->json('rules.0.source_app'))->toBe('zoom');
    });
});

describe('the rules', function () {
    it('keeps one rule per contact, group and Source, a second one replacing the first', function () {
        $first = $this->postJson('/api/call-rules', ['kind' => 'contact', 'number' => '0092 300-123 (4567)', 'name' => '  Ahmed   Khan ', 'action' => 'always_transcribe'])
            ->assertCreated()->json();
        expect($first)->toMatchArray(['kind' => 'contact', 'number' => '+923001234567', 'name' => 'Ahmed Khan', 'action' => 'always_transcribe']);

        $again = $this->postJson('/api/call-rules', ['kind' => 'contact', 'number' => '+92 300 1234567', 'action' => 'dont_record'])->assertOk()->json();
        expect($again['id'])->toBe($first['id'])->and($again['action'])->toBe('dont_record');

        callRule($this, ['kind' => 'contact', 'name' => 'Weekly Sync', 'action' => 'always_audio']);
        callRule($this, ['kind' => 'group', 'group' => 'favourites', 'action' => 'dont_record']);
        callRule($this, ['kind' => 'source', 'source_app' => 'microsoft_teams', 'action' => 'always_transcribe']);

        $rules = $this->getJson('/api/call-rules')->json('rules');
        expect(array_column($rules, 'kind'))->toBe(['contact', 'contact', 'group', 'source'])
            ->and($rules[2])->toMatchArray(['group' => 'favourites'])->not->toHaveKey('number')
            ->and(CallRule::query()->count())->toBe(4)
            ->and($this->getJson('/api/call-rules')->json('version'))->toBe(5);
    });

    it('keeps one rule for a number with and without its country code, and for a name a rule is renamed to', function () {
        $first = callRule($this, ['kind' => 'contact', 'number' => '+92 300 1234567', 'action' => 'always_audio']);
        $again = $this->postJson('/api/call-rules', ['kind' => 'contact', 'number' => '0300-1234567', 'action' => 'dont_record'])->assertOk()->json();
        expect($again)->toMatchArray(['id' => $first['id'], 'number' => '+923001234567', 'action' => 'dont_record']);

        $sync = callRule($this, ['kind' => 'contact', 'name' => 'Weekly Sync', 'action' => 'always_audio']);
        $standup = callRule($this, ['kind' => 'contact', 'name' => 'Standup', 'action' => 'dont_record']);
        $this->patchJson("/api/call-rules/{$standup['id']}", ['name' => 'weekly sync'])->assertOk();
        expect(CallRule::query()->whereKey($sync['id'])->exists())->toBeFalse()
            ->and(CallRule::query()->where('user_id', $this->person->id)->count())->toBe(2)
            ->and(app(CallRules::class)->resolve($this->person->fresh(), name: 'Weekly Sync'))->toBe('dont_record');
    });

    it('drops a trunk "(0)" after the country code', function () {
        expect(CallRules::normalizeNumber('+44 (0)20 7946 0958'))->toBe('+442079460958')
            ->and(CallRules::normalizeNumber('+92 (0)300 1234567'))->toBe('+923001234567')
            ->and(CallRules::normalizeNumber('0044 (0)20 7946 0958'))->toBe('+442079460958')
            ->and(CallRules::normalizeNumber('(0300) 123-4567'))->toBe('03001234567');
        $rule = callRule($this, ['kind' => 'contact', 'number' => '+92 (0)300 1234567', 'action' => 'dont_record']);
        expect($rule['number'])->toBe('+923001234567')
            ->and(app(CallRules::class)->resolve($this->person->fresh(), number: '+92 300 1234567'))->toBe('dont_record');
    });

    it('refuses a rule it cannot match, with codes', function () {
        $this->postJson('/api/call-rules', ['kind' => 'contact', 'action' => 'ask'])->assertStatus(422)->assertJsonPath('error_codes.number.0.code', 'required');
        $this->postJson('/api/call-rules', ['kind' => 'contact', 'number' => 'call me', 'action' => 'ask'])->assertStatus(422)->assertJsonPath('error_codes.number.0.code', 'invalid_number');
        $this->postJson('/api/call-rules', ['kind' => 'source', 'source_app' => 'voice_note', 'action' => 'ask'])->assertStatus(422);
        $this->postJson('/api/call-rules', ['kind' => 'group', 'action' => 'ask'])->assertStatus(422)->assertJsonPath('error_codes.group.0.code', 'required');
        $this->postJson('/api/call-rules', ['kind' => 'source', 'source_app' => 'zoom', 'action' => 'maybe'])->assertStatus(422);
        $this->postJson('/api/call-rules', ['kind' => 'contact', 'name' => str_repeat('a', 121), 'action' => 'ask'])->assertStatus(422)
            ->assertJsonPath('error_codes.name.0.code', 'too_long');
        expect(CallRule::query()->count())->toBe(0);
    });

    it('changes and removes only the person\'s own rules', function () {
        $rule = callRule($this, ['kind' => 'contact', 'name' => 'Weekly Sync', 'action' => 'always_audio']);
        $this->patchJson("/api/call-rules/{$rule['id']}", ['action' => 'dont_record', 'name' => 'Monday sync'])->assertOk()
            ->assertJson(['action' => 'dont_record', 'name' => 'Monday sync']);
        // Known by name only: it now matches the new name.
        expect(app(CallRules::class)->resolve($this->person->fresh(), name: 'monday  SYNC'))->toBe('dont_record');

        $theirs = CallRule::query()->create(['user_id' => Fx::user()->id, 'kind' => 'source', 'match_key' => 'zoom', 'source_app' => 'zoom', 'action' => 'dont_record']);
        $this->patchJson("/api/call-rules/{$theirs->id}", ['action' => 'ask'])->assertNotFound()->assertJson(['code' => 'rule_not_found']);
        $this->deleteJson("/api/call-rules/{$theirs->id}")->assertNotFound();
        expect($theirs->fresh()->action)->toBe('dont_record');

        $this->deleteJson("/api/call-rules/{$rule['id']}")->assertNoContent();
        $this->deleteJson("/api/call-rules/{$rule['id']}")->assertNotFound();
    });

    it('keeps at most 500 rules a person, while a rule already there can still change', function () {
        $now = now();
        CallRule::query()->insert(array_map(fn (int $i): array => ['id' => (string) Str::ulid(), 'user_id' => $this->person->id, 'kind' => 'contact',
            'match_key' => '+92300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'number' => '+92300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'action' => 'ask', 'created_at' => $now, 'updated_at' => $now], range(1, 500)));

        $this->postJson('/api/call-rules', ['kind' => 'source', 'source_app' => 'zoom', 'action' => 'ask'])->assertStatus(409)->assertJson(['code' => 'too_many_rules']);
        $this->postJson('/api/call-rules', ['kind' => 'contact', 'number' => '+923000000001', 'action' => 'dont_record'])->assertOk();
        expect(CallRule::query()->where('user_id', $this->person->id)->count())->toBe(500);
    });

    it('takes 60 changes a minute a person', function () {
        foreach (range(1, 60) as $_) {
            $this->putJson('/api/call-rules/settings', ['after_call' => 'ask'])->assertOk();
        }
        $this->putJson('/api/call-rules/settings', ['after_call' => 'ask'])->assertStatus(429);
    });
});

describe('which rule wins', function () {
    beforeEach(function () {
        $this->rules = app(CallRules::class);
        $this->putJson('/api/call-rules/settings', ['after_call' => 'always_audio'])->assertOk();
        callRule($this, ['kind' => 'contact', 'number' => '+923001234567', 'action' => 'always_transcribe']);
        callRule($this, ['kind' => 'contact', 'number' => '+923005550000', 'action' => 'ask']);
        callRule($this, ['kind' => 'contact', 'name' => 'Weekly Sync', 'action' => 'dont_record']);
        callRule($this, ['kind' => 'group', 'group' => 'favourites', 'action' => 'dont_record']);
        callRule($this, ['kind' => 'group', 'group' => 'unknown_numbers', 'action' => 'ask']);
        callRule($this, ['kind' => 'source', 'source_app' => 'zoom', 'action' => 'always_transcribe']);
        $this->resolve = fn (...$call): string => $this->rules->resolve($this->person->fresh(), ...$call);
    });

    it('takes the contact, then the group, then the Source, then the default', function () {
        $resolve = $this->resolve;
        // The contact beats the group and the Source.
        expect($resolve(number: '+923001234567', groups: ['favourites'], source: 'zoom'))->toBe('always_transcribe')
            // The same number without the country code (last 10 digits).
            ->and($resolve(number: '0300 1234567', source: 'whatsapp'))->toBe('always_transcribe')
            // A name, without a number.
            ->and($resolve(name: 'weekly   sync', source: 'zoom'))->toBe('dont_record')
            // The group beats the Source.
            ->and($resolve(number: '+923009999999', groups: ['saved_contacts', 'favourites'], source: 'zoom'))->toBe('dont_record')
            // The Source beats the default.
            ->and($resolve(number: '+923009999999', groups: ['saved_contacts'], source: 'zoom'))->toBe('always_transcribe')
            // The default.
            ->and($resolve(number: '+923009999999', source: 'whatsapp'))->toBe('always_audio')
            // Another country's number with the same last 10 digits is not the same person.
            ->and($resolve(number: '+443001234567', source: 'whatsapp'))->toBe('always_audio');
    });

    it('stops at a rule of "ask" and follows the default from there', function () {
        $resolve = $this->resolve;
        expect($resolve(number: '+923005550000', groups: ['saved_contacts'], source: 'zoom'))->toBe('always_audio')
            ->and($resolve(number: '+923009999999', groups: ['unknown_numbers'], source: 'zoom'))->toBe('always_audio');
    });

    it('lets a later "Don\'t record" win over "ask": privacy first', function () {
        $resolve = $this->resolve;
        callRule($this, ['kind' => 'source', 'source_app' => 'google_meet', 'action' => 'dont_record']);
        // Contact "ask" + Source "Don't record".
        expect($resolve(number: '+923005550000', source: 'google_meet'))->toBe('dont_record')
            // Group "ask" + Source "Don't record".
            ->and($resolve(number: '+923009999999', groups: ['unknown_numbers'], source: 'google_meet'))->toBe('dont_record')
            // Contact "ask" + group "Don't record".
            ->and($resolve(number: '+923005550000', groups: ['favourites'], source: 'zoom'))->toBe('dont_record')
            // Another choice at an earlier step still wins: Ahmed is transcribed even where Meet is not recorded.
            ->and($resolve(number: '+923001234567', source: 'google_meet'))->toBe('always_transcribe');

        // "ask" + the default "Don't record".
        $this->putJson('/api/call-rules/settings', ['after_call' => 'dont_record'])->assertOk();
        expect(($this->resolve)(number: '+923005550000', source: 'whatsapp'))->toBe('dont_record')
            ->and(($this->resolve)(number: '+923001234567', source: 'whatsapp'))->toBe('always_transcribe');
    });

    it('takes the exact number first, and none of two that share their last 10 digits', function () {
        $resolve = $this->resolve;
        callRule($this, ['kind' => 'contact', 'number' => '+443001234567', 'action' => 'dont_record']);
        // Exact matches pick their own country.
        expect($resolve(number: '+923001234567'))->toBe('always_transcribe')
            ->and($resolve(number: '+443001234567'))->toBe('dont_record')
            // Without a country code both share the last 10 digits: no guess, the default.
            ->and($resolve(number: '03001234567', source: 'whatsapp'))->toBe('always_audio');
    });

    it('drops short and missed calls unless the person keeps them', function () {
        $resolve = $this->resolve;
        expect($resolve(number: '+923001234567', seconds: 29))->toBe(CallRules::NOT_KEPT)
            ->and($resolve(number: '+923001234567', missed: true))->toBe(CallRules::NOT_KEPT)
            ->and($resolve(number: '+923001234567', seconds: 30))->toBe('always_transcribe');

        $this->putJson('/api/call-rules/settings', ['short_calls' => 'keep'])->assertOk();
        expect(($this->resolve)(number: '+923001234567', seconds: 5))->toBe('always_transcribe');
    });

    it('lets a project\'s "Always transcribe calls" turn ask or audio into a transcript, and never a "Don\'t record"', function () {
        $resolve = $this->resolve;
        expect($resolve(number: '+923009999999', source: 'whatsapp', projectAlways: true))->toBe('always_transcribe')
            ->and($resolve(number: '+923005550000', projectAlways: true))->toBe('always_transcribe')
            ->and($resolve(name: 'Weekly Sync', projectAlways: true))->toBe('dont_record')
            ->and($resolve(number: '+923009999999', groups: ['favourites'], projectAlways: true))->toBe('dont_record');
        $this->putJson('/api/call-rules/settings', ['after_call' => 'dont_record'])->assertOk();
        expect(($this->resolve)(number: '+923009999999', source: 'whatsapp', projectAlways: true))->toBe('dont_record');
    });
});

describe('the people in the rules', function () {
    it('never writes a number or a name to the logs', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        callRule($this, ['kind' => 'contact', 'number' => '+923001112223', 'name' => 'LOG-SECRET-NAME', 'action' => 'dont_record']);
        $this->postJson('/api/call-rules', ['kind' => 'contact', 'number' => 'not +923001112223 a number', 'name' => 'LOG-SECRET-NAME', 'action' => 'ask'])->assertStatus(422);
        $this->getJson('/api/call-rules')->assertOk();

        expect(implode("\n", $logged))->not->toContain('1112223')->not->toContain('LOG-SECRET-NAME');
    });

    it('puts them in Export my data, and nobody else\'s', function () {
        Storage::fake('local');
        callRule($this, ['kind' => 'contact', 'number' => '+923001112223', 'name' => 'Ahmed Khan', 'action' => 'dont_record']);
        CallRule::query()->create(['user_id' => Fx::user()->id, 'kind' => 'contact', 'match_key' => '+923004445556', 'number' => '+923004445556', 'name' => 'SOMEONE-ELSES', 'action' => 'ask']);

        $id = $this->postJson('/api/account/export')->assertStatus(202)->json('id');
        $url = (string) $this->getJson("/api/account/export/{$id}")->assertJsonPath('status', 'ready')->json('url');
        app('auth')->forgetGuards();
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $this->get($url)->assertOk()->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $rules = json_decode((string) $zip->getFromName('call-rules.json'), true);
        $zip->close();
        unlink($path);

        expect($manifest['call_rules'])->toBe('call-rules.json')
            ->and($rules['rules'][0])->toMatchArray(['number' => '+923001112223', 'name' => 'Ahmed Khan'])
            ->and(json_encode($rules))->not->toContain('SOMEONE-ELSES');
    });

    it('erases them with the account, and leaves everyone else\'s', function () {
        callRule($this, ['kind' => 'contact', 'number' => '+923001112223', 'name' => 'Ahmed Khan', 'action' => 'dont_record']);
        $this->putJson('/api/call-rules/settings', ['after_call' => 'always_audio'])->assertOk();
        $theirs = CallRule::query()->create(['user_id' => Fx::user()->id, 'kind' => 'source', 'match_key' => 'zoom', 'source_app' => 'zoom', 'action' => 'ask']);

        app(AccountDeletion::class)->schedule($this->person->fresh());
        $this->travel(7)->days();
        $this->travel(1)->minutes();
        expect(Artisan::call('echoledger:erase-accounts'))->toBe(0);

        expect(User::query()->find($this->person->id))->toBeNull()
            ->and(CallRule::query()->where('user_id', $this->person->id)->count())->toBe(0)
            ->and(DB::table('call_rules')->where('number', '+923001112223')->exists())->toBeFalse()
            ->and($theirs->fresh())->not->toBeNull();
    });
});

it('rolls the table and the column back and forth', function () {
    $migration = 'database/migrations/2027_01_03_000001_create_call_rules.php';

    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('call_rules'))->toBeFalse()->and(Schema::hasColumn('users', 'call_settings'))->toBeFalse();

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('call_rules'))->toBeTrue()->and(Schema::hasColumn('users', 'call_settings'))->toBeTrue();
});
