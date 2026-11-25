<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\Shell;
use App\Models\User;
use App\Services\Accounts\Avatars;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

/*
 * BE-06 in the console: Settings › Profile has what the apps' profile has (contract 2.0.0-r §6, the colours' names since
 * 2.0.0-u): the name and email as before, the photo (Change photo…, Remove photo), the initials' colour when there is
 * no photo (8 swatches: teal, blue, cyan, olive, pink, orange, green, slate), and under the new frame the language. The
 * same settings as GET and PATCH /api/account, so a change shows everywhere. Only the signed-in person's own.
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Fx::fakeRecordingsDisk();
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->user = Fx::user(['name' => 'Ayesha Khan']);
    $this->actingAs($this->user, 'web');
});

/** A small picture, as bytes. */
function profileScreenPicture(): string
{
    $image = imagecreatetruecolor(300, 300);
    imagefilledrectangle($image, 0, 0, 299, 299, (int) imagecolorallocate($image, 20, 120, 110));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('shows the photo, the colour and the language on Profile, each findable from Settings search', function () {
    SettingsContext::forget();
    $html = (string) $this->get('/app/settings/profile')->assertOk()->getContent();

    expect($html)->toContain('data-setting="profile.photo"')->toContain('data-setting="profile.color"')->toContain('data-setting="profile.language"')
        ->toContain('role="radiogroup" aria-labelledby="el-profile-color"');
    foreach (['Teal', 'Blue', 'Cyan', 'Olive', 'Pink', 'Orange', 'Green', 'Slate'] as $i => $name) {
        expect($html)->toContain('aria-label="'.$name.'"')->toContain('var(--el-role-avatar-'.$i.')');
    }
    // The person's own colour is the one ticked, from their id until they choose one; with no photo there is none to remove.
    expect($html)->toContain('data-avatar-color="'.$this->user->avatarColor().'"')->not->toContain('Purple')->not->toContain('Indigo')
        ->toContain('Add photo…')->not->toContain('Remove photo');
});

it('keeps the colour the person picks, and only a colour from 0 to 7', function () {
    Livewire::test(EditProfile::class, ['subpage' => 'profile'])->call('setAvatarColor', 3);
    expect($this->user->fresh()->avatar_color)->toBe(3);

    foreach ([8, -1] as $wrong) {
        Livewire::test(EditProfile::class, ['subpage' => 'profile'])->call('setAvatarColor', $wrong);
    }
    expect($this->user->fresh()->avatar_color)->toBe(3);
    // The apps read the same.
    expect(Avatars::of($this->user->fresh())['avatar_color'])->toBe(3);
});

it('sets the language the apps read, English or Urdu only', function () {
    Livewire::test(EditProfile::class, ['subpage' => 'profile'])->call('setPreference', 'app_language', 'ur');
    expect(Shell::preferences($this->user->fresh())['app_language'])->toBe('ur');
    Livewire::test(EditProfile::class, ['subpage' => 'profile'])->call('setPreference', 'app_language', 'fr');
    expect(Shell::preferences($this->user->fresh())['app_language'])->toBe('ur');
});

it('changes and removes the photo with the apps\' own checks, and shows it on the profile card', function () {
    Livewire::test(EditProfile::class, ['subpage' => 'profile'])
        ->callAction('changePhoto', ['photo' => UploadedFile::fake()->createWithContent('me.png', profileScreenPicture())])
        ->assertHasNoActionErrors()->assertNotified('Photo changed');
    $version = $this->user->fresh()->avatar_version;
    expect($version)->toBeInt();

    SettingsContext::forget();
    $html = (string) $this->get('/app/settings/profile')->assertOk()->getContent();
    expect($html)->toContain(htmlspecialchars(Avatars::url($this->user->id, (int) $version)))->toContain('Change photo…')->toContain('Remove photo');

    Livewire::test(EditProfile::class, ['subpage' => 'profile'])->callAction('removePhoto')->assertNotified('Photo removed');
    expect($this->user->fresh()->avatar_version)->toBeNull();

    // A file that is not a picture is refused with the apps' words, and nothing changes.
    Livewire::test(EditProfile::class, ['subpage' => 'profile'])
        ->callAction('changePhoto', ['photo' => UploadedFile::fake()->createWithContent('me.png', 'not a picture')]);
    expect($this->user->fresh()->avatar_version)->toBeNull();
});

it('draws the initials in the person\'s colour on the profile card when there is no photo', function () {
    $this->user->forceFill(['avatar_color' => 5])->save();
    SettingsContext::forget();

    // Since DES-08 drawn by the one avatar component (x-el.avatar), on the card.
    preg_match('~<div class="el-pcard" data-profile-card>.*?</a>~s', (string) $this->get('/app/settings')->assertOk()->getContent(), $card);
    expect($card[0] ?? '')->toContain('<span class="v2-avatar is-xl is-coloured is-drawn" data-avatar-color="5" style="background-color: var(--el-role-avatar-5)" data-initials="AK" aria-hidden="true"></span>');
});

it('changes nothing of anyone else', function () {
    $other = Fx::user(['name' => 'Someone Else']);
    Livewire::test(EditProfile::class, ['subpage' => 'profile'])->call('setAvatarColor', 6)->call('setPreference', 'app_language', 'ur');

    $other->refresh();
    expect($other->avatar_color)->toBeNull()->and(Shell::preferences($other)['app_language'])->toBe('en')
        ->and(User::query()->whereNotNull('avatar_color')->pluck('id')->all())->toBe([$this->user->id]);
});

it('draws the rail\'s avatar and its menu in the person\'s colour, or their photo', function () {
    $this->user->forceFill(['avatar_color' => 2])->save();
    SettingsContext::forget();
    $html = (string) $this->get('/app/settings')->assertOk()->getContent();
    expect(substr_count($html, 'class="v2-avatar is-coloured" data-avatar-color="2" style="background-color: var(--el-role-avatar-2)"'))->toBe(1)
        ->and(substr_count($html, 'class="v2-avatar is-large is-coloured" data-avatar-color="2" style="background-color: var(--el-role-avatar-2)"'))->toBe(1);

    app(Avatars::class)->store($this->user->fresh(), UploadedFile::fake()->createWithContent('me.png', profileScreenPicture()));
    // A new request reads the person again.
    $this->actingAs($this->user->fresh(), 'web');
    SettingsContext::forget();
    $html = (string) $this->get('/app/settings')->assertOk()->getContent();
    expect($html)->toContain('<img class="v2-avatar is-photo"')->toContain('<img class="v2-avatar is-large is-photo"')
        ->not->toContain('class="v2-avatar is-coloured"');
});

it('shares the apps\' limit of 10 photo changes an hour, whichever side asks, and says so', function () {
    [, $token] = $this->enroll($this->user);
    foreach (range(1, 10) as $i) {
        $this->asToken($token)->deleteJson('/api/account/avatar')->assertSuccessful();
    }
    $this->actingAs($this->user->fresh(), 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'profile'])
        ->callAction('changePhoto', ['photo' => UploadedFile::fake()->createWithContent('me.png', profileScreenPicture())])
        ->assertNotified('You have changed your photo 10 times this hour. Try again in 60 min.');
    expect($this->user->fresh()->avatar_version)->toBeNull();

    // And the other way round: the console's changes count for the apps.
    $someone = Fx::user();
    $this->actingAs($someone, 'web');
    foreach (range(1, 10) as $i) {
        Livewire::test(EditProfile::class, ['subpage' => 'profile'])
            ->callAction('changePhoto', ['photo' => UploadedFile::fake()->createWithContent('me.png', profileScreenPicture())]);
    }
    [, $theirs] = $this->enroll($someone->fresh());
    $this->asToken($theirs)->deleteJson('/api/account/avatar')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
});

it('answers the arrows, Home and End on every radio group of Profile and Appearance, from one helper', function () {
    foreach (['/app/settings/profile', '/app/settings/appearance'] as $path) {
        SettingsContext::forget();
        $html = (string) $this->get($path)->assertOk()->getContent();
        // The settings page's own groups (the avatar menu's theme is the frame's).
        preg_match_all('/<[^>]*role="radiogroup" aria-labelledby="el-[^>]*>/', $html, $groups);
        expect($groups[0])->not->toBeEmpty($path);
        foreach ($groups[0] as $group) {
            expect($group)->toContain('data-radio-keys');
        }
        expect($html)->toContain('window.ElRadioKeysCore')->toContain("closest('[role=\"radiogroup\"][data-radio-keys]')")->not->toContain('x-on:keydown.arrow-right');
    }
});
