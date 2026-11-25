<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use App\Services\Accounts\Avatars;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * DES-08: what a person's avatar shows in the new console (the x-el.avatar component): their photo, else their initials
 * on their colour. The photo and the colour are the API's (Avatars::of, contract 2.0.0-l and 2.0.0-r §6), so a person
 * looks the same on the web as in the apps. Initials are the first letters of the first and last name ("Saif Hassam" is
 * SH, a one-word name gives one letter) and, with no name, the email's first letter or digit. Urdu initials stay two
 * letters, not joined into a word.
 */
final class PersonAvatar
{
    /**
     * [$person] is the account; [$name] and [$email] stand in for it (an invitation's address has no account yet).
     *
     * @return array{photo: string|null, initials: string, color: int}
     */
    public static function of(?User $person, ?string $name = null, ?string $email = null): array
    {
        $name = filled($name) ? $name : (string) $person?->name;
        $email = filled($email) ? $email : (string) $person?->email;
        $initials = InitialsAvatarProvider::initials($name);
        if ($initials === '' && preg_match('/[\p{L}\p{N}]/u', $email, $first) === 1) {
            $initials = mb_strtoupper($first[0]);
        }
        // An Urdu name's two letters would join into one word: a zero-width non-joiner keeps them two letters.
        if (mb_strlen($initials) > 1 && preg_match('/\p{Arabic}/u', $initials) === 1) {
            $initials = implode("\u{200C}", mb_str_split($initials));
        }

        return [
            'photo' => $person !== null ? Avatars::of($person)['avatar_url'] : null,
            'initials' => $initials,
            'color' => $person?->avatarColor() ?? self::colorOf($email),
        ];
    }

    /**
     * A person in a table's cell (x-el.person): their avatar, their name and, under it, [$sub] (their email). The words
     * are escaped by the component; the cell is HTML for Filament's column.
     */
    public static function cell(?User $person, ?string $sub = null, ?string $name = null, ?string $email = null): HtmlString
    {
        return new HtmlString(Blade::render('<x-el.person :person="$person" :name="$name" :email="$email" :sub="$sub" />',
            ['person' => $person, 'name' => $name, 'email' => $email, 'sub' => $sub]));
    }

    /**
     * The colour of an address with no account behind it here (a pending invitation): the same one every time, from
     * the address alone. No account is looked up by the address, so an avatar never tells whether someone has one.
     */
    public static function colorOf(string $email): int
    {
        return (int) (crc32(mb_strtolower(trim($email))) % User::AVATAR_COLORS);
    }
}
