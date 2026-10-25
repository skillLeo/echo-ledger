<?php

declare(strict_types=1);

use App\Models\Credential;
use App\Models\Setting;
use Livewire\Livewire;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/** Loads a console page as the current user and returns the snapshot of the named Livewire component. */
function adminSnapshotOf($test, string $url, string $componentName): string
{
    $html = $test->get($url)->assertOk()->getContent();
    preg_match_all('/<div[^>]*wire:snapshot="([^"]*)"[^>]*wire:name="([^"]*)"/s', $html, $found);
    foreach ($found[2] as $index => $name) {
        if ($name === $componentName) {
            return html_entity_decode($found[1][$index], ENT_QUOTES | ENT_HTML5);
        }
    }

    throw new RuntimeException("No {$componentName} component on {$url}");
}

/** Replays a component update the way the browser would. */
function adminReplay($test, string $snapshot, array $updates = [], array $calls = [])
{
    return $test->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ], ['X-Livewire' => 'true']);
}

it('accepts a component update from the signed-in admin', function () {
    $this->actingAs($this->makeAdmin(), 'web');
    $snapshot = adminSnapshotOf($this, '/admin/settings', 'App\Filament\Pages\Settings');

    $response = adminReplay($this, $snapshot, ['data.default_language' => 'ur']);

    $response->assertOk()->assertJsonStructure(['components']);
});

it('refuses a settings update once the admin has signed out', function () {
    $this->actingAs($this->makeAdmin(), 'web');
    $snapshot = adminSnapshotOf($this, '/admin/settings', 'App\Filament\Pages\Settings');
    auth()->logout();

    adminReplay($this, $snapshot, ['data.retention_days' => 7], [['path' => '', 'method' => 'save', 'params' => []]])->assertUnauthorized();

    expect(Setting::query()->count())->toBe(0);
});

it('refuses a credentials update once the admin has signed out', function () {
    $this->actingAs($this->makeAdmin(), 'web');
    $snapshot = adminSnapshotOf($this, '/admin/api-credentials', 'App\Filament\Pages\ApiCredentials');
    auth()->logout();

    adminReplay($this, $snapshot, ['data.assemblyai__api_key' => 'planted-by-a-guest'], [['path' => '', 'method' => 'save', 'params' => []]])->assertUnauthorized();

    expect(Credential::query()->count())->toBe(0);
});

it('refuses a replayed admin snapshot from a signed-in account that is not a super admin', function () {
    $this->actingAs($this->makeAdmin(), 'web');
    $settings = adminSnapshotOf($this, '/admin/settings', 'App\Filament\Pages\Settings');
    $credentials = adminSnapshotOf($this, '/admin/api-credentials', 'App\Filament\Pages\ApiCredentials');
    auth()->logout();
    $this->actingAs($this->makeRegularUser(), 'web');

    adminReplay($this, $settings, ['data.retention_days' => 7], [['path' => '', 'method' => 'save', 'params' => []]])->assertForbidden();
    adminReplay($this, $credentials, ['data.groq__api_key' => 'planted-by-staff'], [['path' => '', 'method' => 'save', 'params' => []]])->assertForbidden();

    expect(Setting::query()->count())->toBe(0)->and(Credential::query()->count())->toBe(0);
});

it('rejects a tampered snapshot', function () {
    $this->actingAs($this->makeAdmin(), 'web');
    $snapshot = json_decode(adminSnapshotOf($this, '/admin/settings', 'App\Filament\Pages\Settings'), true);
    $snapshot['data']['data'][0]['retention_days'] = 365;
    $tampered = json_encode($snapshot);

    $status = adminReplay($this, $tampered, [], [['path' => '', 'method' => 'save', 'params' => []]])->getStatusCode();

    expect($status)->toBeGreaterThanOrEqual(400);
    expect(Setting::query()->count())->toBe(0);
});
