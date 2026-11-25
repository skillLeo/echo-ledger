{{--
    DES-08: a person in a list of the new console: their avatar (x-el.avatar, 32 px), their name and, under it, a second
    line (their email). With no name the email is the name. Names read in their own direction; an email stays left to
    right inside an Urdu page.
--}}
@props(['person' => null, 'name' => null, 'email' => null, 'sub' => null])
@php
    $label = filled($name) ? $name : (filled($person?->name) ? $person->name : (filled($email) ? $email : (string) $person?->email));
@endphp
<span class="v2-person">
    <x-el.avatar :person="$person" :name="$name" :email="$email" size="list" />
    <span class="v2-person-text">
        <span class="v2-person-name"><bdi>{{ $label }}</bdi></span>
        @if (filled($sub))
            <span class="v2-person-sub"><bdi dir="ltr">{{ $sub }}</bdi></span>
        @endif
    </span>
</span>
