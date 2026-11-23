<?php

declare(strict_types=1);

use App\Exceptions\MissingPhpExtension;
use App\Mail\ServerHealthMail;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\Health\PhpExtensions;
use App\Services\RecordingCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| W-INC-1124: a hosting change took sodium out of PHP 8.4, and every upload crashed in RecordingCipher without anyone
| being told. The server now says so: a check every five minutes (one critical log line, a banner on the admin dashboard,
| an email to the super admins once an hour), a deploy check for both PHPs, and a cipher that names what is missing.
*/

/** Has PHP report these extensions as not loaded, for the cipher, the health check and the web's own check. */
function withoutExtensions(string ...$names): void
{
    app()->instance(PhpExtensions::class, new PhpExtensions(array_values($names)));
}

function healthCheck(): int
{
    return Artisan::call('echoledger:health', ['--report' => true]);
}

describe('the recordings cipher', function () {
    it('names the missing sodium extension up front, not an undefined constant', function () {
        withoutExtensions('sodium');

        foreach ([
            'from the config' => fn () => RecordingCipher::fromConfig(),
            'from a key' => fn () => new RecordingCipher(str_repeat('k', 32)),
            'a new key' => fn () => RecordingCipher::generateKey(),
        ] as $how => $make) {
            try {
                $make();
                $this->fail("No exception {$how}.");
            } catch (MissingPhpExtension $e) {
                expect($e->getMessage())->toContain('The PHP extension "sodium" is not loaded')
                    ->toContain('web/DEPLOY.md')->toContain('php artisan echoledger:health');
            }
        }
    });
});

describe('the health check', function () {
    it('fails, names each missing extension and the cipher, and writes one critical line', function () {
        withoutExtensions('sodium', 'intl');
        Log::spy();

        expect(healthCheck())->toBe(1);

        $out = Artisan::output();
        expect($out)->toContain('sodium')->toContain('intl')->toContain('Recordings cipher')->not->toContain('All required');
        Log::shouldHaveReceived('critical')->once();
    });

    it('passes on a server with everything, and writes nothing critical', function () {
        Log::spy();

        expect(healthCheck())->toBe(0);

        expect(Artisan::output())->toContain('All required PHP extensions are loaded')->toContain('Recordings cipher: works');
        Log::shouldNotHaveReceived('critical');
    });

    it('emails the super admins once an hour while it lasts, and no more often when it flaps', function () {
        Mail::fake();
        $admin = Fx::superAdmin();
        $member = User::factory()->create();
        withoutExtensions('sodium');

        healthCheck();
        $this->travel(5)->minutes();
        healthCheck();
        Mail::assertSent(ServerHealthMail::class, 1);
        Mail::assertSent(ServerHealthMail::class, fn (ServerHealthMail $mail): bool => $mail->hasTo($admin->email) && ! $mail->hasTo($member->email)
            && in_array('sodium', $mail->missing, true));

        $this->travel(56)->minutes();
        healthCheck();
        Mail::assertSent(ServerHealthMail::class, 2);

        // A pass in between does not open the gate: a check that flaps mails once an hour at most.
        withoutExtensions();
        healthCheck();
        withoutExtensions('sodium');
        $this->travel(5)->minutes();
        healthCheck();
        Mail::assertSent(ServerHealthMail::class, 2);

        $this->travel(56)->minutes();
        healthCheck();
        Mail::assertSent(ServerHealthMail::class, 3);
    });

    it('shows a banner on the admin dashboard for the web PHP and for the scheduled tasks\' PHP', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        expect((string) $this->get('/admin')->assertOk()->getContent())->not->toContain('data-server-health');

        // The web PHP checks itself on each visit.
        withoutExtensions('sodium');
        $html = (string) $this->get('/admin')->assertOk()->getContent();
        expect($html)->toContain('data-server-health')->toContain('sodium');

        // The scheduled tasks' PHP is shown by its last check, also when the web PHP has everything.
        healthCheck();
        withoutExtensions();
        $html = (string) $this->get('/admin')->assertOk()->getContent();
        expect($html)->toContain('data-server-health')->toContain('sodium')->toContain('scheduled tasks');

        healthCheck();
        expect((string) $this->get('/admin')->assertOk()->getContent())->not->toContain('data-server-health');
    });

    it('shows the banner to super admins only: it names the server\'s internals', function () {
        withoutExtensions('sodium');
        healthCheck();
        $support = Fx::user();
        $support->forceFill(['staff_role_id' => StaffRole::query()->where('key', 'support')->value('id'), 'app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();

        $html = (string) $this->actingAs($support->fresh(), 'web')->get('/admin')->assertOk()->getContent();
        expect($html)->not->toContain('data-server-health')->not->toContain('sodium');
        expect((string) $this->actingAs(Fx::superAdmin(), 'web')->get('/admin')->assertOk()->getContent())->toContain('data-server-health');
    });

    it('fails, and keeps answering everywhere, when the check itself cannot run on an unwritable temp folder', function () {
        $locked = sys_get_temp_dir().'/el-health-'.bin2hex(random_bytes(6));
        mkdir($locked, 0500);
        config(['echoledger.tmp_dir' => $locked.'/tmp']);
        Log::spy();
        try {
            expect(healthCheck())->toBe(1)
                ->and(Artisan::output())->toContain('Recordings cipher: fails');
            Log::shouldHaveReceived('critical')->once();
            expect(Artisan::call('about', ['--only' => 'server_health']))->toBe(0)
                ->and(Artisan::output())->toContain('fails');
            $this->getJson('/health/php')->assertStatus(503)->assertExactJson(['ok' => false]);
            $html = (string) $this->actingAs(Fx::superAdmin(), 'web')->get('/admin')->assertOk()->getContent();
            expect($html)->toContain('data-server-health')->toContain('Web PHP');
        } finally {
            chmod($locked, 0700);
            @rmdir($locked.'/tmp');
            rmdir($locked);
        }
    });

    it('is part of php artisan about', function () {
        Artisan::call('about', ['--only' => 'server_health']);
        expect(Artisan::output())->toContain('PHP extensions')->toContain('Recordings cipher');

        withoutExtensions('sodium');
        Artisan::call('about', ['--only' => 'server_health']);
        expect(Artisan::output())->toContain('missing: sodium');
    });

    it('runs every five minutes', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toMatch('#\*/5\s+\* \* \* \*\s+php artisan echoledger:health --report#');
    });
});

describe('the deploy check', function () {
    it('has the web PHP answer for itself: ok or not, and nothing more', function () {
        $this->getJson('/health/php')->assertOk()->assertExactJson(['ok' => true]);

        withoutExtensions('sodium');
        $answer = $this->getJson('/health/php')->assertStatus(503)->assertExactJson(['ok' => false]);
        expect((string) $answer->getContent())->not->toContain('sodium');
    });

    it('fails the deploy when the CLI PHP or the web PHP is missing an extension', function (int $cli, string $web, int $exit, array $says) {
        $stubs = sys_get_temp_dir().'/el-check-php-'.bin2hex(random_bytes(6));
        mkdir($stubs);
        file_put_contents("{$stubs}/artisan", '');
        file_put_contents("{$stubs}/php", "#!/bin/sh\n[ \"\$2\" = echoledger:health ] || exit 9\necho \"\$STUB_SAYS\"\nexit \$STUB_CLI\n");
        file_put_contents("{$stubs}/curl", "#!/bin/sh\nprintf '%s' \"\$STUB_HTTP\"\n");
        chmod("{$stubs}/php", 0755);
        chmod("{$stubs}/curl", 0755);

        $run = new Process(['bash', dirname(base_path()).'/deploy/check-php.sh', '--php', "{$stubs}/php", '--url', 'https://console.example'], $stubs, [
            'PATH' => $stubs.':'.getenv('PATH'), 'STUB_CLI' => (string) $cli, 'STUB_HTTP' => $web,
            'STUB_SAYS' => $cli === 0 ? 'All required PHP extensions are loaded.' : 'Missing PHP extensions: sodium',
        ]);
        $run->run();
        array_map('unlink', glob("{$stubs}/*") ?: []);
        rmdir($stubs);

        expect($run->getExitCode())->toBe($exit, $run->getOutput().$run->getErrorOutput());
        foreach ($says as $line) {
            expect(str_contains($run->getOutput(), $line))->toBeTrue("Missing \"{$line}\" in:\n".$run->getOutput());
        }
    })->with([
        'both have everything' => [0, '200', 0, ['PASS  CLI PHP', 'PASS  web PHP']],
        'the CLI PHP lacks one' => [1, '200', 1, ['FAIL  CLI PHP', 'Missing PHP extensions: sodium', 'PASS  web PHP']],
        'the web PHP lacks one' => [0, '503', 1, ['PASS  CLI PHP', 'FAIL  web PHP']],
        'the web PHP cannot be reached' => [0, '403', 1, ['FAIL  web PHP', '403']],
    ]);
});
