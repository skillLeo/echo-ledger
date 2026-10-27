<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The groups and sections of the console's Settings page (plan B19) and the search over them. Group names match the
 * desktop app and the phone; a section is found by its name, its keywords or its group.
 */
final class SettingsSearch
{
    /** @var array<string, string> group => label, in tab order */
    public const GROUPS = [
        'general' => 'General',
        'recording' => 'Recording',
        'captions' => 'Captions',
        'dictation' => 'Dictation',
        'privacy' => 'Privacy & security',
        'devices' => 'Devices',
        'storage' => 'Storage',
        'about' => 'About',
    ];

    /** @var array<string, array{0: string, 1: string, 2: string}> section => [group, title, keywords] */
    public const SECTIONS = [
        'language' => ['general', 'Language & time', 'default language time zone timezone english urdu hindi automatic'],
        'vocabulary' => ['general', 'Vocabulary', 'keyterms names jargon corrections replace always write words'],
        'engine' => ['recording', 'Transcription engine', 'final transcript voice notes provider assemblyai elevenlabs azure whisper soniox accuracy'],
        'routing' => ['recording', 'Engine per language', 'language urdu hindi english mixed engine routing order fallback soniox elevenlabs assemblyai script'],
        'bookmarks' => ['recording', 'Bookmarks', 'tags markers one tap during a call'],
        'assemblyai' => ['recording', 'AssemblyAI', 'region eu us streaming model final transcript models'],
        'elevenlabs' => ['recording', 'ElevenLabs', 'scribe base url batch model'],
        'azure' => ['recording', 'Azure Speech', 'region endpoint mai transcribe model'],
        'whisper' => ['recording', 'whisper.cpp (self-hosted)', 'server url slice length vad'],
        'captions' => ['captions', 'Live captions', 'engine provider channels client only cost streaming'],
        'dictation' => ['dictation', 'Dictation', 'engine provider voice typing keyboard'],
        'access' => ['privacy', 'Sign-in and access', 'two-factor 2fa passkeys browsers sessions audit log password'],
        'pins' => ['privacy', 'Certificate pins', 'pinning spki sha256 tls certificate security'],
        'devices' => ['devices', 'Paired devices', 'pair revoke phone computer devices page'],
        'retention' => ['storage', 'Storage and retention', 'delete days undo bin recordings transcripts erase 48 hours'],
        'limit' => ['storage', 'Monthly limit', 'budget minutes dollars usd warn percent prices cost usage'],
        'plan' => ['storage', 'Plan limits', 'plan workspace minutes gigabytes pairings quota raise'],
        'about' => ['about', 'About', 'version export import backup settings file json'],
    ];

    /** Whether [$section] belongs in the result of [$query]; every section when the query is blank. */
    public static function matches(?string $query, string $section): bool
    {
        $words = self::words($query);
        if ($words === []) {
            return true;
        }
        [$group, $title, $keywords] = self::SECTIONS[$section];
        $haystack = mb_strtolower("{$title} {$keywords} ".self::GROUPS[$group]);
        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The sections that match [$query]; none for a blank query.
     *
     * @return list<string>
     */
    public static function results(?string $query): array
    {
        if (self::words($query) === []) {
            return [];
        }

        return array_values(array_filter(array_keys(self::SECTIONS), fn (string $id): bool => self::matches($query, $id)));
    }

    /** How many sections of [$group] match [$query]; 0 for a blank query (no badge). */
    public static function count(?string $query, string $group): int
    {
        return count(array_filter(self::results($query), fn (string $id): bool => self::SECTIONS[$id][0] === $group));
    }

    /** The group to jump to for [$query]: the first one with a match. */
    public static function firstGroup(?string $query): ?string
    {
        foreach (array_keys(self::GROUPS) as $group) {
            if (self::count($query, $group) > 0) {
                return $group;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function words(?string $query): array
    {
        return array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $query))) ?: [], fn (string $word): bool => $word !== ''));
    }
}
