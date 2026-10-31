<?php

declare(strict_types=1);

use App\Filament\Support\CommandPalette;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\LocalInterFontProvider;
use App\Filament\Support\Palette;
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

it('uses the phone\'s indigo as the accent and its slate greys, with its red, amber, green and teal', function () {
    $colors = $this->panel->getColors();

    expect($colors)->toHaveKeys(['primary', 'gray', 'danger', 'warning', 'success', 'info']);
    $html = $this->get('/admin/login')->assertOk()->getContent();
    $palettes = ['primary' => Palette::INDIGO, 'gray' => Palette::SLATE, 'danger' => Palette::RED, 'warning' => Palette::AMBER, 'success' => Palette::GREEN, 'info' => Palette::TEAL];
    foreach ($palettes as $name => $palette) {
        expect($html)->toContain("--{$name}-500:".Color::convertToOklch($palette[500]).';');
    }
    // The design system's brand, background and text colours (docs/DESIGN_SYSTEM.md).
    expect(Palette::INDIGO[600])->toBe('#3E63DD')
        ->and(Palette::SLATE[50])->toBe('#F9F9FB')
        ->and(Palette::SLATE[950])->toBe('#111113');
});

it('declares the phone\'s colour tokens for both themes in the console theme', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)->toContain('--bg: #f9f9fb;')->toContain('--accent: #3e63dd;')->toContain('--text: #1c2024;')
        ->toContain('--bg: #111113;')->toContain('--accent-text: #9eb1ff;')
        ->toContain("'Inter'")->not->toContain('#7c3aed');
});

it('uses the Inter font', function () {
    expect($this->panel->getFontFamily())->toBe('Inter');
});

it('has the command palette as global search on Ctrl or Cmd + K and a collapsible sidebar', function () {
    expect($this->panel->getGlobalSearchProvider())->toBeInstanceOf(CommandPalette::class)
        ->and($this->panel->getGlobalSearchKeyBindings())->toBe(['mod+k'])
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
});

it('names no font or avatar service anywhere in the console page (A6.W27)', function () {
    $this->signInAsAdmin(['name' => 'Farah Khan']);

    $html = $this->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('fonts.bunny.net')->not->toContain('ui-avatars.com')->not->toContain('fonts.googleapis.com');
    expect($this->panel->getFontProvider())->toBe(LocalInterFontProvider::class)
        ->and($this->panel->getDefaultAvatarProvider())->toBe(InitialsAvatarProvider::class);
});

it('draws the account avatar locally from the initials', function () {
    $admin = $this->signInAsAdmin(['name' => 'Farah Khan']);

    $url = Filament::getUserAvatarUrl($admin);
    $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true);

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and($svg)->toContain('>FK</text>')
        ->and(InitialsAvatarProvider::initials('farah  ali khan'))->toBe('FK')
        ->and(InitialsAvatarProvider::initials('[SYSTEM] admin'))->toBe('SA')
        ->and(InitialsAvatarProvider::initials('  '))->toBe('');
    expect(base64_decode(substr(InitialsAvatarProvider::dataUri('<script>&'), 26), true))->not->toContain('<script>');
});

it('declares the Inter font from the published files on this server', function () {
    $css = LocalInterFontProvider::css('Inter');

    if (! is_file(public_path(LocalInterFontProvider::PUBLISHED_DIR.'/index.css'))) {
        expect($css)->toBe(''); // assets not published: the theme falls back to the system font stack
    } else {
        preg_match_all('/url\(["\']?([^"\')]+)/', $css, $urls);
        expect($css)->toContain("font-family:'Inter'")->not->toContain('Inter Variable')
            ->and($urls[1])->not->toBeEmpty();
        foreach ($urls[1] as $url) {
            expect($url)->toStartWith(asset(LocalInterFontProvider::PUBLISHED_DIR).'/')->toEndWith('.woff2');
        }
    }
    expect(LocalInterFontProvider::css('Inter;}body{x'))->toBe('');
});
