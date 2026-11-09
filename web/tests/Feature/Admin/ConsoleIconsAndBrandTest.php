<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Filament\Pages\Activity;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\Search;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Shares;
use App\Filament\Pages\Upload;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\RecordingRow;
use App\Filament\Support\SharingActions;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Services\ActivityFeed;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * docs/DESIGN_SYSTEM.md "Icons" and "Motion", docs/design/brand: the console draws the apps' pictures, outlined at
 * rest and solid when active, one size per place; the brand mark in both themes.
 */

/** The file below web/ as text. */
function consoleFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/** What tells a Heroicon apart in a rendered page: the outline of its first path. */
function iconPath(string $name): string
{
    preg_match('/\bd="([^"]+)"/', svg($name)->contents(), $path);

    return $path[1];
}

/** What an Activity row's tile draws, found by the row's title. */
function activityTile(string $html, string $title): string
{
    return Str::afterLast(Str::before($html, $title), 'el-tile ');
}

/** The blocks of the console's theme whose selector draws an svg: selector => [width, height]. */
function themeIconSizes(): array
{
    preg_match_all('/([^{}]*\bsvg\b[^{}]*)\{([^{}]*)\}/', consoleFile('resources/css/filament/admin/theme.css'), $blocks, PREG_SET_ORDER);
    $sizes = [];
    foreach ($blocks as [, $selector, $rules]) {
        if (preg_match('/\bwidth:\s*([^;]+);/', $rules, $w) === 1 && preg_match('/\bheight:\s*([^;]+);/', $rules, $h) === 1) {
            foreach (array_map('trim', explode(',', $selector)) as $one) {
                $sizes[trim((string) preg_replace('~/\*.*?\*/~s', '', $one))][] = [$w[1], $h[1]];
            }
        }
    }

    return $sizes;
}

describe('the icons map', function () {
    it('draws each thing the apps show with the same picture', function (string $page, string $glyph) {
        expect($page::getNavigationIcon()?->value)->toBe('o-'.$glyph)
            ->and($page::getActiveNavigationIcon()?->value)->toBe($glyph);
    })->with([
        'Library is a film' => [CaptureSessionResource::class, 'film'],
        'Projects is a folder' => [ClientResource::class, 'folder'],
        'Activity is a bell' => [Activity::class, 'bell'],
        'Upload is the tray with an arrow up' => [Upload::class, 'arrow-up-tray'],
        'Search is a magnifier' => [Search::class, 'magnifying-glass'],
        'Shared with me is people' => [SharedWithMe::class, 'users'],
        'Share links are a link' => [Shares::class, 'link'],
        'Request files is the inbox with an arrow down' => [RequestLinksPage::class, 'inbox-arrow-down'],
        'Recently deleted is a bin' => [RecentlyDeleted::class, 'trash'],
        'Dashboard is a house' => [Dashboard::class, 'home'],
    ]);

    it('has every sidebar item outlined at rest and solid when active', function () {
        $checked = 0;
        foreach ([...Filament::getPanel('app')->getPages(), ...Filament::getPanel('app')->getResources(), ...Filament::getPanel('admin')->getPages(), ...Filament::getPanel('admin')->getResources()] as $class) {
            $rest = $class::getNavigationIcon();
            if ($rest === null) {
                continue; // not in the sidebar
            }
            expect($rest)->toBeInstanceOf(Heroicon::class)
                ->and($rest->value)->toStartWith('o-');
            expect($class::getActiveNavigationIcon()?->value)->toBe(Str::after($rest->value, 'o-'), "{$class} has no solid icon when active");
            $checked++;
        }

        expect($checked)->toBeGreaterThanOrEqual(18);
    });

    it('uses one picture for one thing on the actions', function () {
        $none = fn (): null => null;

        expect(SharingActions::share($none, fn (): array => [])->getIcon())->toBe(Heroicon::OutlinedLink)
            ->and(SharingActions::send($none)->getIcon())->toBe(Heroicon::OutlinedPaperAirplane);
        $bulk = collect(CaptureSessionsTable::bulkActions())->keyBy(fn ($action) => $action->getName());
        expect($bulk['export']->getIcon())->toBe(Heroicon::OutlinedArrowDownTray)
            ->and($bulk['move']->getIcon())->toBe(Heroicon::OutlinedFolder)
            ->and(consoleFile('app/Filament/Auth/EditProfile.php'))->not->toContain('OutlinedDocumentArrowDown');
    });

    it('draws the smart folders as the apps do, and each exists outlined and solid', function () {
        expect(array_keys(ListCaptureSessions::FOLDER_ICONS))->toBe(array_keys(ListCaptureSessions::FOLDER_LABELS))
            ->and(ListCaptureSessions::FOLDER_ICONS['inbox'])->toBe('inbox')
            ->and(ListCaptureSessions::FOLDER_ICONS['shared'])->toBe('users')
            ->and(ListCaptureSessions::FOLDER_ICONS['teams'])->toBe('video-camera');

        foreach (ListCaptureSessions::FOLDER_ICONS as $glyph) {
            expect(iconPath('heroicon-o-'.$glyph))->not->toBeEmpty()
                ->and(iconPath('heroicon-s-'.$glyph))->not->toBeEmpty();
        }
    });

    it('keeps a source app on the same picture in the enum and on the tile, as the desktop draws it', function () {
        foreach ([SourceApp::MicrosoftTeams, SourceApp::Zoom, SourceApp::GoogleMeet] as $app) {
            expect($app->getIcon())->toBe(Heroicon::OutlinedVideoCamera)
                ->and(RecordingRow::appIcon($app))->toBe('heroicon-s-video-camera');
        }
        foreach ([SourceApp::Slack, SourceApp::Discord, SourceApp::WhatsApp] as $app) {
            expect($app->getIcon())->toBe(Heroicon::OutlinedChatBubbleLeftRight)
                ->and(RecordingRow::appIcon($app))->toBe('heroicon-s-chat-bubble-left-right');
        }
        expect(SourceApp::WhatsAppWeb->getIcon())->toBe(Heroicon::OutlinedGlobeAlt)
            ->and(RecordingRow::appIcon(SourceApp::WhatsAppWeb))->toBe('heroicon-s-globe-alt');
    });

    it('draws what signed in: a computer for a laptop, a phone for a phone, in the bell and on Activity', function () {
        $admin = $this->signInAsAdmin();
        Device::factory()->desktop()->for($admin)->create(['name' => 'Work laptop']);
        Device::factory()->for($admin)->create(['name' => 'Pixel']);
        $stored = fn (string $name): ?string => $admin->notifications()->get()
            ->first(fn (DatabaseNotification $n): bool => $n->data['title'] === "{$name} signed in")?->data['icon'];

        expect($stored('Work laptop'))->toBe('heroicon-o-computer-desktop')
            ->and($stored('Pixel'))->toBe('heroicon-o-device-phone-mobile');
        $html = $this->panelComponent(Activity::class)->html();

        expect(activityTile($html, 'Work laptop signed in'))->toContain(iconPath('heroicon-o-computer-desktop'))
            ->and(activityTile($html, 'Pixel signed in'))->toContain(iconPath('heroicon-o-device-phone-mobile'));
    });

    it('keeps a phone on a device row from before, and each other kind on its own picture', function () {
        $admin = $this->signInAsAdmin();
        $feed = app(ActivityFeed::class);
        $feed->post($admin, 'device_paired', 'Old row', 'x', null, 'heroicon-o-device-phone-mobile');
        $feed->post($admin, 'device_paired', 'No picture', 'x', null, 'heroicon-o-bell');
        $feed->post($admin, 'transcript_ready', 'Ready', 'x', null, 'heroicon-o-document-text');
        $feed->post($admin, 'transcript_failed', 'Failed', 'x', null, 'heroicon-o-exclamation-triangle');

        $html = $this->panelComponent(Activity::class)->html();

        expect(activityTile($html, 'Old row'))->toContain(iconPath('heroicon-o-device-phone-mobile'))
            ->and(activityTile($html, 'No picture'))->toContain(iconPath('heroicon-o-device-phone-mobile'))
            ->and(activityTile($html, 'Ready'))->toContain(iconPath('heroicon-s-check-circle'))
            ->and(activityTile($html, 'Failed'))->toContain(iconPath('heroicon-o-exclamation-circle'));
    });

    it('draws the Library film and the folder in the sidebar and the bottom bar, solid only where you are', function () {
        $this->signInAsAdmin();
        $html = $this->get(CaptureSessionResource::getUrl('index'))->assertOk()->getContent();

        expect(substr_count($html, iconPath('heroicon-s-film')))->toBe(2) // the sidebar and the bottom bar
            ->and($html)->toContain(iconPath('heroicon-o-folder'))
            ->and($html)->not->toContain(iconPath('heroicon-s-rectangle-stack'));
    });

    it('shows a filed recording under a solid folder and not a person', function () {
        $admin = $this->signInAsAdmin();
        $client = Client::factory()->for($admin)->create(['name' => 'Acme Holdings']);
        $session = CaptureSession::factory()->for($admin)->create(['client_id' => $client->id]);

        $html = $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()->getContent();

        expect($html)->toContain(iconPath('heroicon-s-folder'))
            ->and($html)->not->toContain(iconPath('heroicon-o-user'));
    });

    it('has one icon size per place in the theme', function () {
        $sizes = themeIconSizes();

        expect($sizes)->not->toBeEmpty()->toHaveKey('.el-bottom-nav svg');
        foreach ($sizes as $selector => $declared) {
            expect($declared)->toHaveCount(1, "{$selector} is sized twice")
                ->and($declared[0][0])->toBe($declared[0][1], "{$selector} is not square");
        }
        expect($sizes['.el-bottom-nav svg'][0][0])->toBe('24px')
            ->and($sizes['.el-empty-icon svg'][0][0])->toBe('32px')
            ->and($sizes['.fi-ta-empty-state-icon-bg svg'][0][0])->toBe('32px')
            ->and($sizes)->not->toHaveKey('.el-memory-tools .el-icon-btn svg');
    });

    it('outlines Filament\'s own filter and column buttons at rest', function () {
        $this->signInAsAdmin();

        $html = $this->get(Shares::getUrl())->assertOk()->getContent();

        expect($html)->toContain(iconPath('heroicon-o-funnel'))
            ->and($html)->not->toContain(iconPath('heroicon-s-funnel'))
            ->and($html)->toContain('data-active-filters="0"');
    });
});

describe('the brand', function () {
    it('serves the marks of docs/design/brand unchanged', function () {
        $brand = dirname(base_path()).'/docs/design/brand/';

        expect(file_get_contents(public_path('mark.svg')))->toBe(file_get_contents($brand.'mark.svg'))
            ->and(file_get_contents(public_path('favicon.svg')))->toBe(file_get_contents($brand.'mark-small.svg'))
            ->and(file_exists(public_path('favicon.ico')))->toBeTrue();
    });

    it('draws the mark and the wordmark as the brand page says, in the theme\'s text colour for light and dark', function () {
        $html = view('filament.brand')->render();

        expect($html)->toContain('mark.svg')->not->toContain('favicon.svg')
            ->toContain('EchoLedger')
            ->toContain('font-weight: 600')
            ->toContain('letter-spacing: -0.4px')
            ->toContain('color: var(--text)')
            ->and(Filament::getPanel('app')->getBrandLogoHeight())->toBe('1.5rem');
    });

    it('shows the mark on the sign-in page and in the panel, the favicon on every page', function () {
        $login = $this->get('/app/login')->assertOk()->getContent();
        expect($login)->toContain('mark.svg')->toContain('favicon.svg')->toContain('Sign in')
            ->and(substr_count($login, '>Terms<'))->toBe(1);

        $this->get(route('downloads'))->assertOk()->assertSee('mark.svg')->assertSee('favicon.svg');

        $this->signInAsAdmin();
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('mark.svg')->assertSee('favicon.svg');
    });

    it('gives the public share pages the favicon, the mark and the apps\' colours', function () {
        $layout = consoleFile('resources/views/share/layout.blade.php');

        expect($layout)->toContain("asset('favicon.svg')")->toContain("asset('mark.svg')")
            ->toContain('--brand: #3E63DD')
            ->not->toContain('#6d28d9')->not->toContain('#6366f1');
    });
});

describe('motion', function () {
    it('pops the check on a success message in, and only when motion is welcome', function () {
        $theme = consoleFile('resources/css/filament/admin/theme.css');

        expect($theme)->toMatch('/@media \(prefers-reduced-motion: no-preference\) \{\s*\.fi-no-notification\.fi-status-success \.fi-no-notification-icon \{\s*animation: el-check-pop/')
            ->toContain('@keyframes el-check-pop')
            ->toContain('@media (prefers-reduced-motion: reduce)');

        // The classes the rule hangs on: Filament's success message and its check.
        $html = Notification::make()->title('Saved')->success()->toEmbeddedHtml();
        expect($html)->toContain('fi-status-success')->toContain('fi-no-notification-icon')
            ->toContain(iconPath('heroicon-o-check-circle'));
    });

    it('moves nothing on the site, share, sign-in and legal pages unless motion is welcome', function () {
        $checked = 0;
        foreach (['site', 'share', 'auth', 'legal', 'account'] as $folder) {
            foreach (glob(resource_path("views/{$folder}/*.blade.php")) ?: [] as $file) {
                $css = (string) preg_replace('/@media \(prefers-reduced-motion: no-preference\) \{.*\}\s*$/m', '', (string) file_get_contents($file));
                expect($css)->not->toMatch('/(?<![\w-])(transition|animation)\s*:/');
                $checked++;
            }
        }

        expect($checked)->toBeGreaterThan(10);
    });
});
