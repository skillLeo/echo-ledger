<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use App\Services\InterfaceFlags;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * The v2.0 website (ui_v2.website): Home, Pricing from the live plans and Download in the new design, for everyone once
 * the flag says so, and before that for staff only (signed in with the flag on for them, or ?preview=v2). Off, the
 * classic site (SiteClassicGoldenTest) and no /pricing.
 */

function websiteForEveryone(): void
{
    app(InterfaceFlags::class)->set('ui_v2.website', true, 0, [], []);
}

it('shows the new Home, Pricing and Download to everyone once the flag is on for everyone', function () {
    websiteForEveryone();

    $home = (string) $this->get('/')->assertOk()->getContent();
    expect($home)->toContain('s-body')->toContain('Your calls and meetings, written down word for word.')
        ->toContain('class="s-point"')->not->toContain('class="grid"')->not->toContain('gradient')
        ->toContain('href="'.route('site.pricing').'"')->toContain('href="'.route('downloads').'"')->toContain('href="'.route('sign-up').'"')
        ->toContain('href="'.route('legal.terms').'"')->toContain('href="'.route('legal.privacy').'"');

    $this->get('/downloads')->assertOk()->assertSee('Get the apps')->assertSee('How to get started')->assertSee('What works where')
        ->assertSee('No builds are published yet.')->assertSee('s-body', false);
    $this->get('/pricing')->assertOk()->assertSee('Pricing');
});

it('lists the plans on offer, cheapest first, in the console\'s words, and never one off offer or archived', function () {
    websiteForEveryone();
    Plan::query()->updateOrCreate(['key' => 'pro'], ['name' => 'Pro', 'price_label' => 'Rs 2,999 a month', 'on_offer' => true, 'sort_order' => 10,
        'monthly_minutes' => 600, 'features' => ['platform_transcription' => true, 'views' => true, 'screen_moments' => true]]);
    Plan::query()->updateOrCreate(['key' => 'secret'], ['name' => 'Staff only', 'on_offer' => false, 'sort_order' => 20]);
    Plan::query()->updateOrCreate(['key' => 'old'], ['name' => 'Old plan', 'on_offer' => true, 'archived_at' => now(), 'sort_order' => 30]);

    $html = (string) $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain('Free')->toContain('Pro')->toContain('Rs 2,999 a month')->toContain('600 transcription minutes a month')
        ->toContain('Record and upload')->not->toContain('Staff only')->not->toContain('Old plan')
        ->and(strpos($html, '>Free<'))->toBeLessThan(strpos($html, '>Pro<'));
});

it('lets staff preview it before everyone, and nobody else', function () {
    $staff = $this->makeAdmin();
    $person = User::factory()->create();

    $this->get('/?preview=v2')->assertOk()->assertDontSee('s-body', false);
    $this->actingAs($person, 'web')->get('/?preview=v2')->assertOk()->assertDontSee('s-body', false);
    $this->actingAs($person, 'web')->get('/pricing?preview=v2')->assertNotFound();

    $this->actingAs($staff, 'web')->get('/')->assertOk()->assertDontSee('s-body', false);
    $preview = (string) $this->actingAs($staff, 'web')->get('/?preview=v2')->assertOk()->getContent();
    // The preview keeps itself on the site's own links, and is never indexed.
    expect($preview)->toContain('s-body')->toContain(e(route('site.pricing', ['preview' => 'v2'])))->toContain('noindex, nofollow');
    $this->actingAs($staff, 'web')->get('/pricing?preview=v2')->assertOk();

    // The flag on for this member of staff: the new site without the query.
    app(InterfaceFlags::class)->set('ui_v2.website', false, 0, [], [$staff->id]);
    $this->actingAs($staff, 'web')->get('/')->assertOk()->assertSee('s-body', false);
    $this->actingAs($person, 'web')->get('/')->assertOk()->assertDontSee('s-body', false);
});
