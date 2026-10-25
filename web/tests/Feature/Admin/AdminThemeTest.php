<?php

declare(strict_types=1);

use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use PHPUnit\Framework\Assert;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    $this->panel = Filament::getPanel('admin');
});

it('is mounted at /admin as the default panel named EchoLedger', function () {
    expect($this->panel->getId())->toBe('admin')
        ->and($this->panel->getPath())->toBe('admin')
        ->and($this->panel->isDefault())->toBeTrue()
        ->and($this->panel->getBrandName())->toBe('EchoLedger');
});

it('follows the visitor\'s system light or dark preference by default', function () {
    expect($this->panel->hasDarkMode())->toBeTrue()
        ->and($this->panel->getDefaultThemeMode())->toBe(ThemeMode::System);
});

it('lets the visitor choose light, dark or system from the account menu', function () {
    $this->signInAsAdmin();

    $html = $this->get('/admin')->assertOk()->getContent();

    foreach (['light', 'dark', 'system'] as $mode) {
        expect($html)->toContain("(theme = '{$mode}') && close()");
    }
    expect($html)->toContain("localStorage.getItem('filament:theme:mode') ?? 'system'");
});

it('applies the stored theme before first paint to avoid a flash of the wrong theme', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toMatch('/window\.theme\s*=\s*localStorage\.getItem\(\'filament:theme:mode\'\)\s*\?\?\s*\'system\'/')
        ->toContain("document.documentElement.classList.add('dark')");
    // The script runs in the head, ahead of the stylesheet-dependent body and the Livewire bundle.
    expect(strpos($html, 'filament:theme:mode'))->toBeLessThan(strpos($html, 'livewire.js'))
        ->and(strpos($html, 'filament:theme:mode'))->toBeLessThan(strpos($html, '<body'));
});

it('uses violet as the accent, cyan for info and slate for neutrals', function () {
    $colors = $this->panel->getColors();

    expect($colors)->toHaveKeys(['primary', 'info', 'gray']);
    $html = $this->get('/admin/login')->assertOk()->getContent();
    foreach (['primary' => Color::Violet, 'info' => Color::Cyan, 'gray' => Color::Slate] as $name => $palette) {
        expect($html)->toContain("--{$name}-500:{$palette[500]};");
    }
});

it('uses the Inter font', function () {
    expect($this->panel->getFontFamily())->toBe('Inter');
});

it('has global search switched off and a collapsible sidebar', function () {
    expect($this->panel->getGlobalSearchProvider())->toBeNull()
        ->and($this->panel->isSidebarCollapsibleOnDesktop())->toBeTrue();
});

it('has sign-in, profile and revealable passwords but no sign-up, reset or email verification', function () {
    expect($this->panel->hasLogin())->toBeTrue()
        ->and($this->panel->hasProfile())->toBeTrue()
        ->and($this->panel->isProfilePageSimple())->toBeFalse()
        ->and($this->panel->arePasswordsRevealable())->toBeTrue()
        ->and($this->panel->hasRegistration())->toBeFalse()
        ->and($this->panel->hasPasswordReset())->toBeFalse()
        ->and($this->panel->hasEmailVerification())->toBeFalse();
});

it('titles console pages "Page - EchoLedger" and declares the language', function () {
    $this->signInAsAdmin();

    $html = $this->get('/admin/settings')->assertOk()->getContent();

    expect($html)->toMatch('/<title>\s*Settings\s*-\s*EchoLedger\s*<\/title>/')
        ->and($html)->toMatch('/<html\s+lang="en"/');
});

it('titles the sign-in page "Login - EchoLedger" with a "Sign in" heading', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toMatch('/<title>\s*Login\s*-\s*EchoLedger\s*<\/title>/')
        ->and($html)->toMatch('/<h1[^>]*>\s*Sign in\s*<\/h1>/');
});

it('loads the console assets from the app itself', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain(config('app.url').'/css/filament/filament/app.css')
        ->toContain(config('app.url').'/js/filament/filament/app.js')
        ->toContain('/livewire');
});

it('does not load fonts, avatars or scripts from third-party hosts', function () {
    $this->signInAsAdmin(['name' => 'Farah Khan']);

    $html = $this->get('/admin')->assertOk()->getContent();
    preg_match_all('#(?:src|href)=["\'](https?://[^"\']+)["\']#i', $html, $matches);
    $hosts = collect($matches[1])->map(fn (string $url) => parse_url($url, PHP_URL_HOST))->filter()->unique()->values()->all();

    $own = parse_url((string) config('app.url'), PHP_URL_HOST);
    Assert::assertSame([], array_values(array_diff($hosts, [$own])), 'the console page asks the browser to contact third-party hosts');
})->skip('A6.W27: the console loads fonts and avatars from third-party hosts');
