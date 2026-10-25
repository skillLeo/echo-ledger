<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Text replacements of the vocabulary (since v1.1): words a provider keeps getting wrong, fixed in every new final
 * transcript ("acme limited" → "Acme Ltd"). Global rules come from the console settings, client rules from the
 * client; client rules run first. The provider's original stays restorable.
 */
final class TextReplacements
{
    public const MAX_RULES = 200;

    public const MAX_CHARS = 100;

    /**
     * Valid rules from stored or submitted data: `from` 1-100 characters, `to` 0-100, whole word unless switched off.
     *
     * @return list<array{from: string, to: string, match_case: bool, whole_word: bool}>
     */
    public static function rules(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }
        $rules = [];
        $seen = [];
        foreach ($stored as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $from = trim((string) ($rule['from'] ?? ''));
            $to = trim((string) ($rule['to'] ?? ''));
            if ($from === '' || mb_strlen($from) > self::MAX_CHARS || mb_strlen($to) > self::MAX_CHARS || $from === $to) {
                continue;
            }
            $matchCase = (bool) ($rule['match_case'] ?? false);
            $key = ($matchCase ? $from : mb_strtolower($from));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rules[] = ['from' => $from, 'to' => $to, 'match_case' => $matchCase, 'whole_word' => (bool) ($rule['whole_word'] ?? true)];
        }

        return array_slice($rules, 0, self::MAX_RULES);
    }

    /**
     * Applies the rules to every segment's text.
     *
     * @param  list<array<string, mixed>>  $segments
     * @param  list<array{from: string, to: string, match_case: bool, whole_word: bool}>  $rules
     * @return array{0: list<array<string, mixed>>, 1: int} the segments and the number of replacements
     */
    public static function apply(array $segments, array $rules): array
    {
        $count = 0;
        foreach ($segments as $i => $segment) {
            $text = (string) ($segment['text'] ?? '');
            $changed = $text;
            foreach ($rules as $rule) {
                $changed = preg_replace_callback(
                    TranscriptEditor::pattern($rule['from'], $rule['match_case'], $rule['whole_word']),
                    static fn (): string => $rule['to'],
                    $changed,
                    -1,
                    $n,
                ) ?? $changed;
                $count += $n;
            }
            if ($changed !== $text) {
                $clean = TranscriptEditor::cleanText($changed);
                if ($clean !== '') {
                    $segments[$i]['text'] = $clean;
                    unset($segments[$i]['words']);
                }
            }
        }

        return [$segments, $count];
    }
}
