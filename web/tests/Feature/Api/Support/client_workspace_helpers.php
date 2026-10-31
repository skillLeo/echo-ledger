<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\ClientAi\ClientAi;
use Tests\Feature\Api\Support\Fixtures;

// Shared by the client workspace tests (v1.4): a Claude stand-in and a client call with a finished transcript.

if (function_exists('fakeClientAi')) {
    return;
}

/** Claude stand-in: fixed answers, and what it was asked. */
function fakeClientAi(): object
{
    $fake = new class implements ClientAi
    {
        /** @var list<array{0: string, 1: mixed}> */
        public array $calls = [];

        public function callNotes(string $clientName, array $brief, array $lines, string $language): array
        {
            $this->calls[] = ['notes', $lines];

            return ['sections' => [
                'asked_for' => [['text' => 'A login page', 'lines' => [1], 'verify' => false]],
                'changes' => [['text' => 'Dark mode', 'lines' => [2], 'verify' => false, 'kind' => 'add']],
                'new_items' => [],
                'decisions' => [['text' => 'Launch on November 15', 'lines' => [3], 'verify' => false]],
                // An item citing a line that does not exist is not in the transcript: it must be dropped.
                'to_confirm' => [['text' => 'Payment method', 'lines' => [99], 'verify' => true], ['text' => 'Hosting plan', 'lines' => [2], 'verify' => true]],
                'numbers' => [],
                'my_actions' => [],
            ], 'input_tokens' => 10_000, 'output_tokens' => 1_000, 'model' => 'claude-sonnet-5-5'];
        }

        public function brief(string $clientName, array $documents, array $callNotes): array
        {
            $this->calls[] = ['brief', ['documents' => $documents, 'notes' => $callNotes]];

            return ['sections' => ['goal' => 'An online shop', 'scope' => ['Login', ' '], 'constraints' => [], 'deadlines' => ['Nov 15'], 'budget' => [], 'people' => ['Ali (owner)'], 'glossary' => []],
                'input_tokens' => 5_000, 'output_tokens' => 500, 'model' => 'claude-sonnet-5-5'];
        }

        public function pdfText(string $pdf, string $name): array
        {
            $this->calls[] = ['pdf', $name];

            return ['text' => "=== Page 1 ===\nScope\n\n=== Page 2 ===\nPayment: 50% upfront", 'pages' => 2, 'input_tokens' => 2_000, 'output_tokens' => 400, 'model' => 'claude-haiku-5-5'];
        }

        public function ask(string $clientName, array $brief, array $memory, array $passages, string $question, string $language): array
        {
            $this->calls[] = ['ask', ['passages' => $passages, 'language' => $language, 'memory' => $memory]];

            return ['answer' => 'Launch is on November 15.', 'citations' => [['passage' => 0, 'block' => 3, 'quote' => 'We launch on November 15']],
                'input_tokens' => 3_000, 'output_tokens' => 100, 'model' => 'claude-sonnet-5-5'];
        }

        public function suggestFixes(string $clientName, array $terms, array $lines): array
        {
            $this->calls[] = ['fixes', $terms];

            return ['fixes' => [
                ['line' => 1, 'from' => 'login page', 'to' => 'Login page', 'reason' => 'product name'],
                // Not in that line: dropped.
                ['line' => 0, 'from' => 'La ravel', 'to' => 'Laravel', 'reason' => 'framework'],
            ], 'input_tokens' => 4_000, 'output_tokens' => 200, 'model' => 'claude-sonnet-5-5'];
        }
    };
    app()->instance(ClientAi::class, $fake);

    return $fake;
}

function clientCall(object $test, Client $client): CaptureSession
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(3000), ['client_id' => $client->id, 'status' => 'completed', 'title' => 'Kick-off']);
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en',
        'text' => 'x', 'segments' => [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hi Ali'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'I need a login page'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 61_000, 'end_ms' => 64_000, 'text' => 'Also add dark mode'],
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 125_000, 'end_ms' => 128_000, 'text' => 'We launch on November 15'],
        ]]);

    return $session;
}
