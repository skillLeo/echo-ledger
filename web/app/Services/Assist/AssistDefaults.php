<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Models\AssistPrep;
use App\Models\User;

/**
 * A person's Live Assist settings (v1.9 L8, the console's Live Assist → Settings): for each meeting type the mode, the
 * hint level and the language help a new prep starts with when it names none (the device API and the console alike).
 * Without a setting: Balanced, the meeting type's own hint level (the prompt pack's: Outline for an interview), no
 * language help.
 */
final class AssistDefaults
{
    /**
     * The defaults of every meeting type for [$user].
     *
     * @return array<string, array{mode: string, hint_level: string, language_help: string}>
     */
    public static function all(?User $user): array
    {
        $out = [];
        foreach (AssistPrep::MEETING_TYPES as $type) {
            $out[$type] = self::for($user, $type);
        }

        return $out;
    }

    /** @return array{mode: string, hint_level: string, language_help: string} */
    public static function for(?User $user, string $meetingType): array
    {
        $saved = is_array($user?->assist_settings['types'][$meetingType] ?? null) ? $user->assist_settings['types'][$meetingType] : [];
        $pack = PromptPack::shipped()->defaultHintLevel($meetingType);

        return [
            'mode' => in_array($saved['mode'] ?? null, AssistPrep::MODES, true) ? $saved['mode'] : 'balanced',
            'hint_level' => in_array($saved['hint_level'] ?? null, AssistPrep::HINT_LEVELS, true) ? $saved['hint_level']
                : (in_array($pack, AssistPrep::HINT_LEVELS, true) ? $pack : 'full'),
            'language_help' => in_array($saved['language_help'] ?? null, AssistPrep::LANGUAGE_HELP, true) ? $saved['language_help'] : 'off',
        ];
    }

    /**
     * Keeps [$types] (meeting type => mode, hint level, language help) for [$user]; anything unknown is left out.
     *
     * @param  array<string, mixed>  $types
     */
    public static function save(User $user, array $types): void
    {
        $clean = [];
        foreach (AssistPrep::MEETING_TYPES as $type) {
            $row = is_array($types[$type] ?? null) ? $types[$type] : [];
            $clean[$type] = array_filter([
                'mode' => in_array($row['mode'] ?? null, AssistPrep::MODES, true) ? $row['mode'] : null,
                'hint_level' => in_array($row['hint_level'] ?? null, AssistPrep::HINT_LEVELS, true) ? $row['hint_level'] : null,
                'language_help' => in_array($row['language_help'] ?? null, AssistPrep::LANGUAGE_HELP, true) ? $row['language_help'] : null,
            ]);
        }
        $user->forceFill(['assist_settings' => ['types' => $clean]])->save();
    }
}
