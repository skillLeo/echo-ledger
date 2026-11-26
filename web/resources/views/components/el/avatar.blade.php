{{--
    DES-08: a person's avatar, the one used everywhere in the new console (the avatar menu, Settings › Profile and the
    profile card, Shared with me, Sent to, the workspace's people and invitations): their photo, else their initials on
    their colour (avatar_color 0 to 7, the tokens' --el-role-avatar-0…7; never grey), with a 1 px ring so it never melts
    into the page. A photo shows on its colour while it loads and turns into the initials if it fails (filament.v2.head).
    It is decorative: the person's name is always written beside it or in its button's label.

    person: the account (App\Filament\Support\PersonAvatar); name and email stand in for it when there is none yet.
    size: none (36 px, the avatar menu's button in the top bar), rail (28 px, the rail's Account, UX-18), list (32 px),
    large (40 px, the menu's head), xl (56 px, the profile card), profile (96 px, Settings › Profile, UX-18).
    drawn: the initials drawn by CSS (data-initials) rather than written, inside a link whose text must stay the
    person's name and email (the profile card, as BE-06 drew it).
--}}
@props(['person' => null, 'name' => null, 'email' => null, 'size' => null, 'drawn' => false])
@php
    $look = \App\Filament\Support\PersonAvatar::of($person, $name, $email);
    $class = 'v2-avatar'.match ($size) {
        'rail' => ' is-rail',
        'list' => ' is-list',
        'large' => ' is-large',
        'xl' => ' is-xl',
        'profile' => ' is-profile',
        default => '',
    };
    $drawn = $drawn ? ' is-drawn' : '';
@endphp
@if ($look['photo'] !== null)
<img class="{{ $class }} is-photo{{ $drawn }}" src="{{ $look['photo'] }}" alt="" aria-hidden="true" data-avatar-color="{{ $look['color'] }}" data-initials="{{ $look['initials'] }}" style="background-color: var(--el-role-avatar-{{ $look['color'] }})">
@elseif ($drawn !== '')
<span class="{{ $class }} is-coloured{{ $drawn }}" data-avatar-color="{{ $look['color'] }}" style="background-color: var(--el-role-avatar-{{ $look['color'] }})" data-initials="{{ $look['initials'] }}" aria-hidden="true"></span>
@else
<span class="{{ $class }} is-coloured" data-avatar-color="{{ $look['color'] }}" style="background-color: var(--el-role-avatar-{{ $look['color'] }})" aria-hidden="true">{{ $look['initials'] }}</span>
@endif
