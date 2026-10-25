<?php

declare(strict_types=1);

use App\Enums\SttUseCase;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\SettingsSearch;
use App\Services\SettingsTransfer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function adminGroupsPage($test)
{
    return $test->panelComponent(Settings::class);
}

function adminSettingsFile(array $settings, string $format = SettingsTransfer::FORMAT): UploadedFile
{
    return UploadedFile::fake()->createWithContent('settings.json', json_encode(['format' => $format, 'version' => 1, 'settings' => $settings]));
}

// --- Groups and search (B19) -------------------------------------------------------------------------------------

it('names the groups like the desktop app and the phone', function () {
    expect(array_values(SettingsSearch::GROUPS))->toBe(['General', 'Recording', 'Captions', 'Dictation', 'Privacy & security', 'Devices', 'Storage', 'About']);
});

it('puts every section in a group and gives each group at least one section', function () {
    foreach (SettingsSearch::SECTIONS as [$group]) {
        expect(SettingsSearch::GROUPS)->toHaveKey($group);
    }
    foreach (array_keys(SettingsSearch::GROUPS) as $group) {
        expect(collect(SettingsSearch::SECTIONS)->contains(fn (array $section): bool => $section[0] === $group))->toBeTrue();
    }
});

it('shows the group tabs and opens on General', function () {
    $this->signInAsAdmin();

    $page = adminGroupsPage($this)->assertSet('activeTab', 'general')->assertSee('Search settings', false);
    foreach (SettingsSearch::GROUPS as $label) {
        $page->assertSee($label);
    }
});

it('shows one group at a time', function (string $group, string $see, string $dontSee) {
    $this->signInAsAdmin();

    adminGroupsPage($this)->set('activeTab', $group)->assertSee($see)->assertDontSee($dontSee);
})->with([
    'general' => ['general', 'Language & time', 'Monthly limit (minutes)'],
    'recording' => ['recording', 'Bookmark tags', 'Language & time'],
    'captions' => ['captions', 'Live caption channels', 'Bookmark tags'],
    'dictation' => ['dictation', 'Dictation', 'Live caption channels'],
    'privacy' => ['privacy', 'Server certificate pins', 'Dictation engine'],
    'devices' => ['devices', 'Open Devices', 'Server certificate pins'],
    'storage' => ['storage', 'Monthly limit (minutes)', 'Open Devices'],
    'about' => ['about', 'never holds API keys', 'Monthly limit (minutes)'],
]);

it('matches a setting by its name, a keyword or its group', function () {
    expect(SettingsSearch::results('retention'))->toBe(['retention'])
        ->and(SettingsSearch::results('passkeys'))->toBe(['access'])
        ->and(SettingsSearch::results('USD budget'))->toBe(['limit'])
        ->and(SettingsSearch::results('captions'))->toBe(['captions'])
        ->and(SettingsSearch::results('devices'))->toBe(['devices'])
        ->and(SettingsSearch::results('  Time   ZONE '))->toBe(['language'])
        ->and(SettingsSearch::results('quota'))->toBe(['plan'])
        ->and(SettingsSearch::results('banana'))->toBe([])
        ->and(SettingsSearch::results('   '))->toBe([])
        ->and(SettingsSearch::matches('', 'limit'))->toBeTrue()
        ->and(SettingsSearch::matches(null, 'limit'))->toBeTrue()
        ->and(SettingsSearch::matches('banana', 'limit'))->toBeFalse();
});

it('counts the matches per group for the tab badges', function () {
    expect(SettingsSearch::count('whisper', 'recording'))->toBe(2)
        ->and(SettingsSearch::count('whisper', 'general'))->toBe(0)
        ->and(SettingsSearch::count('', 'recording'))->toBe(0)
        ->and(SettingsSearch::firstGroup('limit'))->toBe('storage')
        ->and(SettingsSearch::firstGroup('banana'))->toBeNull();
});

it('jumps to the first group with a match while typing in the search box', function (string $query, string $group) {
    $this->signInAsAdmin();

    adminGroupsPage($this)->set('data.settings_search', $query)->assertSet('activeTab', $group);
})->with([
    ['passkeys', 'privacy'],
    ['monthly budget', 'storage'],
    ['bookmark tags', 'recording'],
    ['export', 'about'],
    ['voice typing', 'dictation'],
]);

it('stays where it is when nothing matches', function () {
    $this->signInAsAdmin();

    adminGroupsPage($this)->set('activeTab', 'storage')->set('data.settings_search', 'banana')->assertSet('activeTab', 'storage');
});

it('hides the sections that do not match the search and shows the ones that do', function () {
    $this->signInAsAdmin();

    $hiddenSections = fn (string $html): int => preg_match_all('/<div hidden="hidden" style="display: none;"[^>]*class="fi-sc-section"/', $html);

    $page = adminGroupsPage($this);
    expect($hiddenSections($page->html()))->toBe(0);

    $page->set('data.settings_search', 'time zone');
    $html = $page->html();
    expect($hiddenSections($html))->toBe(1)
        ->and($html)->toContain('Language &amp; time');

    $page->set('data.settings_search', '');
    expect($hiddenSections($page->html()))->toBe(0);
});

it('does not save the search words and does not lose settings while searching', function () {
    $this->signInAsAdmin();

    adminGroupsPage($this)->set('data.settings_search', 'time zone')->set('data.display_timezone', 'Asia/Karachi')->call('save')->assertHasNoFormErrors();

    expect(app(SettingsRepository::class)->displayTimezone())->toBe('Asia/Karachi')
        ->and(Setting::query()->where('key', 'like', '%search%')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'retention_days')->exists())->toBeTrue();
});

it('links to the pages that own the access and device settings', function () {
    $this->signInAsAdmin();

    adminGroupsPage($this)->set('activeTab', 'privacy')->assertSee('Open Security')
        ->set('activeTab', 'devices')->assertSee('/admin/devices');
});

// --- Export and import (B19) -------------------------------------------------------------------------------------

it('exports the settings as a download', function () {
    $this->signInAsAdmin();

    adminGroupsPage($this)->callAction('exportSettings')->assertFileDownloaded('EchoLedger console settings '.now()->format('Y-m-d').'.json');
});

it('exports only the non-secret settings and never the credentials', function () {
    $this->signInAsAdmin();
    $vault = app(CredentialVault::class);
    $vault->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');
    $vault->put('assemblyai', 'webhook_secret', 'aai-hook-secret-abcdef');
    $vault->put('whispercpp', 'bearer_token', 'whisper-bearer-abcdef123456');
    app(SettingsRepository::class)->set('retention_days', 45);

    $document = app(SettingsTransfer::class)->export();
    $json = json_encode($document);

    expect($document['format'])->toBe('echoledger-console-settings')
        ->and($document['version'])->toBe(1)
        ->and(array_keys($document['settings']))->toBe(SettingsTransfer::PORTABLE)
        ->and($document['settings']['retention_days'])->toBe(45)
        ->and($json)->not->toContain('aai-secret-key-1234567890')
        ->not->toContain('aai-hook-secret-abcdef')
        ->not->toContain('whisper-bearer-abcdef123456')
        ->not->toContain('api_key')
        ->not->toContain('bearer_token')
        ->not->toContain('onboarding');
});

it('exports exactly the settings that the page saves', function () {
    $this->signInAsAdmin();
    adminGroupsPage($this)->call('save')->assertHasNoFormErrors();

    $saved = Setting::query()->pluck('key')->all();
    sort($saved);
    $portable = SettingsTransfer::PORTABLE;
    sort($portable);

    expect($saved)->toBe($portable);
});

it('imports a settings file and keeps everything that is not in it', function () {
    $this->signInAsAdmin();
    $settings = app(SettingsRepository::class);
    $settings->set('retention_days', 14);

    adminGroupsPage($this)->callAction('importSettings', ['file' => adminSettingsFile([
        'retention_days' => 90,
        'captions.channels' => 'client_only',
        'default_language' => 'en+ur',
        'display.timezone' => 'Asia/Karachi',
        'markers.tags' => ['Follow-up', 'Decision'],
    ])])->assertNotified('Settings imported');

    $settings->flush();
    expect($settings->retentionDays())->toBe(90)
        ->and($settings->captionChannels())->toBe('client_only')
        ->and($settings->get('default_language'))->toBe('en+ur')
        ->and($settings->displayTimezone())->toBe('Asia/Karachi')
        ->and($settings->markerTags())->toBe(['Follow-up', 'Decision'])
        ->and($settings->providerFor(SttUseCase::LiveCaptions)->value)->toBe('assemblyai');
});

it('round-trips an export', function () {
    $this->signInAsAdmin();
    $settings = app(SettingsRepository::class);
    $settings->set('retention_days', 21);
    $settings->set('captions.channels', 'off');
    $document = app(SettingsTransfer::class)->export();
    $settings->set('retention_days', 30);
    $settings->set('captions.channels', 'both');

    adminGroupsPage($this)->callAction('importSettings', ['file' => adminSettingsFile($document['settings'])])->assertNotified('Settings imported');

    $settings->flush();
    expect($settings->retentionDays())->toBe(21)->and($settings->captionChannels())->toBe('off');
});

it('ignores keys that are not settings of this page and never touches the credentials', function () {
    $this->signInAsAdmin();
    $vault = app(CredentialVault::class);
    $vault->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');

    adminGroupsPage($this)->callAction('importSettings', ['file' => adminSettingsFile([
        'retention_days' => 60,
        'evil.key' => 'injected',
        'onboarding.dismissed' => true,
        'credentials' => ['assemblyai' => ['api_key' => 'stolen']],
        'api_key' => 'stolen',
    ])])->assertNotified('Settings imported');

    expect(Setting::query()->where('key', 'like', '%evil%')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'onboarding.dismissed')->exists())->toBeFalse()
        ->and(app(CredentialVault::class)->get('assemblyai', 'api_key'))->toBe('aai-secret-key-1234567890')
        ->and(app(SettingsRepository::class)->retentionDays())->toBe(60);
});

it('changes nothing when a value in the file is not allowed', function (string $key, mixed $value) {
    $this->signInAsAdmin();

    adminGroupsPage($this)->callAction('importSettings', ['file' => adminSettingsFile(['retention_days' => 90, $key => $value])])
        ->assertNotified('Nothing was imported');

    expect(Setting::query()->count())->toBe(0);
})->with([
    'retention out of range' => ['retention_days', 9999],
    'unknown provider' => ['provider.final_transcript', 'skynet'],
    'plain http endpoint' => ['elevenlabs.base_url', 'http://api.elevenlabs.io'],
    'bad pin' => ['app.pins', ['please pin my certificate']],
    'bad time zone' => ['display.timezone', 'Mars/Phobos'],
]);

it('refuses a file that is not a console settings file', function (string $content, string $message) {
    $this->signInAsAdmin();

    adminGroupsPage($this)->callAction('importSettings', ['file' => UploadedFile::fake()->createWithContent('settings.json', $content)])
        ->assertNotified($message);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'not json' => ['hello', 'That is not an EchoLedger console settings file.'],
    'other format' => ['{"format":"echoledger-settings","settings":{}}', 'That is not an EchoLedger console settings file.'],
    'no settings' => ['{"format":"echoledger-console-settings"}', 'The file has no settings.'],
]);

it('reads only the known keys of a settings file', function () {
    $transfer = app(SettingsTransfer::class);

    expect($transfer->read(json_encode(['format' => SettingsTransfer::FORMAT, 'settings' => ['retention_days' => 7, 'x' => 1]])))->toBe(['retention_days' => 7])
        ->and(fn () => $transfer->read(str_repeat('a', SettingsTransfer::MAX_BYTES + 1)))->toThrow(InvalidArgumentException::class, 'too large');
});

it('keeps the import and export actions for super admins only', function () {
    $this->actingAs($this->makeRegularUser());

    $this->get('/admin/settings')->assertForbidden();
});
