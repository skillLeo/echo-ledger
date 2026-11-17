<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Prompt;

use App\Services\Assist\Http\Origin;
use App\Services\Assist\Http\Pricing;
use App\Services\Assist\PromptPack;

/**
 * Building what a model reads (services/assist-relay/src/context.rs `ContextBuilder`): the pack's template for the
 * meeting type with every block filled, in the template's cache order, cut into segments at the blocks:
 *
 * 1. the system rules (cache point; the same for every session), the meeting's lines and the brief, the documents, the
 *    project memory (cache point), then the facts ledger and the running summary;
 * 2. the older transcript in blocks of about 1,500 tokens (cache point after the last full block);
 * 3. the last turns word for word, "Now" (hint level and language help), and the question.
 *
 * The longest `recent` scope (Fast) leaves the older blocks out; every mode gets the facts and the summary.
 */
final class PromptBuilder
{
    /** The relay's own lines for asks the pack has no wording for (help now and the rewrites). */
    private const PARTIAL_NONE = '(none)';

    private const HELP_NOW = '(Me asked for help now: suggest what Me can say next.)';

    private const HELP_NOW_OPENING = '(Me asked for help now, before Them said anything: suggest how Me can open the call, from the brief.)';

    private const REGENERATE = 'Write a different suggestion for these words. Do not repeat the previous one.';

    private const SHORTER = 'Make the previous suggestion shorter: the first line only, at most 12 words, and at most one bullet.';

    private const FORMAL = 'Rewrite the previous suggestion in a more formal, polite tone. Keep the same facts; do not make it longer.';

    private const TRANSLATE = 'Explain the previous suggestion in {language} in one or two simple lines, then give the English line to say.';

    private const REWRITE_NOW = "\n\n## Now\nRewrite the previous suggestion below. This is not a new answer: do not output SKIP, keep its facts, and add nothing that is not in the brief, the documents or the call.\n{target}\n\n## The suggestion to rewrite\nWhat Them said, the previous suggestion, and what Me asked for.\n\n";

    private const REWRITE_REGENERATE = 'Language: English. Write a different suggestion with the same shape as the previous one: a first line of at most 25 words, at most 3 bullets with the key words in **bold**.';

    private const REWRITE_SHORTER = 'Language: English. Only a first line of at most 12 words, and at most one bullet.';

    private const REWRITE_FORMAL = 'Language: English. A formal, polite tone; the same shape and length as the previous suggestion.';

    private const REWRITE_TRANSLATE = 'Language: {language}, then English. First one or two simple lines in {language} that start with "{language}:" and explain the previous suggestion; then one line that starts with "Say:" and gives the English words to say.';

    private const EARLIER_DROPPED = '(The earliest parts of the call are left out; the running summary covers them.)';

    private const OLDER_LEFT_OUT = '(not included in this mode; the running summary covers it)';

    public const LANGUAGES = ['urdu' => 'Urdu', 'roman_urdu' => 'Roman Urdu', 'arabic' => 'Arabic'];

    /** @var array<string, list<string>> */
    private array $pieces = [];

    public function __construct(private readonly PromptPack $pack) {}

    public static function shipped(): self
    {
        return new self(PromptPack::shipped());
    }

    /**
     * @param  array<string, mixed>  $call  the LiveContext: brief, settings, documents, memory, facts, summary
     * @param  string  $scope  the mode's transcript scope: `recent` or `full`
     */
    public function build(array $call, Transcript $transcript, Origin $origin, string $scope): Prompt
    {
        $brief = is_array($call['brief'] ?? null) ? $call['brief'] : [];
        $settings = is_array($call['settings'] ?? null) ? $call['settings'] : [];
        $meeting = in_array($brief['meeting_type'] ?? null, PromptPack::MEETING_TYPES, true) ? (string) $brief['meeting_type'] : 'general';
        $piece = $this->pieces($meeting);
        $block = fn (int $i, string $value): string => $piece[$i].self::orNone($value);
        $facts = array_map(fn (mixed $f): string => '- '.trim((string) $f), array_filter(array_map('strval', (array) ($call['facts'] ?? [])), fn (string $f): bool => trim($f) !== ''));
        $segments = [
            self::segment('rules', 'system', $piece[0].$this->pack->systemRules(), true),
            self::segment('brief', 'system', $block(1, self::brief($brief))),
            self::segment('documents', 'system', $block(2, self::documents((array) ($call['documents'] ?? [])))),
            self::segment('memory', 'system', $block(3, (string) ($call['memory'] ?? '')), true),
            self::segment('facts', 'user', $block(4, implode("\n", $facts))),
            self::segment('summary', 'user', $block(5, (string) ($call['summary'] ?? ''))),
        ];
        $blocks = [];
        if ($scope === 'full') {
            $blocks = $transcript->blocks();
            if ($transcript->dropped() > 0 && $blocks !== []) {
                $blocks[0]['text'] = self::EARLIER_DROPPED."\n\n".$blocks[0]['text'];
            }
        }
        if ($blocks === []) {
            $value = $transcript->hasOlder() && $scope === 'recent' ? self::OLDER_LEFT_OUT : self::PARTIAL_NONE;
            $segments[] = self::segment('transcript', 'user', $piece[6].$value);
        } else {
            // Only the last closed block is a cache point (the earlier ones are inside its prefix).
            $lastClosed = null;
            foreach ($blocks as $i => $b) {
                if ($b['closed']) {
                    $lastClosed = $i;
                }
            }
            foreach ($blocks as $i => $b) {
                $text = $i === 0 ? $piece[6].$b['text'] : "\n\n".$b['text'];
                $segments[] = self::segment('transcript', 'user', $text, $i === $lastClosed);
            }
        }
        $skip = $origin->kind === Origin::ANSWER ? $origin->turnId : null;
        $latest = array_map(Transcript::line(...), array_values(array_filter($transcript->latest(), fn (array $t): bool => $t['id'] !== $skip)));
        $segments[] = self::segment('last_turns', 'user', $block(7, implode("\n", $latest)));
        $hint = in_array($settings['hint_level'] ?? null, $this->pack->hintLevels(), true) ? (string) $settings['hint_level'] : $this->pack->defaultHintLevel($meeting);
        $language = in_array($settings['language_help'] ?? null, $this->pack->languageHelp(), true) ? (string) $settings['language_help'] : 'off';
        $now = $origin->isRewrite() ? self::rewriteNow($origin) : $piece[8].$hint.$piece[9].$language.$piece[10];
        $segments[] = self::segment('now', 'user', $now);
        $segments[] = self::segment('question', 'user', self::question($origin).$piece[11]);

        return new Prompt($segments);
    }

    /** @return list<string> */
    private function pieces(string $meeting): array
    {
        return $this->pieces[$meeting] ??= $this->pack->pieces($meeting);
    }

    /** @return array{kind: string, role: string, text: string, tokens: int, cache: bool} */
    private static function segment(string $kind, string $role, string $text, bool $cache = false): array
    {
        return ['kind' => $kind, 'role' => $role, 'text' => $text, 'tokens' => Pricing::estimateTokens($text), 'cache' => $cache];
    }

    private static function orNone(string $value): string
    {
        $value = trim($value);

        return $value === '' ? self::PARTIAL_NONE : $value;
    }

    /** A rewrite's own Now block: no SKIP, no hint-level shape, and the language to write in. */
    private static function rewriteNow(Origin $origin): string
    {
        $label = self::LANGUAGES[(string) $origin->language] ?? 'English';
        $target = match ($origin->kind) {
            'regenerate' => self::REWRITE_REGENERATE,
            'shorter' => self::REWRITE_SHORTER,
            'formal' => self::REWRITE_FORMAL,
            default => str_replace('{language}', $label, self::REWRITE_TRANSLATE),
        };

        return str_replace('{target}', $target, self::REWRITE_NOW);
    }

    /** The question block: Them's words, and for help now and the rewrites what Me asked for. */
    private static function question(Origin $origin): string
    {
        if ($origin->kind === Origin::ANSWER) {
            return trim($origin->question);
        }
        if ($origin->kind === Origin::HELP_NOW) {
            return trim($origin->question) !== '' ? trim($origin->question)."\n\n".self::HELP_NOW : self::HELP_NOW_OPENING;
        }
        $label = self::LANGUAGES[(string) $origin->language] ?? 'English';
        $ask = match ($origin->kind) {
            'regenerate' => self::REGENERATE,
            'shorter' => self::SHORTER,
            'formal' => self::FORMAL,
            default => str_replace('{language}', $label, self::TRANSLATE),
        };
        $words = trim($origin->question) === '' ? '(Me asked for help.)' : trim($origin->question);

        return $words."\n\nThe previous suggestion:\n".trim((string) $origin->previous)."\n\n".$ask;
    }

    /** @param  array<string, mixed>  $brief */
    private static function brief(array $brief): string
    {
        $person = trim((string) ($brief['counterpart'] ?? ''));
        $company = trim((string) ($brief['company'] ?? ''));
        $with = match (true) {
            $person === '' && $company === '' => '',
            $company === '' => $person,
            $person === '' => $company,
            default => "{$person} ({$company})",
        };
        $lines = [];
        foreach ([
            'Title' => trim((string) ($brief['title'] ?? '')), 'With' => $with, 'Goals' => trim((string) ($brief['goals'] ?? '')),
            'Done' => trim((string) ($brief['done'] ?? '')), 'Not done' => trim((string) ($brief['not_done'] ?? '')),
            'To discuss' => trim((string) ($brief['to_discuss'] ?? '')), "Me's instructions" => trim((string) ($brief['instructions'] ?? '')),
        ] as $label => $value) {
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    /** @param  array<int, mixed>  $documents */
    private static function documents(array $documents): string
    {
        $parts = [];
        foreach ($documents as $document) {
            $text = trim((string) (is_array($document) ? ($document['text'] ?? '') : ''));
            if ($text === '') {
                continue;
            }
            $title = trim((string) ($document['title'] ?? ''));
            $parts[] = '['.($title === '' ? (string) ($document['id'] ?? '') : $title)."]\n".$text;
        }

        return implode("\n\n", $parts);
    }
}
