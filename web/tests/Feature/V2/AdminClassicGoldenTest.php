<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

require_once __DIR__.'/Support/golden.php';

/*
 * The classic admin panel stays exactly as it is while ui_v2.admin is off: the dashboard, Customers, Settings and
 * Server usage, for fixed staff and customers at a fixed time, are compared with the HTML saved in
 * tests/Feature/V2/golden/ before the admin re-theme. UPDATE_GOLDEN=1 saves them again on purpose.
 */

/** A fixed member of staff and two customers at a fixed time. */
function classicAdmin(object $test): User
{
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $admin = $test->makeAdmin(['name' => 'Hassam Owner', 'email' => 'owner@echoledger.example']);
    foreach ([['Bilal Qureshi', 'bilal@qureshi-textiles.example'], ['Sadia Hassan', 'sadia@zamzam-travels.example']] as [$name, $email]) {
        User::factory()->create(['name' => $name, 'email' => $email]);
    }
    Filament::setCurrentPanel('admin');

    return $admin->fresh();
}

dataset('classic admin pages', [
    'dashboard' => ['dashboard', '/admin'],
    'customers' => ['customers', '/admin/customers'],
    'settings' => ['settings', '/admin/settings'],
    'server-usage' => ['server-usage', '/admin/server-usage'],
]);

// Each page is the first request of its test: Livewire adds its styles to the first page a process serves.
it('renders the classic admin page exactly as before the re-theme while ui_v2.admin is off', function (string $name, string $path) {
    $admin = classicAdmin($this);
    $response = $this->actingAs($admin, 'web')->get($path)->assertOk();
    $html = normaliseClassic((string) $response->getContent(), (string) session()->token());
    $file = __DIR__."/golden/admin-{$name}.html";
    if (getenv('UPDATE_GOLDEN') === '1') {
        file_put_contents($file, $html);
    }
    expect(file_exists($file))->toBeTrue("no saved copy of {$name}: run with UPDATE_GOLDEN=1 once")
        ->and($html)->toBe(file_get_contents($file), "the classic admin {$name} page changed");
})->with('classic admin pages');

it('changes only the head under ui_v2.admin: the saved dashboard plus the design values, no layout change', function () {
    $admin = classicAdmin($this);
    app(InterfaceFlags::class)->set('ui_v2.admin', false, 0, [], [$admin->id]);

    $html = (string) $this->actingAs($admin, 'web')->get('/admin')->assertOk()->getContent();
    $head = '/\s*<link rel="stylesheet"[^>]*v2\/admin[^>]*>|<script>\s*\(\(\) => \{\s*const root = document\.documentElement;.*?<\/script>|<style>\s*@font-face \{ font-family: "Noto Nastaliq Urdu".*?<\/style>/s';

    expect($html)->toContain('root.dataset.theme')
        ->and(trim((string) preg_replace('/\n\s*\n/', "\n", normaliseClassic((string) preg_replace($head, '', $html), (string) session()->token()))))
        ->toBe(trim((string) preg_replace('/\n\s*\n/', "\n", (string) file_get_contents(__DIR__.'/golden/admin-dashboard.html'))));
});
