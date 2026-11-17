<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Ask;
use App\Filament\Pages\Live;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\V2RecordingMoments;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Screen;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

require_once __DIR__.'/../Api/Support/client_workspace_helpers.php';

/*
 * The v2.0 console (ui_v2.shell, ui_v2.library): the five-module frame, the Library and recording page on the design
 * values, Ask and Live, and the console language. Off, the console is the classic one (ClassicGoldenTest).
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->admin = $this->signInAsAdmin(['name' => 'Bilal Qureshi']);
    $this->isolatedStorage();
});

/** Turns the v2.0 console's flags on for [$user] only. */
function consoleV2For(User $user, array $flags = ['ui_v2.shell', 'ui_v2.library']): void
{
    foreach ($flags as $flag) {
        app(InterfaceFlags::class)->set($flag, false, 0, [], [$user->id]);
    }
}

function v2Recording(User $owner, array $attributes = []): CaptureSession
{
    return CaptureSession::factory()->create(['user_id' => $owner->id, 'title' => 'Payment terms for the March shipment',
        'source_app' => SourceApp::WhatsApp, 'duration_ms' => 1_104_000, 'status' => SessionStatus::Completed, ...$attributes]);
}

describe('the frame', function () {
    it('shows the five modules, Library\'s level 2 and the bottom bar under ui_v2.shell, and the classic frame without it', function () {
        $classic = (string) $this->get('/app')->assertOk()->getContent();
        expect($classic)->toContain('el-sb')->not->toContain('v2-sb')->not->toContain('data-module=');

        consoleV2For($this->admin, ['ui_v2.shell']);
        $html = (string) $this->get('/app')->assertOk()->getContent();

        foreach (['home', 'library', 'live', 'ask', 'settings'] as $module) {
            expect($html)->toContain('data-module="'.$module.'"');
        }
        expect($html)->toContain('class="fi-sidebar fi-main-sidebar v2-sb"')->toContain('v2-bn')
            ->toContain('All recordings')->toContain('Projects')->toContain('Trash')
            // the design values' head: tokens.css follows Filament's dark mode
            ->toContain('root.dataset.theme')
            ->and(substr_count($html, 'aria-current="page"'))->toBeGreaterThanOrEqual(1);
    });

    it('lists a module this console can\'t open with the reason, never hides it', function () {
        consoleV2For($this->admin, ['ui_v2.shell']);
        $this->admin->forceFill(['console_preferences' => null])->save();

        $html = (string) $this->get('/app')->assertOk()->getContent();

        // Live opens on the web (a voice note, the apps' features listed); every module is there.
        expect(substr_count($html, 'data-module='))->toBeGreaterThanOrEqual(5)->and($html)->not->toContain('aria-disabled="true" title=""');
    });

    it('turns every v2.0 part off with Switch back to classic', function () {
        consoleV2For($this->admin);
        app(InterfaceFlags::class)->setClassic($this->admin, true);

        $html = (string) $this->get('/app/sessions')->assertOk()->getContent();

        expect($html)->not->toContain('v2-sb')->not->toContain('v2-library')->not->toContain('root.dataset.theme');
    });
});

describe('the console language', function () {
    it('offers English and Urdu in Settings › Appearance only under ui_v2.shell, and draws the console right to left in Urdu', function () {
        $page = $this->panelComponent(EditProfile::class, ['subpage' => 'appearance']);
        expect($page->call('setPreference', 'app_language', 'ur')->get('subpage'))->toBe('appearance')
            ->and($this->admin->fresh()->console_preferences['shell']['app_language'] ?? null)->toBeNull();

        consoleV2For($this->admin, ['ui_v2.shell']);
        $this->get(EditProfile::settingsUrl('appearance'))->assertOk()->assertSee('setting-appearance.app_language', false)->assertSee('اردو');
        $this->panelComponent(EditProfile::class, ['subpage' => 'appearance'])->call('setPreference', 'app_language', 'ur');
        expect($this->admin->fresh()->console_preferences['shell']['app_language'])->toBe('ur');

        $html = (string) $this->actingAs($this->admin->fresh(), 'web')->get('/app/sessions')->assertOk()->getContent();
        expect($html)->toContain('lang="ur"')->toContain('dir="rtl"')->toContain('لائبریری')->toContain('تمام ریکارڈنگز');

        // An unknown language is refused; the choice stays.
        $this->panelComponent(EditProfile::class, ['subpage' => 'appearance'])->call('setPreference', 'app_language', 'fr');
        expect($this->admin->fresh()->console_preferences['shell']['app_language'])->toBe('ur');
    });

    it('stays English without the new frame, whatever was chosen', function () {
        $this->admin->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();

        $html = (string) $this->actingAs($this->admin->fresh(), 'web')->get('/app/sessions')->assertOk()->getContent();

        expect($html)->toContain('lang="en"')->toContain('dir="ltr"')->not->toContain('لائبریری');
    });
});

describe('the Library', function () {
    it('lists recordings as the apps\' rows with the saved views as chips, and Untranscribed narrows to recordings without a transcript', function () {
        consoleV2For($this->admin);
        $done = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Assalam o alaikum']],
            ['title' => 'Rate for 12 mm rebar', 'status' => SessionStatus::Completed]);
        $open = v2Recording($this->admin, ['title' => 'Umrah group booking for 14 people']);

        $page = $this->panelComponent(ListCaptureSessions::class);
        $html = $page->html();
        expect($html)->toContain('v2-lib-row')->toContain('Umrah group booking for 14 people')->toContain('Rate for 12 mm rebar')
            ->toContain('data-view="untranscribed"')->toContain('data-view="folder:voice_notes"')->toContain('New voice note')
            ->not->toContain('el-with-col');

        $page->call('openPlace', 'untranscribed');
        expect($page->get('activeTab'))->toBe('untranscribed');
        $page->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$done]);
    });

    it('keeps the classic Library without ui_v2.library', function () {
        consoleV2For($this->admin, ['ui_v2.shell']);
        v2Recording($this->admin);

        $page = $this->panelComponent(ListCaptureSessions::class);

        expect($page->html())->toContain('el-row')->not->toContain('v2-lib-row')
            ->and(array_keys($page->instance()->getCachedTabs()))->not->toContain('untranscribed');
        // Its places don't take Untranscribed.
        $page->call('openPlace', 'untranscribed');
        expect($page->get('activeTab'))->not->toBe('untranscribed');
    });
});

describe('the recording page', function () {
    it('has a back link, the meta line with one status, Share · Move · Delete, and the tabs Transcript · Summary · Ask · Details', function () {
        consoleV2For($this->admin);
        $client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Qureshi Textiles']);
        $recording = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Forty percent advance this time']],
            ['title' => 'Payment terms for the March shipment', 'client_id' => $client->id, 'status' => SessionStatus::Completed]);

        $html = (string) $this->get(CaptureSessionResource::getUrl('view', ['record' => $recording]))->assertOk()->getContent();

        expect($html)->toContain('class="v2-back"')->toContain('v2-rec-meta')->toContain('Transcribed')
            ->toContain('Qureshi Textiles')->toContain('v2-rec-tabs');
        foreach (['Transcript', 'Summary', 'Ask', 'Details', 'Share', 'Move', 'Delete'] as $word) {
            expect($html)->toContain($word);
        }
        expect($html)->toContain(e(Ask::getUrl(['project' => $client->id])));
    });

    it('says why a recording without a project has no summary, and offers to move it', function () {
        consoleV2For($this->admin);
        $recording = v2Recording($this->admin);

        $this->panelComponent(ViewCaptureSession::class, ['record' => $recording->id])
            ->assertSee('No summary yet')->assertSee('Ask works on a project')->assertSee('Move to a project');
    });
});

describe('Ask', function () {
    it('asks one project and shows where the answer comes from', function () {
        consoleV2For($this->admin, ['ui_v2.shell']);
        app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
        $ai = fakeClientAi();
        $this->client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
        $call = $this->sessionWithTranscript($this->admin, [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 125_000, 'end_ms' => 128_000, 'text' => 'We launch on November 15'],
        ], ['client_id' => $this->client->id, 'title' => 'Kick-off']);

        $page = $this->panelComponent(Ask::class)->assertSet('project', $this->client->id)
            ->set('question', 'When do we launch?')->set('askLanguage', 'roman_urdu')->call('ask')->assertHasNoErrors();

        expect($ai->calls[0][0])->toBe('ask')->and($ai->calls[0][1]['language'])->toBe('roman_urdu')
            ->and($page->html())->toContain('Launch is on November 15.')->toContain(e(CaptureSessionResource::getUrl('view', ['record' => $call]).'?t=125000'));
    });

    it('refuses a project that is not the person\'s, and shows the empty state without projects', function () {
        consoleV2For($this->admin, ['ui_v2.shell']);
        $other = Client::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Someone else']);

        $this->panelComponent(Ask::class)->assertSee('No projects yet')
            ->set('project', $other->id)->set('question', 'Anything?')->call('ask')->assertHasErrors(['project']);
    });

    it('opens only under ui_v2.shell, as Live does', function () {
        $this->get(Ask::getUrl())->assertForbidden();
        $this->get(Live::getUrl())->assertForbidden();

        consoleV2For($this->admin, ['ui_v2.shell']);
        $this->get(Ask::getUrl())->assertOk()->assertSee('No projects yet')->assertDontSee('Lorem');
        Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Qureshi Textiles']);
        $this->get(Ask::getUrl())->assertOk()->assertSee('Ask your recordings')->assertSee('Qureshi Textiles');
        $this->get(Live::getUrl())->assertOk()->assertSee('Voice note')->assertSee('In the Android app')->assertSee('Get the apps');
    });
});

it('decides the v2.0 parts per person, in the customer console only', function () {
    expect(ConsoleV2::shell())->toBeFalse()->and(ConsoleV2::language())->toBe('en');
    consoleV2For($this->admin, ['ui_v2.library']);
    expect(ConsoleV2::library())->toBeTrue()->and(ConsoleV2::shell())->toBeFalse()->and(ConsoleV2::any())->toBeTrue();

    Filament::setCurrentPanel('admin');
    expect(ConsoleV2::any())->toBeFalse();
});

describe('Moments', function () {
    it('lists the recording\'s screens with their file name and what was said around them, and opens one large', function () {
        consoleV2For($this->admin);
        $recording = $this->sessionWithTranscript($this->admin, [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 870_000, 'end_ms' => 874_000, 'text' => 'This is the invoice page'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 875_000, 'end_ms' => 879_000, 'text' => 'Press Pay at the bottom'],
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 1_200_000, 'end_ms' => 1_202_000, 'text' => 'Much later'],
        ], ['title' => 'Invoice walkthrough', 'status' => SessionStatus::Completed, 'duration_ms' => 1_300_000]);
        Screen::query()->withoutGlobalScopes()->create(['workspace_id' => $recording->workspace_id, 'capture_session_id' => $recording->id,
            'at_ms' => 872_000, 'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'sha256' => hash('sha256', 'a'), 'mime' => 'image/webp', 'bytes' => 1,
            'description' => "The client's invoice page with the Pay button"]);

        $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $recording->id]);

        $page->assertSee('Screen 1')->assertSee('14:32')->assertSee("The client's invoice page with the Pay button")
            ->assertSee('S01_14-32_the-clients-invoice-page-with-the-pay.webp')
            ->assertSee('Said around it')->assertSee('Me: This is the invoice page')->assertSee('Client: Press Pay at the bottom')
            ->assertSee('Play from here')->assertSee('?t=872000', false);
        // Only what was said within 15 seconds of the screen.
        expect(V2RecordingMoments::for($recording->fresh())[0]['said'])->toBe("Me: This is the invoice page\nClient: Press Pay at the bottom");
    });

    it('says when a recording has no moments', function () {
        consoleV2For($this->admin);

        $this->panelComponent(ViewCaptureSession::class, ['record' => v2Recording($this->admin)->id])->assertSee('No moments in this recording');
    });
});

it('shows the workspace and its role in Urdu under the new frame', function () {
    consoleV2For($this->admin, ['ui_v2.shell']);
    $this->admin->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();

    $html = (string) $this->actingAs($this->admin->fresh(), 'web')->get('/app')->assertOk()->getContent();

    expect($html)->toContain('ذاتی')->toContain('مالک')->not->toContain('>Personal<');
});

it('counts the console\'s questions in the API\'s per-person ask limit, both ways', function () {
    consoleV2For($this->admin, ['ui_v2.shell']);
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $ai = fakeClientAi();
    $client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
    // The bucket the API's throttle:ask uses for this person's devices.
    $key = md5('ask'.'ask:u'.$this->admin->id);

    $this->panelComponent(Ask::class)->set('question', 'When do we launch?')->call('ask')->assertHasNoErrors();
    expect(RateLimiter::attempts($key))->toBe(1);

    foreach (range(2, 20) as $i) {
        RateLimiter::hit($key, 60);
    }
    $calls = count($ai->calls);
    $this->panelComponent(Ask::class)->set('project', $client->id)->set('question', 'And the price?')->call('ask')
        ->assertSee('Too many questions for now');
    expect(count($ai->calls))->toBe($calls);

    // A device of the same person is refused by the API too.
    $device = Device::factory()->create(['user_id' => $this->admin->id]);
    $token = $device->createToken('device', Device::ABILITIES)->plainTextToken;
    $this->withToken($token)->postJson("/api/clients/{$client->id}/ask", ['question' => 'Anything?'])->assertStatus(429);
});

describe('the admin panel', function () {
    it('takes the design values under ui_v2.admin in the same frame', function () {
        Filament::setCurrentPanel('admin');
        expect((string) $this->get('/admin')->assertOk()->getContent())->not->toContain('root.dataset.theme');

        consoleV2For($this->admin, ['ui_v2.admin']);
        $html = (string) $this->get('/admin')->assertOk()->getContent();

        // the classic sidebar and top bar, no v2.0 module navigation (AdminClassicGoldenTest compares the whole page)
        expect($html)->toContain('root.dataset.theme')->toContain('el-sb')->not->toContain('v2-sb')->not->toContain('data-module=');
    });

    it('leaves the console alone under ui_v2.admin, and the admin panel alone under the console\'s flags', function () {
        consoleV2For($this->admin, ['ui_v2.admin']);
        expect((string) $this->get('/app')->assertOk()->getContent())->not->toContain('root.dataset.theme');

        consoleV2For($this->admin, ['ui_v2.shell', 'ui_v2.library']);
        app(InterfaceFlags::class)->set('ui_v2.admin', false, 0, [], []);
        Filament::setCurrentPanel('admin');
        expect((string) $this->get('/admin')->assertOk()->getContent())->not->toContain('root.dataset.theme');
    });
});
