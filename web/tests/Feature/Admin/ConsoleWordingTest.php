<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\Device;
use App\Services\AuditLog;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Blade;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * What people read (since v1.5): a folder is a project, and a device signs in. The other person on a call stays
 * "Client", and the codes, routes, columns and API field names keep their names. There is no lang folder: the strings
 * live in the PHP code and the Blade views, so the test reads those (the Blade views compiled first, so comments are
 * gone and what is left is either page text or a PHP string).
 */

/** Every string in the code and the views that a person could read: [path, line, text]. */
function readableStrings(): array
{
    $found = [];
    $collect = function (string $path, string $code) use (&$found): void {
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $found[] = [$path, $token[2], $token[1], $token[0] === T_INLINE_HTML];
            }
        }
    };

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            $collect(substr($file->getPathname(), strlen(base_path()) + 1), (string) file_get_contents($file->getPathname()));
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $collect(substr($file->getPathname(), strlen(base_path()) + 1), Blade::compileString((string) file_get_contents($file->getPathname())));
        }
    }

    return $found;
}

/** The hits of [$word] in what people read: a bare key such as 'clients' or 'pairing' is a name in the code, not text. */
function wordingHits(string $word, array $allowed): array
{
    $hits = [];
    foreach (readableStrings() as [$path, $line, $text, $isPage]) {
        if (preg_match($word, $text) !== 1) {
            continue;
        }
        $inner = trim($text, "'\"");
        $text = $isPage ? $text : $inner;
        // Page text: only words outside the attributes that hold names (wire:key, class, x-*, style, script and CSS).
        if ($isPage) {
            $text = (string) preg_replace('~<(style|script)\b.*?</\1>~si', '', $text);
            $text = (string) preg_replace('~\s(?:wire:[\w.:-]+|x-[\w.:-]+|:\w+|class|style|id|for|name|value|aria-labelledby)="[^"]*"~', '', $text);
            if (preg_match($word, $text) !== 1) {
                continue;
            }
        } elseif (preg_match('/^[a-z0-9_.\-:\/]+$/', $inner) === 1) {
            continue;
        }

        $isAllowed = false;
        foreach ($allowed as $file => $strings) {
            if (str_ends_with($path, $file) && ($strings === '*' || in_array($inner, $strings, true))) {
                $isAllowed = true;
            }
        }
        if (! $isAllowed) {
            $hits[] = "{$path}:{$line}: ".mb_substr($inner, 0, 120);
        }
    }

    return $hits;
}

/**
 * The controls in [$code] named after [$word] with no label of their own: Filament then writes the label from the name
 * (SelectFilter::make('client') reads "Client"), which no search of the strings finds. A View is a template's name.
 */
function unlabelledControls(string $code, string $word): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $t): bool => ! $t->isIgnorable()));
    $hits = [];
    foreach ($tokens as $i => $token) {
        if (! $token->is('make') || ! ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON) || ! ($tokens[$i + 1] ?? null)?->is('(')
            || ! ($tokens[$i + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING) || $tokens[$i - 2]->is('View')) {
            continue;
        }
        $name = trim($tokens[$i + 2]->text, "'\"");
        if (preg_match($word, $name) !== 1) {
            continue;
        }
        // Along the chain: past make(...), then every ->method(...) that follows.
        $methods = [];
        $at = $i + 1;
        while (true) {
            $depth = 0;
            do {
                $depth += $tokens[$at]->is('(') ? 1 : ($tokens[$at]->is(')') ? -1 : 0);
                $at++;
            } while ($depth > 0);
            if (! ($tokens[$at] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                break;
            }
            $methods[] = $tokens[$at + 1]->text;
            $at += 2;
        }
        if (array_intersect($methods, ['label', 'hiddenLabel']) === []) {
            $hits[] = "{$tokens[$i - 2]->text}::make('{$name}') on line {$token->line}";
        }
    }

    return $hits;
}

/** The same, for all of app/: file => hits. */
function unlabelledControlsInApp(string $word): array
{
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            $found = unlabelledControls((string) file_get_contents($file->getPathname()), $word);
            if ($found !== []) {
                $hits[substr($file->getPathname(), strlen(base_path()) + 1)] = $found;
            }
        }
    }

    return $hits;
}

describe('the word for a folder', function () {
    it('is project in every string people read; client stays only for the other person on a call', function () {
        $hits = wordingHits('/(?<![\w\-$.])clients?(?![\w\-])/i', [
            // The speaker on the other side of a call, on the second channel.
            'app/Enums/RecordingRole.php' => ['Channel 2 · Client'],
            'app/Stt/TranscriptNormalizer.php' => ['Client'],
            'app/Services/Callers/CallerMatcher.php' => ['Client'],
            'app/Services/Accuracy/ReferenceText.php' => ['(?:speaker(?:[ _-]?[0-9a-z]{1,3})?|me|client)'],
            'app/Filament/Admin/Pages/Settings.php' => ['Both (Me + Client)', 'Client only (half the streaming cost)'],
            'app/Services/SettingsSearch.php' => ['engine provider channels client only cost streaming'],
            // The person the notes are about (the heading the apps also show, in docs/API.md).
            'app/Models/CallNote.php' => ['To confirm with the client'],
            // Google's own names for its sign-in credentials, as its console shows them.
            'app/Services/CredentialVault.php' => ['Client ID', 'Client secret'],
            'app/Filament/Admin/Pages/ApiCredentials.php' => ['An OAuth client ID of type "Web application" from the Google Cloud console (APIs & Services → Credentials). Its authorised redirect URI: '],
            // Words a search answers to, never shown: someone may still type the old word.
            'app/Filament/Support/CommandPalette.php' => ['clients customers folders keyterms vocabulary corrections'],
            // The Terms speak of the clients people meet, not of folders.
            'resources/views/legal/terms.blade.php' => '*',
            // The prompts sent to the AI: nobody reads them, and their JSON input calls it client.
            'app/Services/ClientAi/ClaudeClientAi.php' => '*',
            'app/Services/Screens/ClaudeScreenDescriber.php' => '*',
        ]);

        expect($hits)->toBe([]);
    });

    it('catches a label that says client again', function () {
        // The test above would miss everything if readableStrings stopped reading the views or the code.
        $all = readableStrings();
        $text = implode("\n", array_map(fn (array $s): string => $s[2], $all));

        expect($text)->toContain('Always double-check recordings filed under a project')
            ->toContain('Top projects')
            ->toContain('No project')
            ->toContain('Channel 2 · Client');
        expect(preg_match('/(?<![\w\-$.])clients?(?![\w\-])/i', 'Move to client'))->toBe(1)
            ->and(preg_match('/(?<![\w\-$.])clients?(?![\w\-])/i', 'wire:key="client-tab"'))->toBe(0)
            ->and(preg_match('/(?<![\w\-$.])clients?(?![\w\-])/i', 'var(--client)'))->toBe(0);
    });

    it('leaves no control to name itself client', function () {
        expect(unlabelledControlsInApp('/client/i'))->toBe([]);
    });

    it('finds a control with no label, wherever the chain goes', function () {
        $find = fn (string $code): array => unlabelledControls("<?php\n{$code}", '/client/i');

        expect($find("SelectFilter::make('client')->options(['a' => strlen('b')])->query(fn () => null);"))->toBe(["SelectFilter::make('client') on line 2"])
            ->and($find("TextColumn::make('client.name');"))->toBe(["TextColumn::make('client.name') on line 2"])
            ->and($find("Select::make('client_id')\n  ->options(fn () => pick('label'))\n  ->searchable();"))->toBe(["Select::make('client_id') on line 2"])
            ->and($find("SelectFilter::make('client')\n  ->options(['a' => strlen('b')])\n  ->label('Project');"))->toBe([])
            ->and($find("TextEntry::make('client.name')->hiddenLabel();"))->toBe([])
            ->and($find("View::make('client.tab');"))->toBe([])
            ->and($find("SelectFilter::make('status');"))->toBe([]);
    });

    it('labels the Library\'s project filter itself, because Filament would write Client', function () {
        $this->signInAsAdmin();

        expect(SelectFilter::make('client')->getLabel())->toBe('Client')
            ->and($this->panelComponent(ListCaptureSessions::class)->instance()->getTable()->getFilter('client')->getLabel())->toBe('Project');
    });

    it('shows project on the pages', function () {
        $this->signInAsAdmin();

        $this->get(CaptureSessionResource::getUrl('index'))->assertOk()->assertDontSee('No client')->assertDontSee('Move to client');
        $this->get(Settings::getUrl(panel: 'admin'))->assertOk()->assertDontSee('filed under a client')->assertDontSee('client workspaces');
    });
});

describe('the words for a device', function () {
    it('are about signing in in every string people read', function () {
        $hits = wordingHits('/\b(?:un)?pair(?:ed|ing|ings|s)?\b/i', [
            // Words a search answers to, never shown: someone may still type the old word.
            'app/Filament/Support/CommandPalette.php' => ['phones computers sign in out pair unpair'],
            'app/Services/SettingsSearch.php' => ['pair sign in revoke phone computer devices page', 'plan workspace minutes gigabytes pairings quota raise'],
        ]);

        expect($hits)->toBe([]);
    });

    it('leaves no control to name itself after pairing', function () {
        expect(unlabelledControlsInApp('/(?<![a-z])(?:un)?pair/i'))->toBe([]);
    });

    it('tells the device making the request to sign out of it, under the same code', function () {
        $device = Device::factory()->create();
        $token = $device->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)->deleteJson('/api/devices/'.$device->id)
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This is the device you are using. Sign out of it in its settings instead.', 'code' => 'current_device']);
    });

    it('answers a wrong code and a missing one without the old word, under the same field', function () {
        $wrong = $this->postJson('/api/devices/register', ['pairing_code' => 'AAAA-BBBB', 'name' => 'Phone', 'platform' => 'android'])
            ->assertUnprocessable();
        $missing = $this->postJson('/api/devices/register', ['name' => 'Phone', 'platform' => 'android'])->assertUnprocessable();

        expect($wrong->json('errors.pairing_code.0'))->toBe('This code is invalid, expired, or already used.')
            ->and($missing->json('errors.pairing_code.0'))->toBe('The code field is required.');
    });

    it('names the event in the audit log and keeps its code', function () {
        expect(AuditLog::label('device_paired'))->toBe('Device signed in with a code')
            ->and(array_keys(AuditLog::EVENTS))->toContain('device_paired');
    });
});
