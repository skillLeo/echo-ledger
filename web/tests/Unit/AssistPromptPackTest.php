<?php

declare(strict_types=1);

use App\Services\Assist\PromptPack;

/*
 * The Live Assist prompt pack (v1.9 L1.5, docs/API_ASSIST.md "Output contract"): the system rules and the five meeting
 * types' templates in web/resources/prompts/assist, read through App\Services\Assist\PromptPack. Files only: no provider
 * is called and no key is held.
 */

/** The context blocks in the plan's cache order (PLAN_V1_9 L2): what never changes first, the question last. */
const ASSIST_CACHE_ORDER = ['system_rules', 'brief', 'documents', 'project_memory', 'facts_ledger', 'running_summary', 'transcript_blocks', 'last_turns', 'question'];

function assistPack(): PromptPack
{
    return new PromptPack(dirname(__DIR__, 2).'/resources/prompts/assist');
}

/** @return list<string> every file of the pack */
function assistPackFiles(): array
{
    return glob(dirname(__DIR__, 2).'/resources/prompts/assist/*') ?: [];
}

/**
 * The words rule's patterns as tools/repo-policy.test.mjs has them (one list for the whole repo), for PHP.
 *
 * @return list<string>
 */
function bannedWordPatterns(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/tools/repo-policy.test.mjs');
    $start = strpos($source, 'const BANNED_WORDS = [');
    $end = strpos($source, "\n];", (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $patterns = [];
    foreach (explode("\n", substr($source, (int) $start, (int) $end - (int) $start)) as $line) {
        if (preg_match('/^\s*\/(.*)\/([a-z]*),\s*(?:\/\/.*)?$/', $line, $m) === 1) {
            $patterns[] = '/'.$m[1].'/'.$m[2];
        }
    }

    return $patterns;
}

it('names its version, its files and the context blocks in the plan\'s cache order', function () {
    $pack = assistPack();
    $manifest = $pack->manifest();

    expect($pack->version())->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($manifest['system'])->toBe('system.md')
        ->and(array_keys($manifest['templates']))->toBe(PromptPack::MEETING_TYPES)
        ->and(array_values(array_diff($pack->placeholders(), ['hint_level', 'language_help'])))->toBe(ASSIST_CACHE_ORDER)
        // The two settings the person can change during a call come just before the question, after everything cached.
        ->and(array_slice($pack->placeholders(), -3))->toBe(['hint_level', 'language_help', 'question'])
        ->and($pack->hintLevels())->toBe(['keywords', 'outline', 'full'])
        ->and($pack->languageHelp())->toBe(['off', 'urdu', 'roman_urdu', 'arabic'])
        ->and($pack->problems())->toBe([]);
    $files = array_map('basename', assistPackFiles());
    sort($files);
    expect($files)->toBe(['boss.md', 'existing_client.md', 'general.md', 'interview.md', 'manifest.json', 'new_client.md', 'system.md']);
});

it('loads every template: the system rules first, then each context block once and in order', function (string $type) {
    $pack = assistPack();

    expect(PromptPack::placeholdersIn($pack->raw($type)))->toBe($pack->placeholders())
        ->and($pack->template($type))->toStartWith("# Live Assist: system rules\n")
        ->and(PromptPack::placeholdersIn($pack->template($type)))->toBe(array_slice($pack->placeholders(), 1))
        ->and(PromptPack::placeholdersIn($pack->systemRules()))->toBe([]);
})->with(PromptPack::MEETING_TYPES);

it('carries the output contract in every template', function (string $type) {
    $pack = assistPack();
    $template = $pack->template($type);

    // The rules, from the system rules at the start of every template.
    expect($template)->toStartWith($pack->systemRules());
    foreach (['## Output contract', '## Never repeat what is known', '## Honesty', '## Hint levels', '## Language help'] as $section) {
        expect(substr_count($template, $section))->toBe(1, "{$type}: {$section}");
    }
    foreach (['At most 25 words, in simple English.', 'Then at most 3 bullets.', 'key words in **bold**', 'starts with "Say next:"',
        'output exactly SKIP', 'one last line that explains the question'] as $rule) {
        expect(str_contains($template, $rule))->toBeTrue("{$type}: {$rule}");
    }
    // And again right before the question, in the template's own words.
    expect($pack->raw($type))->toContain('a first line of at most 25 words in simple English, at')
        ->toContain('most 3 bullets with the key words in **bold**, an optional "Say next:" line, and the language line only when language')
        ->toContain('Or output exactly SKIP.');
})->with(PromptPack::MEETING_TYPES);

it('says never to ask again what was said, never to invent, and how much to write at each hint level', function () {
    $rules = assistPack()->systemRules();

    expect($rules)->toContain('Check the facts ledger first')
        ->toContain('Never suggest asking for something Them has already said')
        ->toContain('Stay consistent with Me\'s earlier words')
        ->toContain('Never invent experience, skills, numbers, dates, clients, results or promises')
        ->toContain('offer to check')
        ->toContain('- keywords: no sentences.')
        ->toContain('- outline: the first line is a short phrase')
        ->toContain('- full: the first line is a complete sentence')
        ->toContain('"Urdu:", "Roman Urdu:" or "Arabic:"');
});

it('reminds an interview to check that AI help is allowed (outline by default), and the other meetings of the consent line', function () {
    $pack = assistPack();
    $consent = (string) $pack->manifest()['consent_line'];

    expect($pack->reminder('interview'))->toBe('Check whether this interview allows AI help.')
        ->and($pack->defaultHintLevel('interview'))->toBe('outline')
        ->and($pack->raw('interview'))->toContain('"Check whether this interview allows AI help."')
        ->and($consent)->toContain('I record this call')->toContain('Is that okay with you?');
    foreach (['boss', 'existing_client', 'new_client', 'general'] as $type) {
        expect($pack->reminder($type))->toBe($consent, $type)
            ->and($pack->raw($type))->toContain('"'.$consent.'"')
            ->and($pack->defaultHintLevel($type))->toBe('full');
    }
});

it('passes the words rule in every language, with the repo\'s own list', function () {
    $patterns = bannedWordPatterns();
    expect(count($patterns))->toBeGreaterThanOrEqual(19);
    foreach (['A way to cheat', 'یہ دھوکہ ہے', 'Bypass it', 'stealth mode', 'chupke se'] as $planted) {
        expect(collect($patterns)->contains(fn (string $p): bool => preg_match($p, $planted) === 1))->toBeTrue($planted);
    }

    $found = [];
    foreach (assistPackFiles() as $file) {
        $text = (string) file_get_contents($file);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = basename($file).': '.$pattern;
            }
        }
    }
    expect($found)->toBe([]);
});

it('fills every block for a question, says (none) for an empty one, and never reads a block for placeholders', function () {
    $prompt = assistPack()->render('interview', [
        'brief' => 'Interview at Acme for a support role. Say {{question}} to nobody.',
        'documents' => '',
        'facts_ledger' => '- Me: three years at Daraz.',
        'last_turns' => "Them: Thanks for joining.\nMe: Thank you for having me.",
        'question' => 'Why do you want to leave your current job?',
    ]);

    expect(PromptPack::placeholdersIn(str_replace('{{question}} to nobody', '', $prompt)))->toBe([])
        ->and($prompt)->toContain('Say {{question}} to nobody.')
        ->and(substr_count($prompt, 'Why do you want to leave your current job?'))->toBe(1)
        ->and($prompt)->toContain("## Documents\nFiles Me attached")->toContain(PromptPack::NONE)
        ->toContain('Hint level: outline')->toContain('Language help: off')
        ->and(strpos($prompt, '- Me: three years at Daraz.'))->toBeLessThan((int) strpos($prompt, 'Them: Thanks for joining.'))
        ->and(strpos($prompt, 'Them: Thanks for joining.'))->toBeLessThan((int) strpos($prompt, 'Why do you want to leave'));

    expect(assistPack()->render('new_client', ['hint_level' => 'keywords', 'language_help' => 'roman_urdu', 'question' => 'What do you charge?']))
        ->toContain('Hint level: keywords')->toContain('Language help: roman_urdu');
});

it('refuses an unknown meeting type, hint level or language help', function (Closure $call) {
    expect($call)->toThrow(InvalidArgumentException::class);
})->with([
    'a meeting type' => [fn () => assistPack()->template('date')],
    'a hint level' => [fn () => assistPack()->render('general', ['hint_level' => 'everything'])],
    'language help' => [fn () => assistPack()->render('general', ['language_help' => 'french'])],
]);

it('says what is wrong with a broken pack, and calls nothing', function () {
    $dir = sys_get_temp_dir().'/assist-pack-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($dir);
    foreach (assistPackFiles() as $file) {
        copy($file, $dir.'/'.basename($file));
    }
    // Two blocks swapped in one template, and another template gone.
    file_put_contents($dir.'/boss.md', strtr((string) file_get_contents($dir.'/boss.md'), ['{{facts_ledger}}' => '{{running_summary}}', '{{running_summary}}' => '{{facts_ledger}}']));
    unlink($dir.'/general.md');

    $problems = (new PromptPack($dir))->problems();
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toStartWith('boss: placeholders system_rules, brief, documents, project_memory, running_summary, facts_ledger')
        ->and($problems[1])->toBe('The prompt pack has no general.md.');
    $code = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Assist/PromptPack.php');
    expect($code)->not->toContain('Http::')->not->toContain('curl_')->not->toContain('Credential');
    foreach (assistPackFiles() as $file) {
        expect((string) file_get_contents($file))->not->toMatch('/https?:\/\/|api[_ ]?key|sk-[A-Za-z0-9]/i');
    }
});
