<?php

declare(strict_types=1);

use App\Filament\Support\PlanLock;
use App\Mail\EmailCodeMail;
use App\Mcp\Servers\EchoLedgerServer;
use App\Models\Plan;
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use App\Services\Entitlements\Features;
use App\Services\ShareLinks;
use App\Services\System\PlatformNotices;
use App\Support\Brand;
use Filament\Facades\Filament;
use Illuminate\Mail\Markdown;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| The product's name in one place (since v1.9, L0): config('echoledger.brand.name'), read through App\Support\Brand. A
| rename is that one value: no page, email, console text or message of the API may say the name on its own. Technical
| names keep it (the `echoledger` namespace, the echoledger:// links, webhook headers, release file names).
*/

/** What may still hold the name in code, each with why: technical names and texts nobody but the operator reads. */
const BRAND_ALLOWED = [
    'app/Stt/Drivers/AssemblyAiDriver.php' => ['X-EchoLedger-Webhook-Token'],   // a webhook header the provider sends back
    'app/Stt/Drivers/SonioxDriver.php' => ['X-EchoLedger-Webhook-Token'],       // the same
    'app/Console/Commands/PublishRelease.php' => ['EchoLedger-'],               // the desktop build's file names
    'app/Console/Commands/MakeAdmin.php' => ['the EchoLedger super admin'],     // an artisan description, for the operator
    'app/Services/RecordingCipher.php' => ['Not an EchoLedger encrypted recording.'], // an internal error, never shown
];

/** A name no page says by chance. */
const BRAND_OTHER = 'Nimbus Ledger';

/** Every string the code writes that holds the name (comments are left out). */
function brandLiteralsIn(string $dir, string $suffix): array
{
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = (string) $file;
        if (! str_ends_with($path, $suffix)) {
            continue;
        }
        $relative = substr($path, strlen(base_path()) + 1);
        $source = (string) file_get_contents($path);
        if (str_ends_with($suffix, '.blade.php')) {
            $texts = [(string) preg_replace('/\{\{--.*?--\}\}/s', '', $source)];
        } elseif ($suffix === '.css') {
            $texts = [(string) preg_replace('~/\*.*?\*/~s', '', $source)];
        } else {
            $texts = collect(token_get_all($source))->filter(fn (mixed $t): bool => is_array($t)
                && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true))->map(fn (array $t): string => $t[1])->all();
        }
        foreach ($texts as $text) {
            if (! str_contains($text, 'EchoLedger')) {
                continue;
            }
            $allowed = collect(BRAND_ALLOWED[$relative] ?? [])->contains(fn (string $ok): bool => str_contains($text, $ok));
            if (! $allowed) {
                $found[] = $relative.': '.mb_substr(trim($text), 0, 120);
            }
        }
    }

    return $found;
}

/** Shows the product under another name, as a rename would. */
function renameBrand(): void
{
    config(['echoledger.brand.name' => BRAND_OTHER, 'app.name' => BRAND_OTHER]);
}

/** The name nowhere, the token never shown, the new name where it was. */
function expectRenamed(string $output, string $where, bool $named = true): void
{
    expect(str_contains($output, 'EchoLedger'))->toBeFalse($where.' still says EchoLedger')
        ->and(str_contains($output, Brand::TOKEN))->toBeFalse($where.' shows '.Brand::TOKEN);
    if ($named) {
        expect(str_contains($output, BRAND_OTHER))->toBeTrue($where.' does not say '.BRAND_OTHER);
    }
}

it('keeps the name in config/echoledger.php, and what reads app.name reads it too', function () {
    expect(config('echoledger.brand.name'))->toBe('EchoLedger')
        ->and(Brand::name())->toBe('EchoLedger')->and(Brand::adminName())->toBe('EchoLedger admin')
        ->and(config('app.name'))->toBe('EchoLedger')
        ->and(Brand::fill('Transcription on '.Brand::TOKEN))->toBe('Transcription on EchoLedger');

    // app.name is set from the brand when the application boots, whatever the .env file's APP_NAME says.
    config(['echoledger.brand.name' => BRAND_OTHER, 'app.name' => 'Name from the env file']);
    (new AppServiceProvider($this->app))->register();
    expect(config('app.name'))->toBe(BRAND_OTHER);
});

it('writes the name nowhere else in what the code or the views show', function () {
    $found = [...brandLiteralsIn('app', '.php'), ...brandLiteralsIn('resources/views', '.blade.php'), ...brandLiteralsIn('resources/views', '.css')];

    expect($found)->toBe([]);
});

it('shows the website, the legal pages and the account pages under another name', function () {
    renameBrand();

    foreach (['/' => 'the landing page', '/downloads' => 'Downloads', '/terms' => 'the terms', '/privacy' => 'the privacy policy', '/account/delete' => 'Delete your account'] as $url => $where) {
        expectRenamed((string) $this->get($url)->assertOk()->getContent(), $where);
    }
    expectRenamed((string) $this->get('/device/sign-in')->getContent(), 'a broken device sign-in link');
    expectRenamed(view('maintenance', ['message' => PlatformNotices::maintenanceMessage(), 'until' => null])->render(), 'the maintenance page');
});

it('shows the console and the admin panel under another name', function () {
    renameBrand();
    $this->get('/app/login')->assertOk()->assertSee(BRAND_OTHER)->assertDontSee('EchoLedger');

    $admin = Fx::superAdmin();
    $this->actingAs($admin, 'web');
    foreach (['/app' => 'the Home page', '/app/activity' => 'Activity', '/app/settings/plan' => 'Plan & usage', '/app/devices' => 'Devices'] as $url => $where) {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        expectRenamed((string) $this->get($url)->assertOk()->getContent(), $where);
    }
    foreach (['/admin' => 'the admin overview', '/admin/plans' => 'Plans', '/admin/staff' => 'Staff', '/admin/share-checks' => 'Screen-share checks'] as $url => $where) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        expectRenamed((string) $this->get($url)->assertOk()->getContent(), $where);
    }
    expect((string) $this->get('/admin')->getContent())->toContain(BRAND_OTHER.' admin');
});

it('names a feature under another name where the upgrade flow and a locked button say it', function () {
    renameBrand();
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $person = Fx::user();
    Workspace::query()->whereKey($person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->actingAs($person, 'web');

    $html = (string) $this->get('/app/settings/plan/upgrade?feature=platform_transcription')->assertOk()->getContent();
    expectRenamed($html, 'the upgrade page of a feature');
    expect($html)->toContain('Transcription on '.BRAND_OTHER.' is in ')
        ->and(Features::name('platform_transcription'))->toBe('Transcription on '.BRAND_OTHER)
        ->and(Features::name('no_such_feature'))->toBe('no_such_feature');
    // A locked feature's button without a label of its own is named after the feature.
    expectRenamed((string) e(PlanLock::sheet('unlock', 'platform_transcription')->getLabel()), 'a locked feature\'s button');
});

it('shows a shared recording under another name', function () {
    Fx::fakeRecordingsDisk();
    renameBrand();
    $owner = Fx::user();
    $session = Fx::session($owner, null, ['title' => 'Weekly sync']);
    Fx::transcript($session, 'We agreed on Friday.');
    ['url' => $url] = app(ShareLinks::class)->create($session, 3600, null, false, 'Ana');

    expectRenamed((string) $this->get($url)->assertOk()->getContent(), 'a shared transcript');
    Fx::cleanupDisks();
});

it('writes an email, its subject and its text part under another name', function () {
    // Laravel marks a view checked by its file alone, while an email's HTML part (secured echo format) and its text part
    // compile to two files: after the HTML part, the text part takes its compiled file unchecked. A cache from before a
    // template changed would then show the old words; deploys clear it (optimize:clear), so this test starts clean too.
    $this->artisan('view:clear')->assertSuccessful();
    renameBrand();
    $mail = new EmailCodeMail('123456', 'sign_in', 10);

    expect($mail->envelope()->subject)->toBe('Your '.BRAND_OTHER.' code: 123456');
    expectRenamed($mail->render(), 'the email');
    expectRenamed((string) app(Markdown::class)->renderText((string) $mail->content()->markdown, $mail->content()->with), 'the email in plain text');
});

it('names the product under another name in what the apps and the connector read', function () {
    renameBrand();
    $device = Fx::device();

    $entitlements = $this->api(Fx::token($device))->getJson('/api/me/entitlements')->assertOk();
    expectRenamed((string) $entitlements->getContent(), 'GET /api/me/entitlements');
    expect($entitlements->json('features.platform_transcription.name'))->toBe('Transcription on '.BRAND_OTHER);

    $server = (new ReflectionClass(EchoLedgerServer::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod($server, 'boot'))->invoke($server);
    $context = $server->createContext();
    expect($context->implementation->name)->toBe(BRAND_OTHER)->and($context->instructions)->toStartWith(BRAND_OTHER.' keeps a person\'s call');
});
