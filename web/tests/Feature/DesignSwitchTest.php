<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\InterfaceFlagsPage;
use App\Models\InterfaceFlag;
use App\Models\User;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * The design switch both ways (contract 2.0.0-g, docs/API.md "The design switch"): availability off / opt_in /
 * everyone, the person's own choice, and the order: classic > everyone > v2 with opt-in > the admin's lists > off.
 */

/** Sets every console flag (and only those) to [$availability], with [$people] on the lists. */
function consoleFlags(string $availability, array $people = []): void
{
    foreach (InterfaceFlags::CONSOLE as $flag) {
        app(InterfaceFlags::class)->set($flag, $availability === 'everyone', 0, [], $people, $availability);
    }
}

function shellFor(User $user): bool
{
    return app(InterfaceFlags::class)->active('ui_v2.shell', $user->fresh());
}

function chooses(User $user, ?string $design): User
{
    app(InterfaceFlags::class)->setDesign($user, $design);

    return $user->fresh();
}

describe('the order of the rules', function () {
    it('1. a person who chose classic gets no new screen, even when it is on for everyone', function () {
        consoleFlags('everyone');
        $person = chooses(Fx::user(), 'classic');

        expect(shellFor($person))->toBeFalse()
            ->and(app(InterfaceFlags::class)->design($person))->toBe(['choice' => 'classic', 'current' => 'classic', 'can_try' => true, 'can_switch_back' => false]);
    });

    it('2. on for everyone reaches a person who never chose', function () {
        consoleFlags('everyone');

        expect(shellFor(Fx::user()))->toBeTrue();
    });

    it('3. opt-in reaches only those who chose the new design; not available reaches nobody who chose it', function () {
        consoleFlags('opt_in');
        $never = Fx::user();
        $tried = chooses(Fx::user(), 'v2');

        expect(shellFor($never))->toBeFalse()->and(shellFor($tried))->toBeTrue()
            ->and(app(InterfaceFlags::class)->design($never))->toMatchArray(['current' => 'classic', 'can_try' => true])
            ->and(app(InterfaceFlags::class)->design($tried))->toMatchArray(['current' => 'v2', 'can_switch_back' => true]);

        consoleFlags('off');
        expect(shellFor($tried))->toBeFalse()
            ->and(app(InterfaceFlags::class)->design($tried))->toMatchArray(['choice' => 'v2', 'current' => 'classic', 'can_try' => false]);
    });

    it('4. the admin\'s lists reach a listed person whatever the availability, unless they chose classic', function () {
        $listed = Fx::user();
        consoleFlags('off', [$listed->id]);

        expect(shellFor($listed))->toBeTrue()->and(shellFor(Fx::user()))->toBeFalse()
            ->and(shellFor(chooses($listed, 'classic')))->toBeFalse();
    });

    it('5. otherwise off: classic is the default for everyone', function () {
        consoleFlags('opt_in');

        expect(app(InterfaceFlags::class)->for(Fx::user()))->each->toBeFalse();
    });

    it('keeps Live Assist out of the design switch: no opt-in, and choosing v2 does not turn it on', function () {
        app(InterfaceFlags::class)->set('live_assist', false, 0, [], [], 'opt_in');
        $tried = chooses(Fx::user(), 'v2');

        expect(InterfaceFlag::query()->where('flag', 'live_assist')->value('availability'))->toBe('off')
            ->and(app(InterfaceFlags::class)->active('live_assist', $tried))->toBeFalse();
    });
});

describe('the API', function () {
    it('serves ui_design in GET /api/config and GET /api/account/ui, and switches both ways with PUT', function () {
        consoleFlags('opt_in');
        $user = Fx::user();
        $api = $this->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])));

        expect($api->getJson('/api/config')->json('ui_design'))->toBe(['choice' => null, 'current' => 'classic', 'can_try' => true, 'can_switch_back' => false]);
        $api->getJson('/api/account/ui')->assertOk()->assertJsonPath('ui_design.can_try', true)->assertJsonPath('ui_classic', false);

        $tried = $api->putJson('/api/account/ui', ['design' => 'v2'])->assertOk()
            ->assertJsonPath('ui_design', ['choice' => 'v2', 'current' => 'v2', 'can_try' => false, 'can_switch_back' => true]);
        expect($tried->json('ui_flags')['ui_v2.shell'])->toBeTrue()->and($tried->json('ui_flags')['live_assist'])->toBeFalse()
            ->and($api->getJson('/api/config')->json('ui_flags')['ui_v2.library'])->toBeTrue();

        $back = $api->putJson('/api/account/ui', ['design' => 'classic'])->assertOk()
            ->assertJsonPath('ui_classic', true)->assertJsonPath('ui_design.current', 'classic');
        expect($back->json('ui_flags')['ui_v2.shell'])->toBeFalse();
        $api->putJson('/api/account/ui', ['design' => null])->assertOk()
            ->assertJsonPath('ui_design.choice', null)->assertJsonPath('ui_classic', false);
        expect($user->fresh()->ui_design)->toBeNull();
    });

    it('refuses the new design when nothing is open to try, and anything that is not a design', function () {
        consoleFlags('off');
        $user = Fx::user();
        $api = $this->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])));

        $api->putJson('/api/account/ui', ['design' => 'v2'])->assertStatus(422)->assertJsonPath('code', 'design_not_available');
        $api->putJson('/api/account/ui', ['design' => 'v3'])->assertStatus(422);
        expect($user->fresh()->ui_design)->toBeNull();
    });

    it('still takes 2.0.0-a\'s {"classic": …}: true is classic, false no choice', function () {
        consoleFlags('everyone');
        $user = Fx::user();
        $api = $this->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])));

        $api->putJson('/api/account/ui', ['classic' => true])->assertOk()->assertJsonPath('ui_design.choice', 'classic');
        expect($user->fresh()->ui_design)->toBe('classic');
        $api->putJson('/api/account/ui', ['classic' => false])->assertOk()->assertJsonPath('ui_design.choice', null)->assertJsonPath('ui_design.current', 'v2');
    });

    it('needs config:read to read or change the design', function () {
        $device = Fx::device();
        $api = $this->api(Fx::token($device, ['sessions:write']));

        $api->getJson('/api/account/ui')->assertForbidden();
        $api->putJson('/api/account/ui', ['design' => 'v2'])->assertForbidden();
    });
});

describe('the admin page', function () {
    beforeEach(fn () => Filament::setCurrentPanel('admin'));

    it('sets the nine console screens together, and each flag on its own', function () {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin, 'web');

        Livewire::test(InterfaceFlagsPage::class)
            ->set('data.console_availability', 'everyone')
            ->assertSet('data.ui_v2__shell.availability', 'everyone')->assertSet('data.ui_v2__setup_health.availability', 'everyone')
            ->set('data.ui_v2__website.availability', 'off')
            ->set('data.ui_v2__admin.availability', 'opt_in')
            ->call('save')->assertHasNoErrors();

        $availability = fn (string $flag): ?string => InterfaceFlag::query()->where('flag', $flag)->value('availability');
        expect(array_map($availability, InterfaceFlags::CONSOLE))->each->toBe('everyone')
            ->and($availability('ui_v2.website'))->toBe('off')->and($availability('ui_v2.admin'))->toBe('opt_in')
            ->and(InterfaceFlag::query()->where('flag', 'ui_v2.home')->value('everyone'))->toBeTrue();

        Livewire::test(InterfaceFlagsPage::class)->assertSet('data.console_availability', 'everyone')
            ->set('data.console_availability', 'opt_in')->call('save');
        expect($availability('ui_v2.library'))->toBe('opt_in')->and(InterfaceFlag::query()->where('flag', 'ui_v2.library')->value('everyone'))->toBeFalse();
    });
});
