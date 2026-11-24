<?php

declare(strict_types=1);

use App\Filament\Pages\RequestLinksPage;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Support\SettingsContext;
use App\Models\Device;
use App\Models\RequestLink;
use App\Services\Sharing\RequestLinks;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * W-207: in the new frame the Devices and Request links pages read their title and their header button (with its
 * window) in the console's language, as My links does; English reads as classic, and classic keeps its words.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

/** The page as drawn, in the new frame (English or Urdu) or in classic. */
function titledPage(object $test, string $page, bool $v2, string $language = 'en'): string
{
    webParityDesign($v2);
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($test->owner->fresh(), 'web')->get($test->pages[$page])->assertOk()->getContent();
}

dataset('titled pages', [
    'devices' => ['devices', 'Devices', 'ڈیوائسز', 'Sign in a device', 'ڈیوائس سائن اِن کریں'],
    'request links' => ['request-links', 'Request links', 'درخواست کے لنکس', 'New request link', 'نیا درخواست کا لنک'],
]);

it('titles the page and names its header button in Urdu in the new frame, and in classic\'s English otherwise', function (string $page, string $title, string $urduTitle, string $button, string $urduButton) {
    $heading = fn (string $words): string => '~<h1[^>]*>\s*'.preg_quote($words, '~').'\s*</h1>~u';
    $ur = titledPage($this, $page, true, 'ur');
    expect($ur)->toMatch($heading($urduTitle))->toMatch('~<title>\s*'.preg_quote($urduTitle, '~').'~u')->toContain($urduButton)
        ->not->toMatch($heading($title))->not->toContain('>'.$button.'<');

    foreach ([true, false] as $v2) {
        $en = titledPage($this, $page, $v2);
        expect($en)->toMatch($heading($title))->toContain($button)->not->toContain($urduButton);
    }
})->with('titled pages');

it('words the header button\'s window in Urdu in the new frame, and in classic\'s English in classic', function () {
    $as = function (bool $v2, string $language): void {
        webParityDesign($v2);
        $prefs = is_array($this->owner->console_preferences) ? $this->owner->console_preferences : [];
        $prefs['shell'] = ['app_language' => $language];
        $this->owner->forceFill(['console_preferences' => $prefs])->save();
        SettingsContext::forget();
        app('auth')->forgetGuards();
        $this->actingAs($this->owner->fresh(), 'web');
        app()->setLocale($v2 ? $language : 'en');
    };

    $as(true, 'ur');
    Livewire::test(ListDevices::class)->assertActionExists('pair', fn (Action $a): bool => $a->getModalHeading() === 'فون یا کمپیوٹر سائن اِن کریں'
        && $a->getModalCancelActionLabel() === 'ہو گیا' && str_contains((string) $a->getModalDescription(), 'QR کوڈ اسکین کریں'));
    Livewire::test(RequestLinksPage::class)->assertActionExists('create', fn (Action $a): bool => $a->getModalSubmitActionLabel() === 'لنک بنائیں'
        && str_contains((string) $a->getModalDescription(), 'بغیر اکاؤنٹ کے'));

    $as(false, 'en');
    Livewire::test(ListDevices::class)->assertActionExists('pair', fn (Action $a): bool => $a->getModalHeading() === 'Sign in a phone or computer'
        && $a->getModalCancelActionLabel() === 'Done' && str_starts_with((string) $a->getModalDescription(), 'Scan the QR code in the '));
    Livewire::test(RequestLinksPage::class)->assertActionExists('create', fn (Action $a): bool => $a->getModalSubmitActionLabel() === 'Make the link');
});

/** The mounted action's window, as Filament builds it: its components by name. */
function mountedWindow(mixed $page): array
{
    $schema = (new ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance());

    return collect($schema?->getFlatComponents() ?? [])->filter(fn ($c): bool => method_exists($c, 'getName'))->keyBy(fn ($c): string => $c->getName())->all();
}

it('draws the Sign in a device window from the new frame\'s own copy, in Urdu with its address left to right, and classic from its view', function () {
    $as = function (bool $v2, string $language): void {
        webParityDesign($v2);
        $prefs = is_array($this->owner->console_preferences) ? $this->owner->console_preferences : [];
        $prefs['shell'] = ['app_language' => $language];
        $this->owner->forceFill(['console_preferences' => $prefs])->save();
        SettingsContext::forget();
        app('auth')->forgetGuards();
        $this->actingAs($this->owner->fresh(), 'web');
        app()->setLocale($v2 ? $language : 'en');
    };

    $as(true, 'ur');
    expect(mountedWindow(Livewire::test(ListDevices::class)->mountAction('pair'))['pairing']->getView())->toBe('filament.v2.pair-device');
    $field = new class
    {
        public function __invoke(): array
        {
            return ['code' => 'APG3-Z8CV', 'qr' => 'data:image/png;base64,AA', 'url' => 'https://echoledger.test'];
        }
    };
    $html = view('filament.v2.pair-device', ['getState' => $field])->render();
    expect($html)->toContain('<span dir="auto" x-show="state === \'waiting\'">ایپ کا انتظار ہے…</span>')
        ->toContain('<span dir="auto">سرور: <bdi dir="ltr">https://echoledger.test</bdi></span>')
        ->toContain('<span dir="auto">یہ ایک بار، 10 منٹ کے لیے چلتا ہے۔</span>')
        // The same polling as the original window.
        ->toContain('await $wire.pairingStatus()')->toContain('$wire.unmountAction()')->toContain('}, 2000)')
        ->not->toContain('Waiting for the app')->not->toContain('It works once');

    $as(false, 'en');
    expect(mountedWindow(Livewire::test(ListDevices::class)->mountAction('pair'))['pairing']->getView())->toBe('filament.pair-device');
    expect(view('filament.pair-device', ['getState' => $field])->render())->toContain('Waiting for the app…')->toContain('Server: https://echoledger.test<br>');
});

it('opens a device row\'s Sign in again window from the new frame\'s own copy in the new frame, and classic\'s view in classic (W-208)', function () {
    $device = Device::query()->where('user_id', $this->owner->id)->firstOrFail();
    $device->forceFill(['signed_out_at' => now()])->save();
    $as = function (bool $v2, string $language): void {
        webParityDesign($v2);
        $prefs = is_array($this->owner->console_preferences) ? $this->owner->console_preferences : [];
        $prefs['shell'] = ['app_language' => $language];
        $this->owner->forceFill(['console_preferences' => $prefs])->save();
        SettingsContext::forget();
        app('auth')->forgetGuards();
        $this->actingAs($this->owner->fresh(), 'web');
        app()->setLocale($v2 ? $language : 'en');
    };
    // The table shows signed-in devices by default (its Show filter): Show › Signed out lists this row.
    $window = fn (): array => mountedWindow(Livewire::test(ListDevices::class)->filterTable('shown', 'signed_out')->mountAction(TestAction::make('signInAgain')->table($device->fresh())));

    $as(true, 'ur');
    expect($window()['pairing']->getView())->toBe('filament.v2.pair-device');
    $as(false, 'en');
    expect($window()['pairing']->getView())->toBe('filament.pair-device');
});

it('shows the New request link title\'s placeholder in Urdu in the new frame, and still names an empty title the server\'s way', function () {
    $as = function (bool $v2, string $language): void {
        webParityDesign($v2);
        $prefs = is_array($this->owner->console_preferences) ? $this->owner->console_preferences : [];
        $prefs['shell'] = ['app_language' => $language];
        $this->owner->forceFill(['console_preferences' => $prefs])->save();
        SettingsContext::forget();
        app('auth')->forgetGuards();
        $this->actingAs($this->owner->fresh(), 'web');
        app()->setLocale($v2 ? $language : 'en');
    };

    $as(true, 'ur');
    $page = Livewire::test(RequestLinksPage::class)->mountAction('create');
    expect(mountedWindow($page)['title']->getPlaceholder())->toBe('مجھے اپنی فائلیں بھیجیں');
    // Left empty, the link is still called the server's English default.
    $page->setActionData(['title' => '', 'client_id' => null, 'expires' => '604800', 'max_files' => 20, 'max_mb' => 100])->callMountedAction();
    expect(RequestLink::query()->latest('id')->firstOrFail()->title)->toBe(RequestLinks::DEFAULT_TITLE);

    $as(false, 'en');
    expect(mountedWindow(Livewire::test(RequestLinksPage::class)->mountAction('create'))['title']->getPlaceholder())->toBe('Send me your files');
});

/**
 * A pairing window's hooks, as written in its Blade file: every wire:*, x-*, @* and data-* attribute with its value,
 * and every <script> / @script block. An x-text is read by the Alpine state it shows (its words may differ: the new
 * frame's copy says them in the console's language).
 *
 * @return list<string>
 */
function pairingHooks(string $view): array
{
    $source = (string) file_get_contents(resource_path('views/'.str_replace('.', '/', $view).'.blade.php'));
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    // With a value or without one (wire:ignore, x-cloak); Blade's own directives (@php, @if…) are not hooks.
    preg_match_all('/\s((?:wire:|x-|@|data-)[\w.:-]+)(?:="([^"]*)")?(?=[\s>\/])/s', $source, $attributes, PREG_SET_ORDER);
    $hooks = [];
    foreach ($attributes as $attribute) {
        [$name, $value] = [$attribute[1], $attribute[2] ?? ''];
        if (preg_match('/^@(php|endphp|if|elseif|else|endif|foreach|endforeach|script|endscript)$/', $name) === 1) {
            continue;
        }
        if ($name === 'x-text') {
            preg_match_all('/\b(state|name)\b(?![\'"])/', (string) preg_replace("/'[^']*'/", "''", $value), $reads);
            $value = 'reads: '.implode(',', array_unique($reads[1]));
        }
        $hooks[] = $name.'='.trim((string) preg_replace('/\s+/', ' ', $value));
    }
    preg_match_all('~<script\b.*?</script>|@script.*?@endscript~s', $source, $scripts);
    foreach ($scripts[0] as $script) {
        $hooks[] = 'script='.trim((string) preg_replace('/\s+/', ' ', $script));
    }
    sort($hooks);

    return $hooks;
}

it('keeps the new frame\'s Sign in a device window wired exactly as classic\'s (the same wire:, x-, data- and script hooks)', function () {
    $classic = pairingHooks('filament.pair-device');
    expect($classic)->toContain('x-init=const timer = setInterval(async () => { const answer = await $wire.pairingStatus(); state = answer.state; name = answer.name; if (state === \'signed_in\') { clearInterval(timer); setTimeout(() => $wire.unmountAction(), 2000); } if (state === \'expired\') { clearInterval(timer); } }, 2000)')
        ->toContain('x-text=reads: name')
        ->and(pairingHooks('filament.v2.pair-device'))->toBe($classic);
});
