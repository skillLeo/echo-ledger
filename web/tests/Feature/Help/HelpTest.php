<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Pages\Help;
use App\Mail\SupportMessageMail;
use App\Models\AppRelease;
use App\Models\AuditEvent;
use App\Models\DeviceReport;
use App\Services\Help\Guides;
use App\Services\Help\SupportMessages;
use App\Services\SettingsRepository;
use App\Services\SettingsTransfer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * In-app help (v1.8 P6.4): the user guide searched, What's new from the releases' notes, Contact support to the
 * server's support address with a device report only when ticked.
 */

beforeEach(fn () => Mail::fake());

/** A release of [$platform] with [$notes]. */
function helpRelease(string $platform, string $version, ?string $notes, string $at): AppRelease
{
    return AppRelease::query()->forceCreate(['platform' => $platform, 'version' => $version, 'code' => $platform === AppRelease::ANDROID ? (int) str_replace('.', '', $version) : null,
        'path' => "releases/{$platform}-{$version}", 'file_name' => "f-{$platform}-{$version}", 'bytes' => 1, 'sha256' => str_repeat('a', 64),
        'notes' => $notes, 'created_at' => $at, 'updated_at' => $at]);
}

it('serves the user guide as docs/USER_GUIDE.md has it (only web/ is deployed: the copy must not drift)', function () {
    expect(file_get_contents(resource_path(Guides::PATH)))->toBe(file_get_contents(base_path('../docs/USER_GUIDE.md')));
});

describe('the device API', function () {
    it('lists the guide\'s sections, finds them by their words (the title first) and opens one', function () {
        $api = $this->api(Fx::token(Fx::device()));

        $all = $api->getJson('/api/help/guides')->assertOk()->json('data');
        expect(count($all))->toBe(count(app(Guides::class)->all()))->and($all[0]['title'])->toBe('Install the Windows app');
        $found = $api->getJson('/api/help/guides?q=after+call')->assertOk()->json('data');
        expect($found[0])->toMatchArray(['id' => 'after-a-call', 'title' => 'After a call'])->and($found[0]['excerpt'])->toBeString()->not->toBe('');
        expect($api->getJson('/api/help/guides?q=zzzqqqxx')->json('data'))->toBe([]);

        $guide = $api->getJson('/api/help/guides/after-a-call')->assertOk();
        expect($guide->json('title'))->toBe('After a call')->and($guide->json('markdown'))->toContain('Keep automatically');
        $api->getJson('/api/help/guides/no-such-guide')->assertNotFound();
    });

    it('gives What\'s new from the releases that have notes, newest first, per platform', function () {
        helpRelease(AppRelease::ANDROID, '1.8.0', "New: missed recordings.\nFixed: the pill.", '2026-11-10 10:00:00');
        helpRelease(AppRelease::ANDROID, '1.8.1', null, '2026-11-12 10:00:00');
        helpRelease(AppRelease::ANDROID, '1.8.2', 'Faster uploads.', '2026-11-14 10:00:00');
        helpRelease(AppRelease::MAC_ARM, '1.8.0', 'Mac: the Dock icon.', '2026-11-11 10:00:00');
        helpRelease(AppRelease::MAC_INTEL, '1.8.0', 'Mac: the Dock icon.', '2026-11-11 10:00:00');
        $api = $this->api(Fx::token(Fx::device()));

        expect($api->getJson('/api/help/whats-new?platform=android')->assertOk()->json('data'))->toBe([
            ['version' => '1.8.2', 'platform' => 'android', 'released_at' => '2026-11-14T10:00:00+00:00', 'notes' => 'Faster uploads.'],
            ['version' => '1.8.0', 'platform' => 'android', 'released_at' => '2026-11-10T10:00:00+00:00', 'notes' => "New: missed recordings.\nFixed: the pill."],
        ])->and($api->getJson('/api/help/whats-new?platform=mac')->json('data'))->toHaveCount(1)
            ->and($api->getJson('/api/help/whats-new')->json('data'))->toHaveCount(3);
        $api->getJson('/api/help/whats-new?platform=linux')->assertStatus(422);
    });

    it('writes to the support address, the person to reply to, the device report only when ticked, and never keeps the words', function () {
        $device = Fx::device(Fx::user(['name' => 'Sana', 'email' => 'sana@example.test']), ['platform' => 'android']);
        DeviceReport::query()->withoutGlobalScopes()->create(['device_id' => $device->id, 'user_id' => $device->user_id, 'workspace_id' => $device->workspace_id,
            'platform' => 'android', 'model' => 'S25', 'app_version' => '1.8.2', 'checks' => [['key' => 'voice_notes', 'state' => 'ok', 'detail' => null]], 'reported_at' => now()]);
        $api = $this->api(Fx::token($device));

        $api->postJson('/api/support/messages', ['subject' => 'Captions stop', 'message' => 'SECRET-SUPPORT-WORDS after a minute.'])->assertStatus(202);
        $api->postJson('/api/support/messages', ['subject' => 'With the report', 'message' => 'See attached.', 'include_device_report' => true])->assertStatus(202);

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        Mail::assertQueued(SupportMessageMail::class, 2);
        Mail::assertQueued(SupportMessageMail::class, fn (SupportMessageMail $m): bool => $m->hasTo("contact@{$host}") && $m->hasReplyTo('sana@example.test')
            && $m->topic === 'Captions stop' && $m->reports === [] && $m->attachments() === []);
        Mail::assertQueued(SupportMessageMail::class, fn (SupportMessageMail $m): bool => $m->topic === 'With the report' && count($m->reports) === 1
            && $m->reports[0]['model'] === 'S25' && count($m->attachments()) === 1);
        expect(AuditEvent::query()->where('event', 'support_message_sent')->count())->toBe(2)
            ->and(AuditEvent::query()->where('event', 'support_message_sent')->pluck('summary')->implode(' '))->not->toContain('SECRET-SUPPORT-WORDS');

        // The admin's address once set; and five an hour per person, whichever device writes.
        app(SettingsRepository::class)->set('support.email', 'help@example.test');
        expect(app(SupportMessages::class)->address())->toBe('help@example.test');
        $other = $this->api(Fx::token(Fx::device($device->user, ['workspace_id' => $device->workspace_id])));
        foreach (range(1, 3) as $i) {
            $other->postJson('/api/support/messages', ['subject' => "s{$i}", 'message' => 'm'])->assertStatus(202);
        }
        $other->postJson('/api/support/messages', ['subject' => 'six', 'message' => 'm'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        // The console counts in the same bucket: the person's.
        Filament::setCurrentPanel('app');
        $this->actingAs($device->user->fresh(), 'web');
        Livewire::test(Help::class)->callAction('contactSupport', ['subject' => 'From the console', 'message' => 'm'])->assertNotified();
        Mail::assertQueued(SupportMessageMail::class, 5);
    });

    it('counts a send before it goes: at 4 used, of two at once only one passes', function () {
        $user = Fx::user();
        foreach (range(1, 4) as $i) {
            RateLimiter::hit('support:u'.$user->id, 3600);
        }
        $support = app(SupportMessages::class);
        $outcomes = [];
        foreach (range(1, 2) as $i) {
            try {
                $support->send($user, "s{$i}", 'm', false);
                $outcomes[] = 'sent';
            } catch (ApiError $e) {
                $outcomes[] = $e->apiCode();
            }
        }
        expect($outcomes)->toBe(['sent', 'too_many_attempts']);
        Mail::assertQueued(SupportMessageMail::class, 1);
        // The check and the count run under the person's lock, released after each send.
        expect(Cache::lock('support:u'.$user->id.':lock', 10)->get())->toBeTrue();
    });

    it('asks for a confirmed email first: the reply goes to it', function () {
        $user = Fx::user(['email' => 'unconfirmed@example.test']);
        $user->forceFill(['email_verified_at' => null])->save();

        $this->api(Fx::token(Fx::device($user)))->postJson('/api/support/messages', ['subject' => 'Hi', 'message' => 'm'])
            ->assertForbidden()->assertJsonPath('code', 'email_not_verified');
        Mail::assertNothingQueued();
    });

    it('shows the person\'s words as written: no link, image, heading or HTML of theirs in the email', function () {
        $user = Fx::user(['name' => 'Eve **Bold**', 'email' => 'eve@example.test']);
        $mail = new SupportMessageMail($user->name, $user->email, '# [Click](https://evil.example) <b>now</b>',
            "## A heading\n![tracker](https://evil.example/p.png)\n[my link](javascript:alert(1))\n<script>alert(1)</script>\n> quoted", [], 'android');

        $html = $mail->render();
        expect($html)->not->toContain('href="https://evil.example')->not->toContain('href="javascript')->not->toContain('src="https://evil.example')
            ->not->toContain('<script')->not->toContain('<b>now')->not->toContain('<h2')->not->toContain('<strong>Bold')
            ->toContain('## A heading')->toContain('[my link](javascript:alert(1))')->toContain('&lt;script');
    });
});

describe('the console', function () {
    it('searches the guide, opens a section with its HTML escaped, shows What\'s new, and writes to support', function () {
        helpRelease(AppRelease::WINDOWS, '1.8.1', 'Windows: captions you can drag.', '2026-11-12 10:00:00');
        Filament::setCurrentPanel('app');
        $user = Fx::user(['email' => 'reader@example.test']);
        $this->actingAs($user, 'web');

        $this->get(Help::getUrl())->assertOk()->assertSee('Search the guide')->assertSee('Windows 1.8.1')->assertSee('captions you can drag');
        $page = Livewire::test(Help::class)->set('q', 'voice note')->assertSee('Voice notes', false)
            ->call('open', 'after-a-call')->assertSee('Keep automatically');
        expect($page->get('guide'))->toBe('after-a-call');
        // A guide's words are shown, never run.
        expect(Str::markdown('<script>x</script>', ['html_input' => 'escape']))->not->toContain('<script>');

        $page->callAction('contactSupport', ['subject' => 'From the console', 'message' => 'Hello', 'include_device_report' => false])->assertNotified('Message sent');
        Mail::assertQueued(SupportMessageMail::class, fn (SupportMessageMail $m): bool => $m->hasReplyTo('reader@example.test') && $m->platform === null);
    });

    it('keeps the support address in Admin → Settings, in the settings export too', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');

        Livewire::test(Settings::class)->set('data.support_email', 'Support@Example.test')->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('support.email'))->toBe('support@example.test')
            ->and(SettingsTransfer::PORTABLE)->toContain('support.email');
    });
});
