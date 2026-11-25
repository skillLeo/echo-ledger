<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Support\PersonAvatar;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInviteLink;
use App\Services\Accounts\Avatars;
use App\Services\InterfaceFlags;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * DES-08 on the web console: one avatar for a person everywhere in the new console (x-el.avatar): their photo, else
 * their initials on their colour (avatar_color, the tokens' --el-role-avatar-0…7; never grey), a photo that fails
 * showing the initials. In the avatar menu, Settings › Profile and the profile card (BE-06's, now the one component),
 * Shared with me, Sent to, the workspace's people, its pending invitations and its invite links. Classic stays as it was.
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->owner = Fx::user(['name' => 'Saif Hassam', 'email' => 'saif@example.com']);
    $this->owner->forceFill(['avatar_color' => 0])->save();
    $this->ws = (int) $this->owner->current_workspace_id;
    $this->farah = Fx::user(['name' => 'Farah Khan', 'email' => 'farah@example.com']);
    $this->farah->forceFill(['avatar_color' => 4])->save();
    $this->omar = Fx::user(['name' => 'Omar Malik', 'email' => 'omar@example.com']);
    $this->omar->forceFill(['avatar_color' => 1, 'avatar_version' => 1_700_000_000])->save();
    $this->actingAs($this->owner->fresh(), 'web');
});

function avatarsV2(bool $on): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, $on, 0, [], [], $on ? 'everyone' : 'off');
    }
}

/** The initials' circle exactly as x-el.avatar draws it. */
function avatarInitials(string $initials, int $color, string $size = ' is-list'): string
{
    return '<span class="v2-avatar'.$size.' is-coloured" data-avatar-color="'.$color.'" style="background-color: var(--el-role-avatar-'.$color.')" aria-hidden="true">'.$initials.'</span>';
}

/** A person in a list as x-el.person draws it: [$avatar], then their name (Livewire's block markers aside). */
function avatarPerson(string $avatar, string $name): string
{
    return '~'.preg_quote($avatar, '~').'\s*(?:<!--\[if ENDBLOCK\]><!\[endif\]-->)?\s*<span class="v2-person-text">\s*<span class="v2-person-name"><bdi>'
        .preg_quote($name, '~').'</bdi></span>~u';
}

it('draws the photo, else the initials of the first and last name on the person\'s colour; one word gives one letter, no name the email\'s', function () {
    expect(PersonAvatar::of($this->farah))->toBe(['photo' => null, 'initials' => 'FK', 'color' => 4])
        ->and(PersonAvatar::of(Fx::user(['name' => 'Zainab']))['initials'])->toBe('Z')
        ->and(PersonAvatar::of(Fx::user(['name' => 'Ali Raza Khan']))['initials'])->toBe('AK')
        ->and(PersonAvatar::of(Fx::user(['name' => '', 'email' => '_q.ali@example.com']))['initials'])->toBe('Q');
    // A person who never chose a colour has their id's, as the API gives it.
    $plain = Fx::user();
    expect(PersonAvatar::of($plain)['color'])->toBe(Avatars::of($plain)['avatar_color'])->toBeBetween(0, 7);
    // With a photo: the API's own address for it.
    expect(PersonAvatar::of($this->omar)['photo'])->toBe(Avatars::url($this->omar->id, 1_700_000_000));

    expect(Blade::render('<x-el.avatar :person="$p" size="list" />', ['p' => $this->farah]))->toContain(avatarInitials('FK', 4));
    $photo = Blade::render('<x-el.avatar :person="$p" size="large" />', ['p' => $this->omar]);
    // On its colour while it loads, with what its initials need if it fails; decorative (the name is beside it).
    expect($photo)->toContain('<img class="v2-avatar is-large is-photo" src="'.e(Avatars::url($this->omar->id, 1_700_000_000)).'" alt="" aria-hidden="true" data-avatar-color="1" data-initials="OM" style="background-color: var(--el-role-avatar-1)">');
    // Never the accent's pale default or any colour but the person's: each size is the same circle.
    foreach ([null, 'list', 'large', 'xl'] as $size) {
        expect(Blade::render('<x-el.avatar :person="$p" :size="$s" />', ['p' => $this->farah, 's' => $size]))->toContain('style="background-color: var(--el-role-avatar-4)"');
    }
});

it('gives an address with no account its first letter on a colour from the address, never looking up an account by it', function () {
    $look = PersonAvatar::of(null, null, 'Hamza.Siddiqui@example.com');
    expect($look)->toBe(['photo' => null, 'initials' => 'H', 'color' => PersonAvatar::colorOf('hamza.siddiqui@example.com')])
        ->and(PersonAvatar::colorOf(' hamza.siddiqui@example.com '))->toBe($look['color'])
        ->and($look['color'])->toBeBetween(0, 7);
    // Omar's own address with no account given: neither his photo nor (unless the address happens to give it) his colour.
    expect(PersonAvatar::of(null, null, 'omar@example.com'))->toBe(['photo' => null, 'initials' => 'O', 'color' => PersonAvatar::colorOf('omar@example.com')]);
});

it('shows the sender\'s avatar beside their name in Shared with me, their email under it; classic keeps its column', function () {
    $session = Fx::session($this->farah, Fx::device($this->farah), ['title' => 'Invoice questions']);
    app(Teammates::class)->send($this->farah, $session, ['saif@example.com'], 'The part about Friday');

    avatarsV2(true);
    $html = (string) $this->get('/app/shared-with-me')->assertOk()->getContent();
    expect($html)->toContain('<span class="v2-person">')->toMatch(avatarPerson(avatarInitials('FK', 4), 'Farah Khan'))
        ->toContain('<span class="v2-person-sub"><bdi dir="ltr">farah@example.com</bdi></span>');

    avatarsV2(false);
    $html = (string) $this->get('/app/shared-with-me')->assertOk()->getContent();
    expect($html)->not->toContain('v2-avatar')->not->toContain('v2-person')->toContain('Farah Khan')->toContain('farah@example.com');
});

it('shows each person a recording was sent to with their avatar; a photo carries its initials for when it fails', function () {
    $session = Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Retainer check-in']);
    app(Teammates::class)->send($this->owner, $session, ['farah@example.com', 'omar@example.com'], 'Notes for Friday');

    $items = fn () => app(Teammates::class)->sentOf($this->owner, $this->ws, $session->id, null);
    avatarsV2(true);
    // The recording's More › Sent to opens it.
    Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->mountAction('sentTo')->assertActionMounted('sentTo');
    $html = view('filament.pages.sent-to', ['items' => $items()])->render();
    expect($html)->toMatch(avatarPerson(avatarInitials('FK', 4), 'Farah Khan'))
        ->toContain('<img class="v2-avatar is-list is-photo" src="'.e(Avatars::url($this->omar->id, 1_700_000_000)).'" alt="" aria-hidden="true" data-avatar-color="1" data-initials="OM"')
        ->toContain('<bdi dir="ltr">omar@example.com</bdi>');

    // Classic: the line as it was, byte for byte (no block markers either).
    avatarsV2(false);
    $html = view('filament.pages.sent-to', ['items' => $items()])->render();
    expect($html)->not->toContain('v2-avatar')
        ->toContain("            <div class=\"min-w-0 text-sm\">\n                <div class=\"font-medium\">Farah Khan <span class=\"text-gray-500 dark:text-gray-400\">farah@example.com</span></div>\n                <div class=\"text-gray-500 dark:text-gray-400\">");
});

it('shows the workspace\'s people with their avatars, and each invitation and invite link with one', function () {
    $workspace = Workspace::query()->findOrFail($this->ws);
    app(Workspaces::class)->addMember($workspace, $this->farah, WorkspaceRole::Member);
    app(Workspaces::class)->addMember($workspace, $this->omar, WorkspaceRole::Admin);
    // An invitation to an address that has an account: the avatar comes from the address alone.
    foreach (['hamza@example.com', 'omar@example.com'] as $email) {
        WorkspaceInvitation::query()->create(['workspace_id' => $this->ws, 'email' => $email, 'role' => WorkspaceRole::Member->value,
            'token_hash' => hash('sha256', Str::random(40)), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDays(7)]);
    }
    WorkspaceInviteLink::query()->create(['workspace_id' => $this->ws, 'role' => WorkspaceRole::Member->value, 'token_hash' => hash('sha256', Str::random(40)),
        'created_by' => $this->owner->id, 'expires_at' => now()->addDays(7), 'max_uses' => 5]);

    avatarsV2(true);
    $html = (string) $this->get('/app/workspace')->assertOk()->getContent();
    expect($html)->toContain(avatarInitials('SH', 0))->toContain(avatarInitials('FK', 4))
        ->toContain('<img class="v2-avatar is-list is-photo" src="'.e(Avatars::url($this->omar->id, 1_700_000_000)).'"')
        ->toMatch(avatarPerson(avatarInitials('H', PersonAvatar::colorOf('hamza@example.com')), 'hamza@example.com'))
        ->toMatch(avatarPerson(avatarInitials('O', PersonAvatar::colorOf('omar@example.com')), 'omar@example.com'));
    // Omar's photo shows once: as a person of the workspace, not for the invitation to his address.
    expect(substr_count($html, e(Avatars::url($this->omar->id, 1_700_000_000))))->toBe(1)
        // The invite link's maker.
        ->and(substr_count($html, avatarInitials('SH', 0)))->toBe(2);

    avatarsV2(false);
    $html = (string) $this->get('/app/workspace')->assertOk()->getContent();
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    expect($html)->not->toContain('v2-avatar')->not->toContain('v2-person')
        ->toContain('<td style="'.$cell.'">hamza@example.com</td><td style="'.$cell.'">Member</td>')
        ->toContain('<td style="'.$cell.'">Member</td><td style="'.$cell.'">Saif Hassam</td>');
});

it('draws the avatar menu, Settings › Profile and the profile card with the one component', function () {
    avatarsV2(true);
    $settings = (string) $this->get('/app/settings')->assertOk()->getContent();
    preg_match('~<div class="el-pcard" data-profile-card>.*?</a>~s', $settings, $card);
    // The card's link reads as the person's name and email: its initials are drawn, not written.
    expect($card[0] ?? '')->toContain('<span class="v2-avatar is-xl is-coloured is-drawn" data-avatar-color="0" style="background-color: var(--el-role-avatar-0)" data-initials="SH" aria-hidden="true"></span>');
    expect($settings)->toContain(avatarInitials('SH', 0, ''))->toContain(avatarInitials('SH', 0, ' is-large'));
    $profile = (string) $this->get('/app/settings/profile')->assertOk()->getContent();
    preg_match('~data-setting="profile.photo">.*?<span class="el-set-text">~s', $profile, $row);
    expect($row[0] ?? '')->toContain(avatarInitials('SH', 0, ' is-xl'));
});

it('turns a photo that fails into the initials in the new console only', function () {
    avatarsV2(true);
    expect((string) $this->get('/app/settings')->assertOk()->getContent())->toContain('window.ElAvatarCore.install(document);')
        ->toContain(trim(explode("\n", (string) file_get_contents(resource_path('js/avatar-core.cjs')))[0]));
    avatarsV2(false);
    expect((string) $this->get('/app')->assertOk()->getContent())->not->toContain('ElAvatarCore');
});

it('keeps an email left to right and a name in its own direction in Urdu', function () {
    avatarsV2(true);
    $this->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
    $this->actingAs($this->owner->fresh(), 'web');
    $session = Fx::session($this->farah, Fx::device($this->farah));
    $this->farah->forceFill(['name' => 'فرح خان'])->save();
    app(Teammates::class)->send($this->farah, $session, ['saif@example.com'], null);

    $html = (string) $this->get('/app/shared-with-me')->assertOk()->getContent();
    expect($html)->toContain('dir="rtl"')->toMatch(avatarPerson(avatarInitials("ف\u{200C}خ", 4), 'فرح خان'))
        ->toContain('<bdi dir="ltr">farah@example.com</bdi>');
});

it('escapes a hostile name and address in every avatar and person it draws', function () {
    $hostile = Fx::user(['name' => '<img src=x onerror=1>', 'email' => 'o"neil@example.com']);
    // With a photo, the initials go into an attribute (data-initials).
    $hostile->forceFill(['avatar_color' => 2, 'avatar_version' => 1_700_000_002])->save();
    avatarsV2(true);

    $cell = PersonAvatar::cell($hostile, $hostile->email)->toHtml();
    $photo = Blade::render('<x-el.avatar :person="$p" />', ['p' => $hostile]);
    $invite = PersonAvatar::cell(null, null, null, 'x"><script>alert(1)</script>@example.com')->toHtml();
    foreach ([$cell, $photo, $invite] as $html) {
        expect($html)->not->toContain('<img src=x')->not->toContain('onerror=1>')->not->toContain('<script>')->not->toContain('o"neil');
    }
    expect($cell)->toContain('<bdi>&lt;img src=x onerror=1&gt;</bdi>')->toContain('<bdi dir="ltr">o&quot;neil@example.com</bdi>')
        ->and($photo)->toContain('data-initials="IO"')
        ->and($invite)->toContain('<bdi>x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;@example.com</bdi>');

    // Without a photo the letters are text, and the workspace's people list keeps the name as text too.
    $hostile->forceFill(['avatar_version' => null])->save();
    expect(Blade::render('<x-el.avatar :person="$p" />', ['p' => $hostile->fresh()]))->toContain('aria-hidden="true">IO</span>');
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->ws), $hostile, WorkspaceRole::Member);
    $html = (string) $this->get('/app/workspace')->assertOk()->getContent();
    expect($html)->toContain('<bdi>&lt;img src=x onerror=1&gt;</bdi>')->not->toContain('<img src=x onerror=1>');
});
