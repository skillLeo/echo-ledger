<?php

declare(strict_types=1);

namespace App\Services\Views;

use Anthropic\Client;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Stt\LanguageChoice;
use RuntimeException;

/**
 * "Show as" with the Claude API (official PHP SDK): one request per chunk of lines, structured JSON back (one entry per
 * line, same index). The model is the console setting `claude.model` (Claude Sonnet 5.5 by default, plan A3); refusals fall back
 * server-side on the models that support it.
 */
class ClaudeTranscriptConverter implements TranscriptConverter
{
    /** Models that take the server-side refusal fallback (`fallbacks: "default"`). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    private const SYSTEM = <<<'TXT'
        You make reading views of the lines of a business phone call transcript from Pakistan. The input is JSON: the
        language the call was transcribed in ("language", its code in "language_code"), the views wanted
        ("views_wanted") and a list of lines, each with an index and its text. Urdu calls mix in English words all the
        time; the engine sometimes wrote those English words in Urdu letters.

        For every input line return one entry with the same index and the fields "roman", "urdu" and "english" (an
        empty string for a view that was not wanted):
        - "roman": Roman Urdu, transliteration only. Write the Urdu words in Latin letters the way Pakistanis type them
          ("kya kar rahe ho", "kaise ho", "theek hai", "acha", "nahi", "meeting kab hai?"). English words stay in their
          normal English spelling, also when the engine wrote them in Urdu letters ("میٹنگ" becomes "meeting", "انوائس"
          becomes "invoice"). Never translate, reword, reorder, fix grammar, add or drop words. A line already in Latin
          letters is copied unchanged.
        - "urdu": when language_code is "ur", the same words with the Urdu words in Urdu script and the English words in
          English letters ("میٹنگ" becomes "meeting"); never translate or reword. For any other language, a faithful Urdu
          translation in Urdu script, the way a Pakistani business person writes; names, brands and technical terms
          may stay in English letters.
        - "english": a faithful English translation. A line already in English is copied unchanged.

        Rules for every view: names, numbers, amounts, dates and product names exactly as spoken; never merge, split,
        summarise or explain lines; markers stay on the same word: "[unclear]" and "[unclear: ...]" are copied as they
        are, and a word the engine was unsure of, written "[?word]", keeps its brackets around that word's own
        transliteration or translation; never use Hindi (Devanagari) letters. Every input index appears exactly once in
        the output.
        TXT;

    public function __construct(private readonly CredentialVault $vault, private readonly SettingsRepository $settings) {}

    public function model(): string
    {
        $model = $this->settings->get('claude.model', 'claude-sonnet-5-5');

        return is_string($model) && $model !== '' ? $model : 'claude-sonnet-5-5';
    }

    public function convert(array $lines, string $language, array $views): array
    {
        $key = $this->vault->get('anthropic', 'api_key');
        if ($key === null || $key === '') {
            throw new RuntimeException(TranscriptViews::missingKeyMessage());
        }
        $model = $this->model();
        $input = [
            'language' => LanguageChoice::label($language),
            'language_code' => $language,
            'views_wanted' => $views,
            'lines' => array_map(fn (int $i, string $text): array => ['index' => $i, 'text' => $text], array_keys($lines), array_values($lines)),
        ];
        $arguments = [
            'model' => $model,
            'maxTokens' => 16000,
            'system' => [['type' => 'text', 'text' => self::SYSTEM, 'cacheControl' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
            'outputConfig' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            'requestOptions' => ['timeout' => 300.0, 'maxRetries' => 2],
        ];
        // A refusal is retried server-side on another model (the models that support it).
        if (in_array($model, self::FALLBACK_MODELS, true)) {
            $arguments['fallbacks'] = 'default';
            $arguments['betas'] = ['server-side-fallback-2026-07-01'];
        }
        $message = $this->client($key)->beta->messages->create(...$arguments);

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude declined to convert this transcript.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new TooLongForOneRequest('The views of these lines did not fit in one answer.');
        }
        $json = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $json .= $block->text;
            }
        }
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['lines'] ?? null)) {
            throw new RuntimeException('Claude did not return the views in the expected form.');
        }
        $out = [];
        foreach ($data['lines'] as $line) {
            if (is_array($line) && isset($line['index']) && array_key_exists((int) $line['index'], $lines)) {
                $out[(int) $line['index']] = [];
                foreach ($views as $view) {
                    $text = is_string($line[$view] ?? null) ? trim($line[$view]) : '';
                    if ($text !== '') {
                        $out[(int) $line['index']][$view] = $text;
                    }
                }
            }
        }

        return [
            'lines' => $out,
            'input_tokens' => $message->usage->inputTokens + (int) $message->usage->cacheReadInputTokens + (int) $message->usage->cacheCreationInputTokens,
            'output_tokens' => $message->usage->outputTokens,
            'model' => $message->model,
        ];
    }

    protected function client(string $key): Client
    {
        return new Client(apiKey: $key);
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'roman' => ['type' => 'string'],
                            'english' => ['type' => 'string'],
                            'urdu' => ['type' => 'string'],
                        ],
                        'required' => ['index', 'roman', 'english', 'urdu'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['lines'],
            'additionalProperties' => false,
        ];
    }
}
