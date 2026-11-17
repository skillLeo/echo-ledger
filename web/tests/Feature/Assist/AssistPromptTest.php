<?php

declare(strict_types=1);

use App\Services\Assist\Http\Origin;
use App\Services\Assist\Http\Prompt\PromptBuilder;
use App\Services\Assist\Http\Prompt\Transcript;

/*
 * What HTTP mode sends a model (services/assist-relay/src/context.rs): the pack's template with every block filled, in
 * cache order, cut into segments at the blocks; the transcript in blocks that never change once closed.
 */

/** @return array{id: string, channel: string, text: string, start_ms: int} */
function assistTurn(int $i, string $channel = 'other', int $words = 60): array
{
    return ['id' => "t{$i}", 'channel' => $channel, 'text' => trim("turn {$i} ".str_repeat('word ', $words)), 'start_ms' => $i * 7000];
}

it('keeps the closed transcript blocks as they are while the call goes on, so each stays in the provider\'s cache', function () {
    $transcript = Transcript::fromConfig();
    foreach (range(0, 39) as $i) {
        $transcript->push(assistTurn($i, $i % 2 === 0 ? 'other' : 'me'));
    }
    $first = array_column(array_filter($transcript->blocks(), fn (array $b): bool => $b['closed']), 'text');
    expect($first)->not->toBe([]);

    foreach (range(40, 79) as $i) {
        $transcript->push(assistTurn($i));
    }
    $later = array_column(array_filter($transcript->blocks(), fn (array $b): bool => $b['closed']), 'text');
    expect(count($later))->toBeGreaterThan(count($first))->and(array_slice($later, 0, count($first)))->toBe($first)
        ->and(count($transcript->latest()))->toBe(8);
});

it('keeps a long call bounded: the oldest blocks go together and the rest keep their part numbers', function () {
    $transcript = Transcript::fromConfig();
    $long = str_repeat('word ', 799);
    foreach (range(0, 1500) as $i) {
        $transcript->push(['id' => "t{$i}", 'channel' => $i % 2 === 0 ? 'other' : 'me', 'text' => "turn {$i} {$long}", 'start_ms' => $i * 12000]);
        expect($transcript->fullBlockTokens())->toBeLessThanOrEqual(40000);
    }
    expect($transcript->dropped())->toBeGreaterThan(0)->and($transcript->find('t1500'))->not->toBeNull()->and($transcript->find('t5'))->toBeNull();
    $blocks = $transcript->blocks();
    expect($blocks[0]['text'])->toStartWith('Part '.($transcript->dropped() + 1)."\n");
});

it('fills every block of the template in cache order and puts the question last', function () {
    $builder = PromptBuilder::shipped();
    $call = ['brief' => ['meeting_type' => 'interview', 'title' => 'Backend role', 'counterpart' => 'Sara', 'company' => 'Acme', 'goals' => 'Get the offer'],
        'settings' => ['hint_level' => 'keywords', 'language_help' => 'roman_urdu'], 'documents' => [['id' => 'cv', 'title' => 'CV', 'text' => 'Payments engineer.']],
        'memory' => 'Budget 5,000 USD.', 'facts' => ['Them: the budget is 8,000 USD (minute 2)'], 'summary' => 'Salary talk.'];
    $transcript = Transcript::fromConfig();
    $transcript->push(assistTurn(1, 'me', 3));
    $transcript->push(['id' => 'o-1', 'channel' => 'other', 'text' => 'What is your notice period?', 'start_ms' => 61000]);

    $prompt = $builder->build($call, $transcript, Origin::answer('o-1', 'What is your notice period?'), 'full');
    $text = $prompt->text();

    expect($text)->not->toContain('{{')
        ->and($text)->toContain("Title: Backend role\nWith: Sara (Acme)\nGoals: Get the offer")
        ->and($text)->toContain('[CV]'."\nPayments engineer.")->toContain('Budget 5,000 USD.')->toContain('- Them: the budget is 8,000 USD (minute 2)')
        ->and($text)->toContain("Hint level: keywords\nLanguage help: roman_urdu")
        ->and($text)->toContain('[00:07] Me: turn 1')
        // The question itself is not repeated among the last turns.
        ->and(substr_count($text, 'What is your notice period?'))->toBe(1)
        ->and($text)->toEndWith("What is your notice period?\n");
    // The order of the blocks: rules, brief, documents, memory, facts, summary, transcript, last turns, now, question.
    $kinds = array_column($prompt->segments, 'kind');
    expect(array_values(array_unique($kinds)))->toBe(['rules', 'brief', 'documents', 'memory', 'facts', 'summary', 'transcript', 'last_turns', 'now', 'question']);
    // The system part first, the rest the user's message; breakpoints after the rules and the memory only (no closed block yet).
    expect(array_column($prompt->segments, 'role'))->toBe(['system', 'system', 'system', 'system', 'user', 'user', 'user', 'user', 'user', 'user'])
        ->and(array_column(array_filter($prompt->segments, fn (array $s): bool => $s['cache']), 'kind'))->toBe(['rules', 'memory']);
});

it('says (none) for an empty block, leaves older transcript out of Fast, and cuts at most three breakpoints', function () {
    $builder = PromptBuilder::shipped();
    $transcript = Transcript::fromConfig();
    foreach (range(0, 199) as $i) {
        $transcript->push(assistTurn($i, 'other', 40));
    }
    $origin = Origin::helpNow('t199', 'turn 199');

    $full = $builder->build([], $transcript, $origin, 'full');
    $fast = $builder->build([], $transcript, $origin, 'recent');

    expect(count(array_filter($full->segments, fn (array $s): bool => $s['cache'])))->toBe(3)
        ->and($full->upperCacheableTokens())->toBeLessThan($full->upperTokens())
        ->and($full->text())->toContain('## The brief')->toContain("\n(none)\n")
        ->and($fast->text())->toContain('(not included in this mode; the running summary covers it)')->not->toContain('Part 1')
        ->and($fast->tokens())->toBeLessThan($full->tokens() / 4);
});

it('builds help now and the rewrites with their own Now block and the words they rewrite', function () {
    $builder = PromptBuilder::shipped();
    $transcript = Transcript::fromConfig();

    $help = $builder->build([], $transcript, Origin::helpNow(null, ''), 'full');
    $shorter = $builder->build([], $transcript, Origin::rewrite('shorter', 'o-1', 'What is your notice period?', 'One month. Flexible.'), 'full');
    $translate = $builder->build([], $transcript, Origin::rewrite('translate', 'o-1', 'What is your notice period?', 'One month.', 'roman_urdu'), 'full');

    expect($help->text())->toContain('before Them said anything')
        ->and($shorter->text())->toContain('do not output SKIP')->toContain('at most 12 words')
        ->and($shorter->text())->toContain("What is your notice period?\n\nThe previous suggestion:\nOne month. Flexible.")->not->toContain('Hint level:')
        ->and($translate->text())->toContain('Language: Roman Urdu, then English')->toContain('start with "Roman Urdu:"');
});
