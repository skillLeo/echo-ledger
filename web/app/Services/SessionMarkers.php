<?php

declare(strict_types=1);

namespace App\Services;

/** Bookmarks and paused stretches of a recording (docs/API.md "markers" and "pauses"). */
final class SessionMarkers
{
    /** One-tap tags offered during a call; editable in the console (Settings → Bookmarks). */
    public const DEFAULT_TAGS = ['Requirement', 'Budget', 'Deadline', 'Decision', 'Follow-up'];

    public const MAX_MARKERS = 500;

    public const MAX_PAUSES = 200;

    /**
     * Validation rules for the optional `markers` and `pauses` lists sent with a recording.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'markers' => ['sometimes', 'array', 'max:'.self::MAX_MARKERS],
            'markers.*' => ['array'],
            'markers.*.at_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'markers.*.tag' => ['nullable', 'string', 'max:40'],
            'markers.*.note' => ['nullable', 'string', 'max:200'],
            'pauses' => ['sometimes', 'array', 'max:'.self::MAX_PAUSES],
            'pauses.*' => ['array'],
            'pauses.*.at_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'pauses.*.paused_ms' => ['required', 'integer', 'min:1', 'max:86400000'],
        ];
    }

    /**
     * Validation rules for one bookmark added later (device or console).
     *
     * @return array<string, list<mixed>>
     */
    public static function markerRules(): array
    {
        return [
            'at_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'tag' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** A tag as stored: trimmed, blank means none. */
    public static function cleanTag(mixed $tag): ?string
    {
        return self::cleanText($tag);
    }

    public static function cleanText(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * The tag presets from the console settings: trimmed, unique, at most 12 tags of 40 characters; the defaults when
     * the setting is empty or broken.
     *
     * @return list<string>
     */
    public static function presets(mixed $stored): array
    {
        if (! is_array($stored)) {
            return self::DEFAULT_TAGS;
        }
        $tags = [];
        foreach ($stored as $tag) {
            $clean = self::cleanTag($tag);
            if ($clean !== null && mb_strlen($clean) <= 40 && ! in_array($clean, $tags, true)) {
                $tags[] = $clean;
            }
        }

        return $tags === [] ? self::DEFAULT_TAGS : array_slice($tags, 0, 12);
    }
}
