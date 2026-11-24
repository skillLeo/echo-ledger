<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Shell;
use App\Models\SentItem;
use App\Models\User;
use App\Services\Accounts\Avatars;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

/*
 * The profile inside the apps (contract 2.0.0-r §6, BE-06; the colours' names since 2.0.0-u): GET /api/account gives the
 * interface language (the console's own setting, en or ur) and avatar_color (0 to 7, the initials' circle when there is
 * no photo); PATCH takes them beside the name, each optional, at least one, 10 an hour shared with the name. The colour
 * is the person's from sign-up: from their id until they choose one, so it is the same on every device. avatar_color is
 * given wherever a person is shown with avatar_url. Only the person's own account.
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
    Notification::fake();
});

it('gives the language and a colour from the person\'s id until they choose one, the same on every device', function () {
    $user = Fx::user();
    [, $phone] = $this->enroll($user);
    [, $laptop] = $this->enroll($user, null, 'windows');

    $account = $this->asToken($phone)->getJson('/api/account')->assertOk();
    expect($account->json('language'))->toBe('en')
        ->and($account->json('avatar_color'))->toBe($user->id % 8)
        ->and($this->asToken($laptop)->getJson('/api/account')->json('avatar_color'))->toBe($user->id % 8);

    // The console's own language setting is the same one.
    Shell::remember($user->fresh(), 'app_language', 'ur');
    expect($this->asToken($laptop)->getJson('/api/account')->json('language'))->toBe('ur');
});

it('takes the language, the colour and the name, each on its own, and answers the account', function () {
    $user = Fx::user(['name' => 'Kept Name']);
    [, $token] = $this->enroll($user);

    $this->asToken($token)->patchJson('/api/account', ['language' => 'ur'])->assertOk()
        ->assertJsonPath('language', 'ur')->assertJsonPath('name', 'Kept Name')->assertJsonStructure(['workspaces', 'avatar_url', 'avatar_color']);
    expect(Shell::preferences($user->fresh())['app_language'])->toBe('ur');

    $this->asToken($token)->patchJson('/api/account', ['avatar_color' => 0])->assertOk()->assertJsonPath('avatar_color', 0)->assertJsonPath('language', 'ur');
    $this->asToken($token)->patchJson('/api/account', ['avatar_color' => 7, 'language' => 'en', 'name' => 'New Name'])->assertOk()
        ->assertJsonPath('avatar_color', 7)->assertJsonPath('language', 'en')->assertJsonPath('name', 'New Name');
    expect($user->fresh()->avatar_color)->toBe(7);
    // Kept from then on, on every device.
    [, $other] = $this->enroll($user->fresh(), null, 'windows');
    $this->asToken($other)->getJson('/api/account')->assertJsonPath('avatar_color', 7);
});

it('refuses a language that is not offered and a colour that is not a whole number from 0 to 7, and saves nothing', function () {
    $user = Fx::user(['name' => 'Kept Name']);
    [, $token] = $this->enroll($user);

    foreach (['fr', 'EN', '', 'en-GB', 1, null, ['en']] as $language) {
        Cache::flush(); // each try counts against the 10 an hour; only the answer matters here
        $response = $this->asToken($token)->patchJson('/api/account', ['language' => $language, 'name' => 'Not Saved'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect($response->json('errors.language.0'))->toBeString();
    }
    foreach ([8, -1, '3', 2.5, true, null, 'teal'] as $colour) {
        Cache::flush();
        $response = $this->asToken($token)->patchJson('/api/account', ['avatar_color' => $colour])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect($response->json('errors.avatar_color.0'))->toBeString();
    }
    $user->refresh();
    expect($user->name)->toBe('Kept Name')->and($user->avatar_color)->toBeNull()->and(ConsoleV2::LANGUAGES)->toHaveKeys(['en', 'ur'])
        ->and(Shell::preferences($user)['app_language'])->toBe('en');
    // Nothing at all: the name is asked for, as before.
    Cache::flush();
    $this->asToken($token)->patchJson('/api/account', [])->assertStatus(422)->assertJsonPath('error_codes.name', [['code' => 'required']]);
});

it('allows 10 changes an hour together with the name', function () {
    $user = Fx::user();
    [, $token] = $this->enroll($user);
    foreach (range(1, 10) as $i) {
        $body = match ($i % 3) {
            0 => ['name' => "Name {$i}"], 1 => ['avatar_color' => $i % 8], default => ['language' => $i % 2 === 0 ? 'ur' : 'en']
        };
        $this->asToken($token)->patchJson('/api/account', $body)->assertOk();
    }

    $this->asToken($token)->patchJson('/api/account', ['avatar_color' => 1])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    $this->asToken($token)->patchJson('/api/account', ['name' => 'Eleven'])->assertStatus(429);
});

it('changes only the asking person\'s own profile: another person\'s id in the body is not read, and a teammate\'s stays', function () {
    $owner = Fx::user(['name' => 'Owner']);
    $member = Fx::user(['name' => 'Member']);
    app(Workspaces::class)->addMember($owner->currentWorkspace, $member, WorkspaceRole::Member);
    app(Workspaces::class)->switchTo($member, $owner->currentWorkspace);
    [, $memberToken] = $this->enroll($member->fresh());

    $this->asToken($memberToken)->patchJson('/api/account', ['avatar_color' => 3, 'language' => 'ur', 'id' => $owner->id, 'user_id' => $owner->id, 'email' => $owner->email])
        ->assertOk()->assertJsonPath('name', 'Member')->assertJsonPath('avatar_color', 3);
    $owner->refresh();
    expect($member->fresh()->avatar_color)->toBe(3)
        ->and($owner->avatar_color)->toBeNull()
        ->and(Shell::preferences($owner)['app_language'])->toBe('en')
        ->and(Shell::preferences($member->fresh())['app_language'])->toBe('ur');
    // A device of another workspace changes only its own person, too.
    [, $outsider] = $this->enroll($stranger = Fx::user());
    $this->asToken($outsider)->patchJson('/api/account', ['avatar_color' => 6])->assertOk();
    expect($stranger->fresh()->avatar_color)->toBe(6)->and($owner->fresh()->avatar_color)->toBeNull()->and($member->fresh()->avatar_color)->toBe(3);
});

it('gives avatar_color wherever a person is shown with avatar_url', function () {
    $ana = Fx::user(['name' => 'Ana']);
    $omar = Fx::user(['name' => 'Omar']);
    $omar->forceFill(['avatar_color' => 4])->save();
    [$anaDevice, $anaToken] = $this->enroll($ana);
    [, $omarToken] = $this->enroll($omar);
    $session = Fx::session($ana, $anaDevice, ['title' => 'Call']);
    SentItem::query()->create(['workspace_id' => $session->workspace_id, 'from_user_id' => $ana->id, 'to_user_id' => $omar->id, 'kind' => 'session',
        'capture_session_id' => $session->id, 'title' => 'Call', 'sent_at' => now()]);

    expect($this->asToken($omarToken)->getJson('/api/shared-with-me')->assertOk()->json('data.0.from'))->toMatchArray(['avatar_url' => null, 'avatar_color' => $ana->id % 8])
        ->and($this->asToken($anaToken)->getJson("/api/sent?session_id={$session->id}")->assertOk()->json('data.0.to'))->toMatchArray(['avatar_color' => 4])
        ->and(Avatars::of(null))->toBe(['avatar_url' => null, 'avatar_version' => null, 'avatar_color' => null])
        ->and(Avatars::of($omar->fresh())['avatar_color'])->toBe(4);
});

it('keeps the colour 0 to 7 for every new and earlier person, from their id', function () {
    $people = collect(range(1, 12))->map(fn (): User => Fx::user());
    expect($people->every(fn (User $u): bool => $u->avatarColor() === $u->id % 8 && $u->avatarColor() >= 0 && $u->avatarColor() <= 7))->toBeTrue();
});
