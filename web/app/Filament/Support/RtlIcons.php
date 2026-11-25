<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * W-234 (the lead's RTL icons decision, as the desktop and the phone have it): right to left, the icons that point a
 * way (a row's ›, a back link's ‹) or read as text (the help "?", which is ؟ in Urdu, a chat bubble, a missed call,
 * sign out) are mirrored; the media player's skip back and forward are not. The mirror is the new design's
 * v2-icon-flip ([dir=rtl] in resources/css/v2/console.css), and it is added only while the console reads right to left,
 * so English and classic draw these icons byte for byte as before. A Blade draws one as
 * `@svg(...RtlIcons::of('heroicon-o-chevron-right', ['aria-hidden' => 'true']))`, the directive's own shape.
 */
final class RtlIcons
{
    /** The heroicons mirrored right to left, by name without their style (heroicon-o-, -s-, -m-, -c-). */
    public const MIRRORED = ['chevron-right', 'chevron-left', 'question-mark-circle', 'chat-bubble-left-ellipsis',
        'chat-bubble-bottom-center-text', 'chat-bubble-left-right', 'phone-x-mark', 'arrow-left-start-on-rectangle'];

    /** The class that mirrors an icon right to left. */
    public const CLASS_NAME = 'v2-icon-flip';

    public static function mirrored(string $icon): bool
    {
        return in_array((string) preg_replace('/^heroicon-[osmc]-/', '', $icon), self::MIRRORED, true);
    }

    /**
     * [$attributes], with the mirror's class when the console reads right to left and [$icon] is one to mirror.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function attributes(string $icon, array $attributes = []): array
    {
        if (! self::mirrored($icon) || ! ConsoleV2::rtl()) {
            return $attributes;
        }
        $attributes['class'] = trim(((string) ($attributes['class'] ?? '')).' '.self::CLASS_NAME);

        return $attributes;
    }

    /**
     * The arguments of @svg for [$icon]: its name and its attributes (attributes()).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function of(string $icon, array $attributes = []): array
    {
        return [$icon, self::attributes($icon, $attributes)];
    }
}
