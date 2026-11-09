<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ApiCredentials;
use App\Filament\Admin\Pages\Plans;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Pages\UpgradeRequestsPage;
use App\Http\Controllers\LegacyConsoleRedirect;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P2.1: the customer app at /app and the staff panel at /admin, with the app's old addresses under /admin sent on.
*/

/** @return list<string> every page of the admin panel a browser can open */
function adminPanelPages(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $r): bool => str_starts_with((string) $r->getName(), 'filament.admin.') && in_array('GET', $r->methods(), true))
        ->map(fn (RouteDefinition $r): string => '/'.ltrim($r->uri(), '/'))
        ->reject(fn (string $uri): bool => str_contains($uri, '{'))
        ->values()->all();
}

it('serves the app at /app and the admin panel at /admin, orange with an ADMIN label', function () {
    expect(Filament::getPanel('app')->getPath())->toBe('app')
        ->and(Filament::getPanel('app')->isDefault())->toBeTrue()
        ->and(Filament::getPanel('admin')->getPath())->toBe('admin')
        ->and(Filament::getPanel('admin')->getColors()['primary'][600])->toBe('#CC4E00')
        ->and(Filament::getPanel('app')->getColors()['primary'][600])->toBe('#3E63DD');

    $this->actingAs(Fx::superAdmin(), 'web');
    $html = (string) $this->get('/admin')->assertOk()->getContent();
    expect($html)->toContain('data-admin-label')->toContain('>ADMIN</span>');
    expect((string) $this->get('/app')->assertOk()->getContent())->not->toContain('data-admin-label');
});

it('has no sign-in of its own: a guest goes to the app\'s sign-in and comes back', function () {
    $this->get('/admin/plans')->assertRedirect('/app/login');
    $admin = Fx::superAdmin(['password' => 'admin-password-123']);
    $this->get('/admin/plans');
    expect(session('url.intended'))->toEndWith('/admin/plans');
    $this->actingAs($admin, 'web')->get('/admin/plans')->assertOk();
});

it('gives someone who is not staff 403 on every admin page, and the bare /admin of before goes to the app', function () {
    $pages = adminPanelPages();
    expect($pages)->toContain('/admin', '/admin/plans', '/admin/settings', '/admin/api-credentials', '/admin/upgrade-requests', '/admin/server-usage');

    foreach ([Fx::user(), withoutWorkspace(Fx::user())] as $customer) {
        $this->actingAs($customer, 'web');
        foreach ($pages as $page) {
            if ($page === '/admin') {
                $this->get($page)->assertRedirect('/app');

                continue;
            }
            expect($this->get($page)->status())->toBe(403, $page);
        }
        auth()->logout();
    }
});

it('refuses a customer the admin pages\' actions too', function () {
    $customer = Fx::user();
    $other = Fx::user();
    $this->actingAs($customer, 'web');
    Filament::setCurrentPanel('admin');

    foreach ([Plans::class, Settings::class, ApiCredentials::class, UpgradeRequestsPage::class] as $page) {
        Livewire::test($page)->assertForbidden();
    }
    expect($other->fresh()->suspended_at)->toBeNull();
});

it('sends the app\'s old addresses under /admin to the same page under /app, with the query', function () {
    $user = Fx::user();
    $session = Fx::session($user);

    $this->get('/admin/login')->assertRedirect(url('/app/login'));
    $this->get('/admin/sessions/'.$session->id.'?tab=notes')->assertRedirect(url('/app/sessions/'.$session->id.'?tab=notes'));
    $this->get('/admin/shared-with-me')->assertRedirect(url('/app/shared-with-me'));
    $this->get('/admin/profile')->assertRedirect(url('/app/profile'));
    // Not an old page of the app: the admin panel's own 404.
    $this->get('/admin/nothing-here')->assertNotFound();

    $this->actingAs($user, 'web')->followingRedirects()->get('/admin/sessions/'.$session->id)->assertOk();
});

it('signs an old signed link again for its new address, so a confirmation sent before the update still works', function () {
    $user = Fx::user();
    $user->forceFill(['email_verified_at' => null])->save();
    $new = Filament::getPanel('app')->getVerifyEmailUrl($user);
    // The same link as the console sent it before v1.6: /admin/…, signed for that address.
    $unsigned = preg_replace('/[?&]signature=[^&]+/', '', str_replace('/app/', '/admin/', $new));
    $old = $unsigned.'&signature='.hash_hmac('sha256', $unsigned, (string) config('app.key'));

    $response = $this->actingAs($user, 'web')->get($old);
    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith(url('/app/email-verification/verify/'))->toContain('signature=');
    $this->get($location);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    // A forged or changed one is passed on as it came and refused there as before.
    $user->forceFill(['email_verified_at' => null])->save();
    $forged = $unsigned.'&signature='.str_repeat('0', 64);
    $location = (string) $this->get($forged)->headers->get('Location');
    expect($location)->toEndWith('signature='.str_repeat('0', 64))->and($this->get($location)->status())->toBe(403)
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('keeps the staff panel\'s names out of the app\'s old addresses', function () {
    $adminSlugs = collect(adminPanelPages())->map(fn (string $p): string => explode('/', trim($p, '/'))[1] ?? '')->filter()->unique();

    expect($adminSlugs->intersect(LegacyConsoleRedirect::PATHS)->all())->toBe([]);
});

it('offers staff the admin panel in the avatar menu and the command palette, and customers neither', function () {
    $customer = Fx::user();
    $this->actingAs($customer, 'web');
    $html = (string) $this->get('/app')->assertOk()->getContent();
    expect($html)->not->toContain('Admin panel')->not->toContain('/admin"');

    $this->actingAs(Fx::superAdmin(), 'web');
    $html = (string) $this->get('/app')->assertOk()->getContent();
    expect($html)->toContain('Admin panel')->toContain(url('/admin'));
    $admin = (string) $this->get('/admin')->assertOk()->getContent();
    expect($admin)->toContain('Back to the app');
});

it('lists no server page in a customer\'s app, and the server pages are not in the app at all', function () {
    $owner = Fx::user();
    $this->actingAs($owner, 'web');
    $html = (string) $this->get('/app')->assertOk()->getContent();
    foreach (['API Credentials', 'Plans', 'Server usage', 'Device reports', 'Accuracy Lab', 'Upgrade requests'] as $label) {
        expect($html)->not->toContain('>'.$label.'<');
    }
    foreach (['/app/settings', '/app/plans', '/app/api-credentials', '/app/upgrade-requests', '/app/server-usage', '/app/device-reports', '/app/accuracy-lab'] as $gone) {
        $this->get($gone)->assertNotFound();
    }
    expect(User::query()->whereKey($owner->id)->value('is_super_admin'))->toBeFalsy();
});
