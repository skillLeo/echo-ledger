<?php

declare(strict_types=1);

namespace App\Services\ClientAi;

use Anthropic\Client;
use App\Services\CredentialVault;
use App\Services\Views\TranscriptViews;
use RuntimeException;

/**
 * Client workspaces on Claude (v1.4, P4): Sonnet 5.5 writes the call notes, the brief and the answers; Haiku 5.5 reads
 * PDFs into text. The fixed instructions are cached; nothing is trained (Anthropic's API terms: docs/research/).
 */
class ClaudeClientAi implements ClientAi
{
    public const MODEL = 'claude-sonnet-5-5';

    public const CHEAP_MODEL = 'claude-haiku-5-5';

    private const LANGUAGES = [
        'en' => 'Write in plain English.',
        'roman_urdu' => 'Write in Roman Urdu (Urdu in Latin letters, the way Pakistanis type it; English terms stay in English).',
    ];

    private const NOTES = <<<'TXT'
        You write the notes of one business call (or voice note) for a freelance developer, from its transcript. The
        input is JSON: the client's name, the client's brief (may be empty) and the transcript lines, each with an
        index "i", its time "t", the speaker ("You" is the developer) and the text.

        Return only what was said. Never add, guess or complete anything that is not in the lines; when a section has
        nothing, return an empty list for it. Each item is one short line (at most 25 words) with "lines": the indexes
        of the lines it comes from (at least one). Set "verify" to true when the lines are unclear, contain "[?word]" or
        "[unclear]", contradict each other, or you are not sure the item is right.

        Sections:
        - asked_for: what the client asked for.
        - changes: changes to earlier plans, each with "kind": add, remove or modify.
        - new_items: things asked for that are not in the brief (empty when there is no brief).
        - decisions: what was agreed.
        - to_confirm: open points the developer should confirm with the client.
        - numbers: deadlines, dates, prices, amounts and other numbers, exactly as said.
        - my_actions: what the developer said they would do.
        TXT;

    private const BRIEF = <<<'TXT'
        You write the one-page brief of a client project for a freelance developer. Use only the documents and call
        notes given; never invent. Keep it short: the goal in one sentence; every other section a list of short lines
        (at most 8 lines each, at most 20 words a line), empty when the material says nothing about it. Sections:
        goal; scope (features and deliverables); constraints (technical or business limits); deadlines; budget (prices,
        payment terms); people (names and roles); glossary (terms, product names and what they mean, as
        "Term: meaning"). Write in plain English; keep names, product names and numbers exactly as written.
        TXT;

    private const PDF_TEXT = <<<'TXT'
        Copy the text of this document exactly, page by page, without commentary. Before each page write
        "=== Page N ===" on its own line. Keep headings, lists and table rows as plain text lines.
        TXT;

    private const ASK = <<<'TXT'
        You answer a freelance developer's question about one client, using only the documents given: the client's
        brief and memory, and passages from call transcripts and the client's files. Answer briefly (at most 120 words)
        and cite the passages you rely on. If the documents do not answer the question, say so in one sentence and
        do not guess. Dates, prices and names exactly as written.
        TXT;

    private const FIXES = <<<'TXT'
        You check a call transcript for words the speech engine most likely misheard. The input is JSON: the client's
        name, the client's vocabulary (names, products, technical terms) and the transcript lines, each with an index
        "i". Flag only clear cases where a word or a short phrase sounds like a vocabulary term or name and that term
        fits what is being said ("La ravel" → "Laravel", "react native" → "React Native"). "from" is copied exactly
        from the line; "to" is the fix; "reason" is a few words. Never rephrase, fix grammar, translate or touch words
        that are fine. At most 10 fixes; none is a fine answer.
        TXT;

    /** USD per million tokens [input, output]. */
    public const PRICES = [
        self::MODEL => [2.00, 10.00],
        self::CHEAP_MODEL => [0.10, 0.50],
    ];

    public function __construct(private readonly CredentialVault $vault) {}

    public function callNotes(string $clientName, array $brief, array $lines, string $language): array
    {
        $item = [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'lines' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'verify' => ['type' => 'boolean'],
            ],
            'required' => ['text', 'lines', 'verify'],
            'additionalProperties' => false,
        ];
        $change = $item;
        $change['properties']['kind'] = ['type' => 'string', 'enum' => ['add', 'remove', 'modify']];
        $change['required'][] = 'kind';
        $properties = [];
        foreach (['asked_for', 'changes', 'new_items', 'decisions', 'to_confirm', 'numbers', 'my_actions'] as $key) {
            $properties[$key] = ['type' => 'array', 'items' => $key === 'changes' ? $change : $item];
        }
        $schema = ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $input = ['client' => $clientName, 'brief' => $brief, 'lines' => $lines];

        $message = $this->send(self::MODEL, self::NOTES.' '.self::languageRule($language), [
            ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ], ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]]);
        $data = json_decode(self::text($message), true);
        if (! is_array($data)) {
            throw new RuntimeException('Claude did not return the notes in the expected form.');
        }

        return ['sections' => $data] + self::usage($message);
    }

    public function brief(string $clientName, array $documents, array $callNotes): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];
        $schema = [
            'type' => 'object',
            'properties' => ['goal' => ['type' => 'string'], 'scope' => $list, 'constraints' => $list, 'deadlines' => $list, 'budget' => $list, 'people' => $list, 'glossary' => $list],
            'required' => ['goal', 'scope', 'constraints', 'deadlines', 'budget', 'people', 'glossary'],
            'additionalProperties' => false,
        ];
        $content = [];
        foreach ($documents as $document) {
            $content[] = isset($document['pdf'])
                ? ['type' => 'document', 'title' => $document['title'], 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($document['pdf'])]]
                : ['type' => 'document', 'title' => $document['title'], 'source' => ['type' => 'text', 'mediaType' => 'text/plain', 'data' => (string) ($document['text'] ?? '')]];
        }
        $request = "Client: {$clientName}.";
        if ($callNotes !== []) {
            $request .= "\n\nNotes of the calls so far (oldest first):\n\n".implode("\n\n", $callNotes);
        }
        $content[] = ['type' => 'text', 'text' => $request."\n\nWrite the brief."];

        $message = $this->send(self::MODEL, self::BRIEF, [['role' => 'user', 'content' => $content]], [
            'effort' => 'medium',
            'format' => ['type' => 'json_schema', 'schema' => $schema],
        ]);
        $data = json_decode(self::text($message), true);
        if (! is_array($data)) {
            throw new RuntimeException('Claude did not return the brief in the expected form.');
        }

        return ['sections' => $data] + self::usage($message);
    }

    public function pdfText(string $pdf, string $name): array
    {
        $message = $this->send(self::CHEAP_MODEL, self::PDF_TEXT, [[
            'role' => 'user',
            'content' => [
                ['type' => 'document', 'title' => $name, 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($pdf)]],
                ['type' => 'text', 'text' => 'Copy the text of this document.'],
            ],
        ]], ['effort' => 'low'], fallback: false);
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('This file is too long to read in one go. Split it into smaller files.');
        }
        $text = trim(self::text($message));

        return ['text' => $text, 'pages' => max(1, substr_count($text, '=== Page '))] + self::usage($message);
    }

    public function ask(string $clientName, array $brief, array $memory, array $passages, string $question, string $language): array
    {
        $about = "Brief of {$clientName}:\n".json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            ."\n\nMemory:\n".($memory === [] ? '(empty)' : implode("\n", $memory));
        // Every document cites (all or none): the brief and memory first, then one document per passage, a block per line.
        // The brief and memory change rarely: cached, so the next questions about this client cost less.
        $content = [['type' => 'document', 'title' => 'Brief and memory', 'citations' => ['enabled' => true], 'cacheControl' => ['type' => 'ephemeral'], 'source' => ['type' => 'text', 'mediaType' => 'text/plain', 'data' => $about]]];
        foreach ($passages as $passage) {
            $blocks = array_map(fn (string $line): array => ['type' => 'text', 'text' => $line], $passage['blocks'] === [] ? [' '] : $passage['blocks']);
            $content[] = ['type' => 'document', 'title' => $passage['title'], 'citations' => ['enabled' => true], 'source' => ['type' => 'content', 'content' => $blocks]];
        }
        $content[] = ['type' => 'text', 'text' => $question];

        $message = $this->send(self::MODEL, self::ASK.' '.self::languageRule($language), [['role' => 'user', 'content' => $content]], ['effort' => 'low']);
        $answer = '';
        $citations = [];
        foreach ($message->content as $block) {
            if (($block->type ?? null) !== 'text') {
                continue;
            }
            $answer .= $block->text;
            foreach ($block->citations ?? [] as $citation) {
                // Document 0 is the brief and memory; passages start at 1.
                $passage = (int) ($citation->documentIndex ?? 0) - 1;
                if ($passage < 0) {
                    continue;
                }
                $citations[] = [
                    'passage' => $passage,
                    'block' => (int) ($citation->startBlockIndex ?? 0),
                    'quote' => mb_substr((string) ($citation->citedText ?? ''), 0, 300),
                ];
            }
        }

        return ['answer' => trim($answer), 'citations' => $citations] + self::usage($message);
    }

    public function suggestFixes(string $clientName, array $terms, array $lines): array
    {
        $fix = [
            'type' => 'object',
            'properties' => ['line' => ['type' => 'integer'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'reason' => ['type' => 'string']],
            'required' => ['line', 'from', 'to', 'reason'],
            'additionalProperties' => false,
        ];
        $schema = ['type' => 'object', 'properties' => ['fixes' => ['type' => 'array', 'items' => $fix]], 'required' => ['fixes'], 'additionalProperties' => false];
        $input = ['client' => $clientName, 'vocabulary' => $terms, 'lines' => $lines];
        $message = $this->send(self::MODEL, self::FIXES, [
            ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ], ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]]);
        $data = json_decode(self::text($message), true);

        return ['fixes' => is_array($data['fixes'] ?? null) ? $data['fixes'] : []] + self::usage($message);
    }

    private static function languageRule(string $language): string
    {
        return self::LANGUAGES[$language] ?? self::LANGUAGES['en'];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $outputConfig
     */
    private function send(string $model, string $system, array $messages, array $outputConfig, bool $fallback = true): object
    {
        $key = $this->vault->get('anthropic', 'api_key');
        if ($key === null || $key === '') {
            throw new RuntimeException(TranscriptViews::missingKeyMessage());
        }
        $arguments = [
            'model' => $model,
            'maxTokens' => 16000,
            'system' => [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            'messages' => $messages,
            'outputConfig' => $outputConfig,
            'requestOptions' => ['timeout' => 300.0, 'maxRetries' => 2],
        ];
        // A refusal is retried server-side on another model (Sonnet 5.5 supports the default list; Haiku has none).
        if ($fallback && $model === self::MODEL) {
            $arguments['fallbacks'] = 'default';
            $arguments['betas'] = ['server-side-fallback-2026-07-01'];
        }
        $message = $this->client($key)->beta->messages->create(...$arguments);
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude declined this request.');
        }

        return $message;
    }

    private static function text(object $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /** @return array{input_tokens: int, output_tokens: int, model: string} */
    private static function usage(object $message): array
    {
        return [
            'input_tokens' => $message->usage->inputTokens + (int) $message->usage->cacheReadInputTokens + (int) $message->usage->cacheCreationInputTokens,
            'output_tokens' => $message->usage->outputTokens,
            'model' => (string) $message->model,
        ];
    }

    protected function client(string $key): Client
    {
        return new Client(apiKey: $key);
    }
}
