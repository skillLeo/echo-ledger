<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials avatar rendered as an inline SVG data URI, so the console never asks a third-party avatar service
 * (Filament's default calls ui-avatars.com with the user's name) (A6.W27).
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        return self::dataUri(Filament::getNameForDefaultAvatar($record));
    }

    public static function initials(string $name): string
    {
        $letters = [];
        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // Skip leading punctuation such as "[Ops] Farah" so it does not become an initial.
            $word = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word);
            if ($word !== '') {
                $letters[] = mb_strtoupper(mb_substr($word, 0, 1));
            }
        }
        if (count($letters) > 2) {
            $letters = [$letters[0], $letters[count($letters) - 1]];
        }

        return implode('', $letters);
    }

    public static function dataUri(string $name): string
    {
        $initials = htmlspecialchars(self::initials($name), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Slate[950]);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.$background.'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="600">'.$initials.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
