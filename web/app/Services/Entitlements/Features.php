<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

/**
 * The features registry (since v1.6, P1, docs/API.md "Entitlements"): every feature a plan can grant, with its name and
 * description as the apps show them, and whether it is free or costs money to run. Free features are in every plan;
 * paid ones only in the plans that grant them. Also the limits a plan sets and the engines it may use.
 */
final class Features
{
    public const FREE = 'free';

    public const PAID = 'paid';

    /** @var array<string, array{name: string, description: string, cost: string}> */
    public const ALL = [
        'record' => ['name' => 'Record and upload', 'description' => 'Record calls, meetings and voice notes on your phone and computer, and upload files.', 'cost' => self::FREE],
        'organise' => ['name' => 'Organise', 'description' => 'Projects, labels, pins and filing rules for your recordings.', 'cost' => self::FREE],
        'search' => ['name' => 'Search', 'description' => 'Find any word in your recordings and transcripts.', 'cost' => self::FREE],
        'share_links' => ['name' => 'Share links', 'description' => 'Links to a recording or a project that you can take back at any time.', 'cost' => self::FREE],
        'export' => ['name' => 'Export', 'description' => 'Transcripts as text and subtitles.', 'cost' => self::FREE],
        'ready_check' => ['name' => 'Ready check', 'description' => 'Checks that your phone or computer will record your calls.', 'cost' => self::FREE],
        'platform_transcription' => ['name' => 'Transcription on EchoLedger', 'description' => 'Your recordings are transcribed for you, with no key of your own.', 'cost' => self::PAID],
        'views' => ['name' => 'Roman Urdu, English and Urdu', 'description' => 'Read any call in Roman Urdu, English or Urdu, side by side with what was said.', 'cost' => self::PAID],
        'double_check' => ['name' => 'Double-check', 'description' => 'A second engine listens again to the words the first one was unsure of, and to every short note.', 'cost' => self::PAID],
        'screen_moments' => ['name' => 'Screen moments', 'description' => 'What was on the screen during a meeting, next to the words that were said.', 'cost' => self::PAID],
        'connector' => ['name' => 'Claude and ChatGPT', 'description' => 'Ask Claude or ChatGPT about your recordings.', 'cost' => self::PAID],
        'team' => ['name' => 'Team workspace', 'description' => 'Invite people to a shared workspace, with roles and seats.', 'cost' => self::PAID],
        'premium_engines' => ['name' => 'Premium engines', 'description' => 'ElevenLabs and Gemini for the hardest calls and languages.', 'cost' => self::PAID],
        'live_translate' => ['name' => 'Live Translate', 'description' => 'Live subtitles in your language for games and videos.', 'cost' => self::PAID],
        'ai_notes' => ['name' => 'AI notes', 'description' => 'Project notes, memory and Ask about a project.', 'cost' => self::PAID],
    ];

    /** The limits a plan sets, with the plan column each one is kept in. */
    public const LIMITS = [
        'monthly_minutes' => 'monthly_minutes',
        'devices' => 'max_devices',
        'storage_bytes' => 'storage_bytes',
        'retention_days' => 'retention_days',
        'seats' => 'seats',
        'max_file_minutes' => 'max_file_minutes',
        'uploads_per_day' => 'uploads_per_day',
        'ai_usd_month' => 'ai_credits_usd',
    ];

    /** What the limits are called in messages and on the Plans page. */
    public const LIMIT_LABELS = [
        'monthly_minutes' => 'Transcription minutes a month',
        'devices' => 'Devices',
        'storage_bytes' => 'Storage',
        'retention_days' => 'Keeps recordings (days)',
        'seats' => 'People',
        'max_file_minutes' => 'Longest recording (minutes)',
        'uploads_per_day' => 'Recordings a day',
        'ai_usd_month' => 'AI work a month (US$)',
    ];

    /**
     * Engines a plan may use: the person's own Groq key, the desktop's on-device transcription, and the server's own
     * engines (the premium ones are ElevenLabs and Gemini).
     */
    public const ENGINES = ['own_groq', 'on_device', 'assemblyai', 'groq', 'soniox', 'azure_mai', 'elevenlabs', 'gemini'];

    public const PREMIUM_ENGINES = ['elevenlabs', 'gemini'];

    /** Engines that run on the person's own key or device and cost the server nothing. */
    public const OWN_ENGINES = ['own_groq', 'on_device'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /** @return list<string> */
    public static function paid(): array
    {
        return array_keys(array_filter(self::ALL, fn (array $f): bool => $f['cost'] === self::PAID));
    }

    public static function isFree(string $feature): bool
    {
        return (self::ALL[$feature]['cost'] ?? self::PAID) === self::FREE;
    }

    public static function name(string $feature): string
    {
        return self::ALL[$feature]['name'] ?? $feature;
    }
}
