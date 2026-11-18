<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\InterfaceFlagsPage;
use App\Filament\Auth\EditProfile;
use App\Filament\Support\SettingsContext;
use App\Models\InterfaceFlag;
use App\Models\User;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * Interface flags (contract 2.0.0-a, docs/API.md "Interface flags"): the redesigned screens and Live Assist, off by
 * default, on for everyone, a share of accounts, a workspace or a person, and "Switch back to classic".
 */

/** GET /api/config's `ui_flags` and `ui_classic` for [$user]'s own device. */
function uiFlagsOf(User $user): array
{
    $answer = test()->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])))->getJson('/api/config')->assertOk();

    return [$answer->json('ui_flags'), $answer->json('ui_classic')];
}

/** Every flag false but [$on]. */
function flagsWith(array $on = []): array
{
    return array_combine(InterfaceFlags::names(), array_map(fn (string $f): bool => in_array($f, $on, true), InterfaceFlags::names()));
}

it('answers every flag, off, by default, in the contract\'s order', function () {
    [$flags, $classic] = uiFlagsOf(Fx::user());

    expect($flags)->toBe(flagsWith())->and(array_keys($flags))->toBe([
        'ui_v2.shell', 'ui_v2.home', 'ui_v2.library', 'ui_v2.recording_controls', 'ui_v2.after_call', 'ui_v2.live',
        'ui_v2.ask', 'ui_v2.settings', 'ui_v2.setup_health', 'ui_v2.admin', 'ui_v2.website', 'live_assist',
    ])->and($classic)->toBeFalse();
});

it('turns a flag on for everyone', function () {
    app(InterfaceFlags::class)->set('ui_v2.home', true, 0, [], []);

    expect(uiFlagsOf(Fx::user())[0])->toBe(flagsWith(['ui_v2.home']))
        ->and(uiFlagsOf(Fx::user())[0])->toBe(flagsWith(['ui_v2.home']));
});

it('turns a flag on for one person only', function () {
    [$sana, $omar] = [Fx::user(), Fx::user()];
    app(InterfaceFlags::class)->set('ui_v2.library', false, 0, [], [$sana->id]);

    expect(uiFlagsOf($sana)[0])->toBe(flagsWith(['ui_v2.library']))
        ->and(uiFlagsOf($omar)[0])->toBe(flagsWith());
});

it('turns a flag on for one workspace, by the device\'s workspace', function () {
    [$sana, $omar] = [Fx::user(), Fx::user()];
    app(InterfaceFlags::class)->set('live_assist', false, 0, [$sana->current_workspace_id], []);

    expect(uiFlagsOf($sana)[0])->toBe(flagsWith(['live_assist']))
        ->and(uiFlagsOf($omar)[0])->toBe(flagsWith());
});

it('turns a flag on for a share of accounts, the same accounts every time', function () {
    $people = collect(range(1, 40))->map(fn () => Fx::user());
    $flags = app(InterfaceFlags::class);
    $flags->set('ui_v2.ask', false, 30, [], []);

    foreach ($people->take(12) as $person) {
        $expected = InterfaceFlags::bucket('ui_v2.ask', $person->id) < 30;
        expect(uiFlagsOf($person)[0]['ui_v2.ask'])->toBe($expected)
            ->and(uiFlagsOf($person)[0]['ui_v2.ask'])->toBe($expected);
    }
    // About the share asked for, and a larger share keeps everyone who had it.
    $on = $people->filter(fn (User $p): bool => $flags->active('ui_v2.ask', $p));
    expect($on->count())->toBeGreaterThan(2)->toBeLessThan(25);
    $flags->set('ui_v2.ask', false, 60, [], []);
    expect($on->every(fn (User $p): bool => $flags->active('ui_v2.ask', $p)))->toBeTrue();
    // 100 is everyone, 0 nobody.
    $flags->set('ui_v2.ask', false, 100, [], []);
    expect($people->every(fn (User $p): bool => $flags->active('ui_v2.ask', $p)))->toBeTrue();
    $flags->set('ui_v2.ask', false, 0, [], []);
    expect($people->contains(fn (User $p): bool => $flags->active('ui_v2.ask', $p)))->toBeFalse();
});

it('turns every ui_v2 flag off with Switch back to classic, but not live_assist, and back on', function () {
    $user = Fx::user();
    $flags = app(InterfaceFlags::class);
    foreach (InterfaceFlags::names() as $name) {
        $flags->set($name, true, 0, [], []);
    }
    $api = $this->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])));

    $api->putJson('/api/account/ui', ['classic' => true])->assertOk()
        ->assertExactJson(['ui_flags' => flagsWith(['live_assist']), 'ui_classic' => true,
            // A person who went back to classic can try the new design again.
            'ui_design' => ['choice' => 'classic', 'current' => 'classic', 'can_try' => true, 'can_switch_back' => false]]);
    expect($api->getJson('/api/config')->json('ui_flags'))->toBe(flagsWith(['live_assist']))
        ->and($user->fresh()->ui_classic)->toBeTrue();

    $api->putJson('/api/account/ui', ['classic' => false])->assertOk()->assertJsonPath('ui_classic', false);
    expect($api->getJson('/api/config')->json('ui_flags'))->toBe(flagsWith(InterfaceFlags::names()));
    $api->putJson('/api/account/ui', ['classic' => 'maybe'])->assertStatus(422);
    $api->putJson('/api/account/ui', [])->assertStatus(422);
});

it('ignores a flag it does not know', function () {
    $flags = app(InterfaceFlags::class);
    $flags->set('ui_v3.everything', true, 100, [], []);
    expect(InterfaceFlag::query()->where('flag', 'ui_v3.everything')->exists())->toBeFalse();
    // A row made some other way is not answered either.
    InterfaceFlag::query()->create(['flag' => 'ui_v3.everything', 'everyone' => true]);
    $flags->forget();

    expect(uiFlagsOf(Fx::user())[0])->toBe(flagsWith())
        ->and($flags->active('ui_v3.everything', Fx::user()))->toBeFalse();
});

it('needs config:read to switch back to classic', function () {
    $device = Fx::device();
    $this->api(Fx::token($device, ['sessions:write']))->putJson('/api/account/ui', ['classic' => true])->assertForbidden();
    expect($device->user->fresh()->ui_classic)->toBeFalse();
});

describe('the console', function () {
    beforeEach(fn () => Filament::setCurrentPanel('app'));

    it('offers Try the new design in classic Appearance and Switch back to classic in the new one, both ways', function () {
        $user = Fx::user();
        $this->actingAs($user, 'web');
        $page = fn (): string => (string) $this->get('/app/settings/appearance')->assertOk()->getContent();
        foreach (InterfaceFlags::CONSOLE as $flag) {
            app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
        }
        SettingsContext::forget();
        // Nothing open to try: neither row.
        expect($page())->not->toContain('Try the new design')->not->toContain('Switch back to classic');

        // Opt-in: classic offers Try the new design.
        foreach (InterfaceFlags::CONSOLE as $flag) {
            app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'opt_in');
        }
        SettingsContext::forget();
        expect($page())->toContain('Try the new design')->not->toContain('Switch back to classic');

        Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->call('tryNewDesign')->assertRedirect('/app/settings/appearance');
        expect($user->fresh()->ui_design)->toBe('v2')
            ->and(app(InterfaceFlags::class)->active('ui_v2.shell', $user->fresh()))->toBeTrue();
        SettingsContext::forget();
        expect($page())->toContain('Switch back to classic')->not->toContain('Try the new design');

        // And back.
        Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->call('switchBackToClassic')->assertRedirect('/app/settings/appearance');
        expect($user->fresh()->ui_design)->toBe('classic')->and($user->fresh()->ui_classic)->toBeTrue()
            ->and(app(InterfaceFlags::class)->active('ui_v2.shell', $user->fresh()))->toBeFalse();
        SettingsContext::forget();
        expect($page())->toContain('Try the new design');
    });

    it('does nothing when Try the new design is not open to the person', function () {
        $user = Fx::user();
        $this->actingAs($user, 'web');
        foreach (InterfaceFlags::CONSOLE as $flag) {
            app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
        }

        Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->call('tryNewDesign')->assertNoRedirect();
        expect($user->fresh()->ui_design)->toBeNull();
    });
});

describe('the admin panel', function () {
    beforeEach(fn () => Filament::setCurrentPanel('admin'));

    it('lets the super admin set each flag, and nobody else open the page', function () {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $sana = Fx::user();
        $this->actingAs($admin, 'web');

        Livewire::test(InterfaceFlagsPage::class)->assertOk()->assertSee('Live Assist')
            ->set('data.ui_v2__home.availability', 'everyone')
            ->set('data.live_assist.people', [$sana->id])
            ->set('data.ui_v2__library.percent', 25)
            ->call('save')->assertHasNoErrors()->assertNotified('Saved');

        expect(InterfaceFlag::query()->where('flag', 'ui_v2.home')->value('everyone'))->toBeTrue()
            ->and(InterfaceFlag::query()->where('flag', 'ui_v2.library')->value('percent'))->toBe(25)
            ->and(app(InterfaceFlags::class)->for($sana))->toMatchArray(['ui_v2.home' => true, 'live_assist' => true]);

        $this->actingAs(Fx::user(), 'web');
        expect(InterfaceFlagsPage::canAccess())->toBeFalse();
        $this->get(InterfaceFlagsPage::getUrl(panel: 'admin'))->assertForbidden();
    });
});
