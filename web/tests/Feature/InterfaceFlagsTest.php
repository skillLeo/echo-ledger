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
        // Since 2.0.0-r: v2.1's five.
        'v21_aftercall_notify', 'v21_aftercall_card', 'v21_panel_flyout', 'v21_zoom', 'v21_preview_pane',
    ])->and($classic)->toBeFalse();
});

describe('the v2.1 flags (contract 2.0.0-r)', function () {
    it('starts each off with empty lists, so it is off for everyone until the admin lists someone', function () {
        foreach (InterfaceFlags::V21 as $flag) {
            expect(InterfaceFlag::query()->where('flag', $flag)->exists())->toBeFalse($flag);
        }
        $user = Fx::user();
        foreach (InterfaceFlags::V21 as $flag) {
            expect(uiFlagsOf($user)[0][$flag])->toBeFalse($flag);
        }
    });

    it('turns each on for the people, the workspaces or the share the admin lists, and for nobody else', function () {
        [$owner, $teammate, $other] = [Fx::user(), Fx::user(), Fx::user()];
        foreach (InterfaceFlags::V21 as $i => $flag) {
            app(InterfaceFlags::class)->set($flag, false, 0, $i === 0 ? [$teammate->current_workspace_id] : [], [$owner->id]);
        }

        expect(uiFlagsOf($owner)[0])->toBe(flagsWith(InterfaceFlags::V21))
            ->and(uiFlagsOf($teammate)[0])->toBe(flagsWith(['v21_aftercall_notify']))
            ->and(uiFlagsOf($other)[0])->toBe(flagsWith());
        app(InterfaceFlags::class)->set('v21_zoom', true, 0, [], []);
        expect(uiFlagsOf($other)[0])->toBe(flagsWith(['v21_zoom']));
    });

    it('has no opt-in, and the person\'s design choice neither turns one on nor off', function () {
        $flags = app(InterfaceFlags::class);
        foreach (InterfaceFlags::UI_V2 as $flag) {
            $flags->set($flag, false, 0, [], [], 'opt_in');
        }
        $flags->set('v21_preview_pane', false, 0, [], [], 'opt_in');
        expect(InterfaceFlag::query()->where('flag', 'v21_preview_pane')->value('availability'))->toBe('off');

        $tries = Fx::user();
        $flags->setDesign($tries, 'v2');
        expect($flags->for($tries->fresh()))->toMatchArray(['ui_v2.home' => true, 'v21_preview_pane' => false]);

        $listed = Fx::user();
        $flags->set('v21_panel_flyout', false, 0, [], [$listed->id]);
        $flags->setDesign($listed, 'classic');
        expect($flags->for($listed->fresh()))->toMatchArray(['ui_v2.home' => false, 'v21_panel_flyout' => true]);
    });

    it('is in GET /api/account/ui as in GET /api/config', function () {
        $owner = Fx::user();
        app(InterfaceFlags::class)->set('v21_aftercall_card', false, 0, [], [$owner->id]);
        $ui = test()->api(Fx::token(Fx::device($owner, ['workspace_id' => $owner->current_workspace_id])))->getJson('/api/account/ui')->assertOk()->json('ui_flags');

        expect($ui)->toBe(flagsWith(['v21_aftercall_card']))->and($ui['v21_aftercall_card'] ?? null)->toBeTrue();
    });
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

it('turns every ui_v2 flag off with Switch back to classic, but not live_assist or the v2.1 flags, and back on', function () {
    $user = Fx::user();
    $flags = app(InterfaceFlags::class);
    foreach (InterfaceFlags::names() as $name) {
        $flags->set($name, true, 0, [], []);
    }
    $api = $this->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])));

    $api->putJson('/api/account/ui', ['classic' => true])->assertOk()
        // Since 2.0.0-r the v2.1 flags are not the design either: classic leaves them as the admin set them.
        ->assertExactJson(['ui_flags' => flagsWith(['live_assist', ...InterfaceFlags::V21]), 'ui_classic' => true,
            // A person who went back to classic can try the new design again.
            'ui_design' => ['choice' => 'classic', 'current' => 'classic', 'can_try' => true, 'can_switch_back' => false]]);
    expect($api->getJson('/api/config')->json('ui_flags'))->toBe(flagsWith(['live_assist', ...InterfaceFlags::V21]))
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

        // Since 2.0.0-r v2.1's five flags are there too (off or everyone, like Live Assist), set here for one person.
        $page = Livewire::test(InterfaceFlagsPage::class);
        foreach (InterfaceFlags::V21 as $flag) {
            $page->assertSee($flag)->assertSee(InterfaceFlags::LABELS[$flag]);
        }
        $page->set('data.v21_panel_flyout.people', [$sana->id])->call('save')->assertHasNoErrors();
        expect(app(InterfaceFlags::class)->for($sana))->toMatchArray(['v21_panel_flyout' => true, 'v21_zoom' => false])
            ->and(app(InterfaceFlags::class)->for(Fx::user()))->toMatchArray(['v21_panel_flyout' => false]);

        $this->actingAs(Fx::user(), 'web');
        expect(InterfaceFlagsPage::canAccess())->toBeFalse();
        $this->get(InterfaceFlagsPage::getUrl(panel: 'admin'))->assertForbidden();
    });
});
