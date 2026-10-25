<?php

declare(strict_types=1);

use App\Filament\Pages\ApiCredentials;
use App\Models\Credential;
use App\Services\CredentialVault;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function adminVault(): CredentialVault
{
    return app(CredentialVault::class);
}

function adminAllCredentialFields(): array
{
    $fields = [];
    foreach (CredentialVault::FIELDS as $provider => $keys) {
        foreach (array_keys($keys) as $key) {
            $fields[] = "{$provider}__{$key}";
        }
    }

    return $fields;
}

it('renders the credentials page with a section per provider', function () {
    $this->signInAsAdmin();

    $this->get('/admin/api-credentials')->assertOk()
        ->assertSee('API Credentials')
        ->assertSee('AssemblyAI (default)')->assertSee('ElevenLabs')->assertSee('Azure Speech')
        ->assertSee('whisper.cpp server')->assertSee('OpenAI (optional)')->assertSee('Groq (optional)')
        ->assertSee('Save credentials');
});

it('lists the webhook URL to configure at the provider', function () {
    $this->signInAsAdmin();

    $this->get('/admin/api-credentials')->assertOk()
        ->assertSee(rtrim((string) config('app.url'), '/').'/api/webhooks/stt/elevenlabs');
});

it('lets the long webhook URL wrap so the page fits a phone screen (A6.W26)', function () {
    $this->signInAsAdmin();

    $html = $this->get('/admin/api-credentials')->assertOk()->getContent();
    $url = rtrim((string) config('app.url'), '/').'/api/webhooks/stt/elevenlabs';

    expect($html)->toMatch('#style="overflow-wrap: anywhere; word-break: break-all;"[^>]*>\s*(<[^>]+>\s*)*'.preg_quote($url, '#').'#');
});

it('uses a masked, revealable input without autofill for every secret', function () {
    $this->signInAsAdmin();

    $html = $this->panelComponent(ApiCredentials::class)->html();

    foreach (adminAllCredentialFields() as $field) {
        expect($html)->toMatch('/<input[^>]*autocomplete="new-password"[^>]*id="form\.'.$field.'"[^>]*type="password"/');
    }
    expect(substr_count($html, 'aria-label="Show password"'))->toBe(count(adminAllCredentialFields()));
});

it('starts with every field empty and reports "Not set"', function () {
    $this->signInAsAdmin();

    $page = $this->panelComponent(ApiCredentials::class);

    foreach (adminAllCredentialFields() as $field) {
        $page->assertSet('data.'.$field, null);
    }
    expect(substr_count($page->html(), 'Not set'))->toBe(count(adminAllCredentialFields()));
});

it('shows only the last four characters of a long stored secret', function () {
    $this->signInAsAdmin();
    adminVault()->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');

    $html = $this->panelComponent(ApiCredentials::class)->html();

    expect($html)->toContain('ends …7890')->not->toContain('aai-secret-key-1234567890')->not->toContain('secret-key');
});

it('shows no part of a short secret', function () {
    $this->signInAsAdmin();
    adminVault()->put('azure', 'api_key', 'abc123xyz');

    $html = $this->panelComponent(ApiCredentials::class)->html();

    expect($html)->toContain('Stored')->not->toContain('abc123xyz')->not->toContain('3xyz');
});

it('never puts a stored secret in the page, the component state or the Livewire snapshot', function () {
    $this->signInAsAdmin();
    adminVault()->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');
    adminVault()->put('elevenlabs', 'webhook_secret', 'el-hmac-secret-abcdefghijkl');

    $page = $this->get('/admin/api-credentials')->assertOk()->getContent();
    $component = $this->panelComponent(ApiCredentials::class);

    foreach ([$page, $component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain('aai-secret-key-1234567890')->not->toContain('el-hmac-secret-abcdefghijkl');
    }
});

it('stores typed secrets encrypted and clears the form afterwards', function () {
    $admin = $this->signInAsAdmin();

    $page = $this->panelComponent(ApiCredentials::class)
        ->fillForm(['assemblyai__api_key' => 'aai-typed-secret-0001', 'elevenlabs__api_key' => 'xi-typed-secret-0002'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved 2 credential(s)');

    foreach (adminAllCredentialFields() as $field) {
        $page->assertSet('data.'.$field, null);
    }
    expect($page->html())->not->toContain('aai-typed-secret-0001')->not->toContain('xi-typed-secret-0002');

    $raw = DB::table('credentials')->where(['provider' => 'assemblyai', 'key' => 'api_key'])->first();
    expect($raw->value)->not->toContain('aai-typed-secret-0001')
        ->and(Crypt::decryptString($raw->value))->toBe('aai-typed-secret-0001')
        ->and((int) $raw->updated_by)->toBe($admin->id)
        ->and(adminVault()->get('elevenlabs', 'api_key'))->toBe('xi-typed-secret-0002');
});

it('leaves existing secrets alone when their fields are left blank', function () {
    $this->signInAsAdmin();
    adminVault()->put('assemblyai', 'api_key', 'original-assemblyai-key');
    adminVault()->put('azure', 'api_key', 'original-azure-key-0001');

    $this->panelComponent(ApiCredentials::class)
        ->fillForm(['elevenlabs__api_key' => 'brand-new-elevenlabs-key'])
        ->call('save')
        ->assertNotified('Saved 1 credential(s)');

    expect(adminVault()->get('assemblyai', 'api_key'))->toBe('original-assemblyai-key')
        ->and(adminVault()->get('azure', 'api_key'))->toBe('original-azure-key-0001')
        ->and(adminVault()->get('elevenlabs', 'api_key'))->toBe('brand-new-elevenlabs-key');
});

it('replaces an existing secret when a new value is typed', function () {
    $this->signInAsAdmin();
    adminVault()->put('assemblyai', 'api_key', 'old-assemblyai-key-0001');

    $this->panelComponent(ApiCredentials::class)->fillForm(['assemblyai__api_key' => 'new-assemblyai-key-0002'])->call('save');

    expect(adminVault()->get('assemblyai', 'api_key'))->toBe('new-assemblyai-key-0002')
        ->and(Credential::query()->where(['provider' => 'assemblyai', 'key' => 'api_key'])->count())->toBe(1);
});

it('says there was nothing to save when everything is blank or whitespace', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ApiCredentials::class)
        ->fillForm(['assemblyai__api_key' => '   ', 'elevenlabs__api_key' => ''])
        ->call('save')
        ->assertNotified('Nothing to save');

    expect(Credential::query()->count())->toBe(0);
});

it('trims whitespace around a pasted secret', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ApiCredentials::class)->fillForm(['groq__api_key' => "  gsk-pasted-secret-0003 \n"])->call('save');

    expect(adminVault()->get('groq', 'api_key'))->toBe('gsk-pasted-secret-0003');
});

it('saves a secret for each provider field under the right provider and key', function () {
    $this->signInAsAdmin();
    $input = [];
    foreach (adminAllCredentialFields() as $field) {
        $input[$field] = 'value-for-'.$field.'-xyz';
    }

    $this->panelComponent(ApiCredentials::class)->fillForm($input)->call('save')->assertNotified('Saved '.count($input).' credential(s)');

    foreach (CredentialVault::FIELDS as $provider => $keys) {
        foreach (array_keys($keys) as $key) {
            expect(adminVault()->get($provider, $key))->toBe("value-for-{$provider}__{$key}-xyz");
        }
    }
});

it('limits a secret to 4096 characters', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ApiCredentials::class)
        ->fillForm(['assemblyai__api_key' => str_repeat('k', 4097)])
        ->call('save')
        ->assertHasFormErrors(['assemblyai__api_key' => 'max']);

    expect(Credential::query()->count())->toBe(0);
});

it('ignores unknown form keys instead of storing them', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ApiCredentials::class)
        ->set('data.nonsense__api_key', 'should-not-be-stored')
        ->set('data.assemblyai__nonsense', 'should-not-be-stored')
        ->fillForm(['groq__api_key' => 'gsk-real-secret-0004'])
        ->call('save');

    expect(Credential::query()->count())->toBe(1)
        ->and(Credential::query()->first()->provider)->toBe('groq');
});

it('offers Generate only for secrets the server can create', function () {
    $this->signInAsAdmin();

    $html = $this->panelComponent(ApiCredentials::class)->html();

    expect($html)->toContain('generate_assemblyai_webhook_secret')
        ->not->toContain('generate_assemblyai_api_key')
        ->not->toContain('generate_elevenlabs_webhook_secret');
});

it('generates a fresh webhook secret after confirmation, stores it encrypted and never displays it', function () {
    $admin = $this->signInAsAdmin();
    adminVault()->put('assemblyai', 'webhook_secret', 'previous-webhook-secret-0000');

    $page = $this->panelComponent(ApiCredentials::class)
        ->call('mountAction', 'generate_assemblyai_webhook_secret', [], ['schemaComponent' => 'form.assemblyai__webhook_secret']);

    $modal = strip_tags($this->modalHtml($page));
    expect($modal)->toContain('Creates a new random secret.');

    $page->call('callMountedAction')->assertNotified('New secret generated and stored');

    $generated = adminVault()->get('assemblyai', 'webhook_secret');
    expect($generated)->not->toBe('previous-webhook-secret-0000')
        ->and($generated)->toMatch('/^[A-Za-z0-9_-]{43}$/');
    expect(DB::table('credentials')->where(['provider' => 'assemblyai', 'key' => 'webhook_secret'])->value('value'))->not->toContain($generated);
    expect((int) DB::table('credentials')->where(['provider' => 'assemblyai', 'key' => 'webhook_secret'])->value('updated_by'))->toBe($admin->id);
    expect($page->html())->not->toContain($generated)
        ->and($this->get('/admin/api-credentials')->assertOk()->getContent())->not->toContain($generated);
});

it('generates a different secret each time', function () {
    $this->signInAsAdmin();

    $secrets = [];
    foreach (range(1, 3) as $_) {
        $this->panelComponent(ApiCredentials::class)
            ->call('mountAction', 'generate_assemblyai_webhook_secret', [], ['schemaComponent' => 'form.assemblyai__webhook_secret'])
            ->call('callMountedAction');
        $secrets[] = Credential::query()->where(['provider' => 'assemblyai', 'key' => 'webhook_secret'])->first()->value;
    }

    expect(array_unique($secrets))->toHaveCount(3);
});

it('keeps the secret out of serialized credential models', function () {
    adminVault()->put('assemblyai', 'api_key', 'serialize-me-not-0001');

    $credential = Credential::query()->firstOrFail();

    expect(json_encode($credential))->not->toContain('serialize-me-not-0001')
        ->and($credential->toArray())->not->toHaveKey('value')
        ->and($credential->value)->toBe('serialize-me-not-0001');
});

it('refuses a non-admin account', function () {
    $this->actingAs($this->makeRegularUser());

    $this->get('/admin/api-credentials')->assertForbidden();
    expect(ApiCredentials::canAccess())->toBeFalse();
});
