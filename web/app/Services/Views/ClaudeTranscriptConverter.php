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
 * line, same index). The model is the console setting `claude.model` (Claude Opus 5.5 by default); refusals fall back
 * server-side on the models that support it.
 */
class ClaudeTranscriptConverter implements TranscriptConverter
{
    /** Models that take the server-side refusal fallback (`fallbacks: "default"`). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    private const SYSTEM = <<<'TXT'
        You convert the lines of a business phone call transcript into two reading views. The input is JSON: the call's
        language and a list of lines, each with an index and its text.

        For every input line return one entry with the same index:
        - "roman": the same words written in Latin letters. Urdu in the common Pakistani Roman Urdu spelling ("aap kaise
          hain", "kal ki meeting reschedule kar dein"); Arabic in a plain, readable Latin transliteration. English
          words inside the line stay exactly as they are. A line that is already in Latin letters is copied unchanged.
          Use an empty string when no Roman view was asked for ("roman_wanted": false).
        - "english": a faithful English translation of the line. Keep it natural but close to the original; a line
          already in English is copied unchanged.

        Rules for both: keep names, numbers, amounts, dates and product names exactly; never add, drop, merge, summarise
        or explain anything; keep the speaker's tone and any uncertainty; keep markers such as "[unclear: ...]" as they
        are. Every input index appears exactly once in the output.
        TXT;

    public function __construct(private readonly CredentialVault $vault, private readonly SettingsRepository $settings) {}

    public function model(): string
    {
        $model = $this->settings->get('claude.model', 'claude-opus-5-5');

        return is_string($model) && $model !== '' ? $model : 'claude-opus-5-5';
    }

    public function convert(array $lines, string $language, bool $roman): array
    {
        $key = $this->vault->get('anthropic', 'api_key');
        if ($key === null || $key === '') {
            throw new RuntimeException('Add the Anthropic API key on API Credentials to make Roman Urdu and English views.');
        }
        $model = $this->model();
        $input = [
            'language' => LanguageChoice::label($language),
            'roman_wanted' => $roman,
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
                $out[(int) $line['index']] = [
                    'roman' => $roman && is_string($line['roman'] ?? null) && trim($line['roman']) !== '' ? trim($line['roman']) : null,
                    'english' => trim((string) ($line['english'] ?? '')),
                ];
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
                        ],
                        'required' => ['index', 'roman', 'english'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['lines'],
            'additionalProperties' => false,
        ];
    }
}
