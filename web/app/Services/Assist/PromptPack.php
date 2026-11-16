<?php

declare(strict_types=1);

namespace App\Services\Assist;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The Live Assist prompt pack (since v1.9, L1.5; docs/API_ASSIST.md "Output contract"): resources/prompts/assist holds
 * the system rules and one template per meeting type. A template's context blocks are `{{name}}` placeholders in cache
 * order, what never changes first and the question last, so a provider can keep the start of the prompt cached. Files
 * only: nothing here calls a provider or holds a key. The server endpoint that hands the pack to the relay comes with L1.
 */
final class PromptPack
{
    public const MEETING_TYPES = ['interview', 'boss', 'existing_client', 'new_client', 'general'];

    /** A context block's place in a template. */
    public const PLACEHOLDER = '/\{\{\s*([a-z_]+)\s*\}\}/';

    /** What an empty context block says. */
    public const NONE = '(none)';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(private readonly string $dir) {}

    /** The pack the server ships with. */
    public static function shipped(): self
    {
        return new self(resource_path('prompts/assist'));
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        try {
            $manifest = json_decode($this->read('manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('The prompt pack\'s manifest is not readable: '.$e->getMessage(), previous: $e);
        }
        if (! is_array($manifest)) {
            throw new RuntimeException('The prompt pack\'s manifest is not an object.');
        }

        return $this->manifest = $manifest;
    }

    public function version(): string
    {
        return (string) ($this->manifest()['version'] ?? '');
    }

    /** @return list<string> the context blocks, in cache order */
    public function placeholders(): array
    {
        return $this->strings($this->manifest()['placeholders'] ?? []);
    }

    /** @return list<string> */
    public function hintLevels(): array
    {
        return $this->strings($this->manifest()['hint_levels'] ?? []);
    }

    /** @return list<string> */
    public function languageHelp(): array
    {
        return $this->strings($this->manifest()['language_help'] ?? []);
    }

    public function systemRules(): string
    {
        return $this->read((string) ($this->manifest()['system'] ?? ''));
    }

    /** The meeting type's template as it is written, every placeholder still in it. */
    public function raw(string $meetingType): string
    {
        return $this->read((string) ($this->entry($meetingType)['file'] ?? ''));
    }

    /**
     * The template with the system rules in place: the part that is the same for every call of this meeting type, then
     * the context blocks still to fill.
     */
    public function template(string $meetingType): string
    {
        return self::fill($this->raw($meetingType), ['system_rules' => $this->systemRules()]);
    }

    /**
     * The prompt for one question: every context block of [$blocks] (keyed by placeholder) in its place, NONE for an
     * empty one, the hint level (the meeting type's default when not given) and the language help (off) checked. What
     * the blocks hold is put in as it is: a placeholder inside a transcript stays text.
     *
     * @param  array<string, string|null>  $blocks
     */
    public function render(string $meetingType, array $blocks): string
    {
        $hint = $blocks['hint_level'] ?? $this->defaultHintLevel($meetingType);
        if (! in_array($hint, $this->hintLevels(), true)) {
            throw new InvalidArgumentException("Unknown hint level: {$hint}.");
        }
        $language = $blocks['language_help'] ?? 'off';
        if (! in_array($language, $this->languageHelp(), true)) {
            throw new InvalidArgumentException("Unknown language help: {$language}.");
        }
        $values = ['system_rules' => $this->systemRules(), 'hint_level' => $hint, 'language_help' => $language];
        foreach ($this->placeholders() as $name) {
            if (! array_key_exists($name, $values)) {
                $text = trim((string) ($blocks[$name] ?? ''));
                $values[$name] = $text === '' ? self::NONE : $text;
            }
        }

        return self::fill($this->raw($meetingType), $values);
    }

    public function defaultHintLevel(string $meetingType): string
    {
        return (string) ($this->entry($meetingType)['default_hint_level'] ?? '');
    }

    /** The responsible-use line the app shows once in a session of this meeting type. */
    public function reminder(string $meetingType): string
    {
        $entry = $this->entry($meetingType);

        return ($entry['reminder_kind'] ?? null) === 'consent' ? (string) ($this->manifest()['consent_line'] ?? '') : (string) ($entry['reminder'] ?? '');
    }

    /**
     * The placeholders of [$text], in the order they appear.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return $matches[1];
    }

    /**
     * What is wrong with the pack, in plain words; empty when nothing is: a missing file, a meeting type without a
     * template, or a template whose placeholders are missing, repeated, unknown or out of order.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        try {
            $order = $this->placeholders();
            if (self::placeholdersIn($this->systemRules()) !== []) {
                $problems[] = 'The system rules hold placeholders.';
            }
            foreach (self::MEETING_TYPES as $type) {
                $found = self::placeholdersIn($this->raw($type));
                if ($found !== $order) {
                    $problems[] = "{$type}: placeholders ".implode(', ', $found).' instead of '.implode(', ', $order).'.';
                }
                if (! in_array($this->defaultHintLevel($type), $this->hintLevels(), true)) {
                    $problems[] = "{$type}: no known default hint level.";
                }
                if (trim($this->reminder($type)) === '') {
                    $problems[] = "{$type}: no responsible-use line.";
                }
            }
        } catch (RuntimeException|InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }

    /**
     * [$template] with the [$values] placeholders filled in one pass: text put in is never read for placeholders again.
     *
     * @param  array<string, string>  $values
     */
    private static function fill(string $template, array $values): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $m): string => $values[$m[1]] ?? $m[0], $template);
    }

    /** @return array<string, mixed> */
    private function entry(string $meetingType): array
    {
        $entry = $this->manifest()['templates'][$meetingType] ?? null;
        if (! in_array($meetingType, self::MEETING_TYPES, true) || ! is_array($entry)) {
            throw new InvalidArgumentException("Unknown meeting type: {$meetingType}.");
        }

        return $entry;
    }

    /** A file of the pack's own folder (no paths). */
    private function read(string $file): string
    {
        if ($file === '' || basename($file) !== $file) {
            throw new RuntimeException("The prompt pack names a file it cannot have: {$file}.");
        }
        $path = rtrim($this->dir, '/').'/'.$file;
        $text = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException("The prompt pack has no {$file}.");
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_map('strval', array_filter($list, 'is_string'))) : [];
    }
}
