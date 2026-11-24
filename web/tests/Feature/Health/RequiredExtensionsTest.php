<?php

declare(strict_types=1);

use App\Exceptions\MissingPhpExtension;
use App\Mail\ServerHealthFixedMail;
use App\Mail\ServerHealthMail;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\Health\PhpExtensions;
use App\Services\Health\ServerHealth;
use App\Services\RecordingCipher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
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

    it('reports a fault in the check itself with its stack trace, and still answers a failed check', function () {
        Exceptions::fake();
        app()->instance(PhpExtensions::class, new class extends PhpExtensions
        {
            public function loaded(string $extension): bool
            {
                throw new LogicException('A bug in the check.');
            }
        });

        expect(app(ServerHealth::class)->check())->toBe(['missing' => [], 'cipher' => 'the health check itself failed: A bug in the check.']);
        Exceptions::assertReported(fn (LogicException $e): bool => $e->getMessage() === 'A bug in the check.');
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

describe('the alert (W-INC-1125)', function () {
    it('names what is missing and the command that turns it back on, once an hour', function () {
        Mail::fake();
        $admin = Fx::superAdmin();
        withoutExtensions('sodium');

        expect(healthCheck())->toBe(1);
        $this->travel(30)->minutes();
        healthCheck();

        Mail::assertSent(ServerHealthMail::class, 1);
        Mail::assertSent(ServerHealthMail::class, function (ServerHealthMail $mail) use ($admin): bool {
            $html = $mail->render();
            $fix = 'selectorctl --interpreter=php --version='.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.' --enable-user-extensions=sodium';

            return $mail->hasTo($admin->email) && $mail->fix === $fix && str_contains($html, $fix)
                && str_contains($mail->envelope()->subject, 'missing sodium');
        });
    });

    it('emails "fixed" once when a mailed failure passes again, and never without one', function () {
        Mail::fake();
        Fx::superAdmin();

        // A pass with nothing mailed before says nothing.
        healthCheck();
        Mail::assertNothingSent();

        withoutExtensions('sodium');
        healthCheck();
        withoutExtensions();
        expect(healthCheck())->toBe(0);
        healthCheck();

        Mail::assertSent(ServerHealthMail::class, 1);
        Mail::assertSent(ServerHealthFixedMail::class, 1);
        Mail::assertSent(ServerHealthFixedMail::class, fn (ServerHealthFixedMail $mail): bool => str_contains($mail->render(), 'passes again'));
        expect(ServerHealth::lastScheduledFailure())->toBeNull();
    });

    it('asks the web PHP too, and names what it lacks when only the web PHP fails', function () {
        Mail::fake();
        Fx::superAdmin();
        // The web PHP's own check keeps what it lacks for the scheduled run, which only sees its 503.
        withoutExtensions('sodium');
        $this->getJson('/health/php')->assertStatus(503);
        withoutExtensions();
        $web = 503;
        Http::fake(['*/health/php' => function () use (&$web) {
            return Http::response(['ok' => $web === 200], $web);
        }]);

        expect(Artisan::call('echoledger:health', ['--report' => true, '--web' => true]))->toBe(1);

        Mail::assertSent(ServerHealthMail::class, fn (ServerHealthMail $mail): bool => $mail->webFails && $mail->missing === []
            && $mail->webMissing === ['sodium'] && str_contains((string) $mail->fix, 'enable-user-extensions=sodium'));
        // The scheduled tasks' PHP itself is fine: the dashboard's banner for it stays away.
        expect(ServerHealth::lastScheduledFailure())->toBeNull();

        $web = 200;
        expect(Artisan::call('echoledger:health', ['--report' => true, '--web' => true]))->toBe(0);
        Mail::assertSent(ServerHealthFixedMail::class, 1);
    });

    it('does not cry wolf when the web PHP cannot be asked', function () {
        Mail::fake();
        Fx::superAdmin();
        Http::fake(['*/health/php' => Http::response('Bad gateway', 502)]);

        expect(Artisan::call('echoledger:health', ['--report' => true, '--web' => true]))->toBe(0);

        Mail::assertNothingSent();
    });

    it('forgets what the web PHP lacked once it passes', function () {
        withoutExtensions('sodium');
        $this->getJson('/health/php')->assertStatus(503);
        expect(Cache::get(ServerHealth::WEB)['missing'])->toBe(['sodium']);

        withoutExtensions();
        $this->getJson('/health/php')->assertOk();
        expect(Cache::get(ServerHealth::WEB))->toBeNull();
    });

    it('runs with the web check in the schedule', function () {
        $events = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        expect($events->contains(fn (string $command): bool => str_contains($command, 'echoledger:health --report --web')))->toBeTrue();
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
