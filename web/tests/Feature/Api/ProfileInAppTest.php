<?php

declare(strict_types=1);

use App\Filament\Support\Shell;
use App\Models\User;
use App\Services\Accounts\Avatars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| Contract 2.0.0-r, BE-06 (docs/API.md "The profile inside the apps"): GET and PATCH /api/account carry the interface
| language (the web console's own setting) and avatar_color, the initials' circle (0–7), picked once at sign-up and
| given beside every avatar_url. The picture itself is unchanged (2.0.0-l).
*/

function profileApi(User $user): object
{
    return test()->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])));
}

it('gives the account its language and its colour', function () {
    $me = Fx::user();
    $account = profileApi($me)->getJson('/api/account')->assertOk()->json();

    expect($account['language'])->toBe('en')
        ->and($account['avatar_color'])->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(7)
        ->and($account['avatar_color'])->toBe($me->fresh()->avatar_color)
        ->and($account)->toHaveKeys(['avatar_url', 'avatar_version']);
});

it('picks the colour once at sign-up and keeps it', function () {
    $colors = collect(range(1, 40))->map(fn () => Fx::user()->avatar_color);
    expect($colors->every(fn (int $c): bool => $c >= 0 && $c <= 7))->toBeTrue()
        ->and($colors->unique()->count())->toBeGreaterThan(1);

    $me = Fx::user();
    $color = $me->avatar_color;
    $me->forceFill(['name' => 'Renamed'])->save();
    expect($me->fresh()->avatar_color)->toBe($color);
});

it('gives everyone already there a colour from their id, once', function () {
    $people = collect(range(1, 10))->map(fn () => Fx::user());
    $migration = require database_path('migrations/2027_01_01_000001_add_avatar_color_to_users.php');
    $migration->down();
    expect(Schema::hasColumn('users', 'avatar_color'))->toBeFalse();
    $migration->up();

    foreach ($people as $person) {
        expect(DB::table('users')->where('id', $person->id)->value('avatar_color'))->toBe($person->id % 8);
    }
    $migration->up();
    expect(DB::table('users')->where('id', $people[0]->id)->value('avatar_color'))->toBe($people[0]->id % 8);
});

it('changes the language and the colour from the apps, alone or with the name', function () {
    $me = Fx::user(['name' => 'Saif Hassam']);
    $api = profileApi($me);

    $api->patchJson('/api/account', ['language' => 'ur'])->assertOk()->assertJsonPath('language', 'ur')->assertJsonPath('name', 'Saif Hassam');
    expect(Shell::preferences($me->fresh())['app_language'])->toBe('ur');

    $api->patchJson('/api/account', ['avatar_color' => 5])->assertOk()->assertJsonPath('avatar_color', 5);
    $api->patchJson('/api/account', ['name' => 'Saif H.', 'language' => 'en', 'avatar_color' => 0])->assertOk()
        ->assertJsonPath('name', 'Saif H.')->assertJsonPath('language', 'en')->assertJsonPath('avatar_color', 0);
    expect($me->fresh())->name->toBe('Saif H.')->avatar_color->toBe(0);
});

it('refuses a language other than en or ur, a colour outside 0 to 7, and nothing at all', function (array $body) {
    $me = Fx::user();
    $before = $me->fresh()->only(['name', 'avatar_color', 'console_preferences']);

    profileApi($me)->patchJson('/api/account', $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect($me->fresh()->only(['name', 'avatar_color', 'console_preferences']))->toBe($before);
})->with([
    'French' => [['language' => 'fr']],
    'eight' => [['avatar_color' => 8]],
    'below zero' => [['avatar_color' => -1]],
    'a colour name' => [['avatar_color' => 'blue']],
    'nothing' => [[]],
    'a good name with a bad colour' => [['name' => 'New name', 'avatar_color' => 9]],
]);

it('counts the language and the colour in the name\'s 10 an hour', function () {
    $api = profileApi(Fx::user());
    for ($i = 0; $i < 10; $i++) {
        $api->patchJson('/api/account', ['avatar_color' => $i % 8])->assertOk();
    }
    $api->patchJson('/api/account', ['language' => 'ur'])->assertStatus(429);
});

it('changes only the person\'s own account, and gives the colour beside every picture', function () {
    [$me, $other] = [Fx::user(), Fx::user()];
    $otherBefore = $other->fresh()->only(['avatar_color', 'console_preferences']);

    profileApi($me)->patchJson('/api/account', ['avatar_color' => 3, 'language' => 'ur'])->assertOk();

    expect($other->fresh()->only(['avatar_color', 'console_preferences']))->toBe($otherBefore)
        ->and(Avatars::of($me->fresh()))->toMatchArray(['avatar_color' => 3])
        ->and(array_keys(Avatars::of($other)))->toBe(['avatar_url', 'avatar_version', 'avatar_color'])
        ->and(Avatars::of(null))->toBe(['avatar_url' => null, 'avatar_version' => null, 'avatar_color' => null]);
});
