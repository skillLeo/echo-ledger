<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\FullConsoleOnly;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SharingActions;
use App\Filament\Support\V2Tables;
use App\Models\Client;
use App\Models\RequestLink;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Sharing\RequestLinks;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Request links (v1.5): a page anyone with the link can send files to, without an account. Sound and video become
 * recordings in the chosen project (or the Inbox); with a project, documents and images go to its Files. The address
 * is shown once, when the link is made.
 */
class RequestLinksPage extends Page implements HasTable
{
    use FullConsoleOnly;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::InboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'More';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Request links';

    protected static ?string $slug = 'request-links';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** Since v2.0 (W-207): in the new frame the title is in the console's language; classic keeps its English. */
    public function getTitle(): string|Htmlable
    {
        return ConsoleV2::shell() ? __('Request links') : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        // Since v2.0 (W-207): in the new frame New request link and its form are in the console's language; classic
        // keeps its English words.
        $t = fn (string $words): string => ConsoleV2::shell() ? __($words) : $words;

        return [
            Action::make('create')->label($t('New request link'))->icon(Heroicon::OutlinedPlus)
                ->modalDescription($t('Anyone with the link can send you files on a page of this server, without an account.'))
                ->schema([
                    // Left empty, the server names it RequestLinks::DEFAULT_TITLE: only the placeholder is in the console's language.
                    TextInput::make('title')->label($t('What you ask for'))->placeholder($t(RequestLinks::DEFAULT_TITLE))->maxLength(200),
                    Select::make('client_id')->label($t('Project'))->placeholder(ConsoleV2::library() ? __('No project (sound and video only)') : $t('Inbox (sound and video only)'))->searchable()
                        ->options(fn (): array => SharingActions::folderOptions())
                        ->helperText($t('With a project, PDF, Word, text and image files go to its Files too.')),
                    Select::make('expires')->label($t('Open for'))->default('604800')->selectablePlaceholder(false)
                        ->options(['86400' => $t('1 day'), '604800' => $t('7 days'), '2592000' => $t('30 days'), 'never' => $t('Until I revoke it')]),
                    TextInput::make('max_files')->label($t('Files at most'))->numeric()->integer()->default(20)->minValue(1)->maxValue(RequestLinks::MAX_FILES)->required(),
                    TextInput::make('max_mb')->label($t('Each file at most (MB)'))->numeric()->integer()->default(100)->minValue(1)->maxValue(RequestLinks::maxMegabytes())->required(),
                ])
                ->modalSubmitActionLabel($t('Make the link'))
                ->action(function (array $data) use ($t): void {
                    $user = auth()->user();
                    $workspace = (int) CurrentWorkspace::id();
                    $project = filled($data['client_id'] ?? null) ? Client::query()->where('workspace_id', $workspace)->find($data['client_id']) : null;
                    if (! $user instanceof User || $workspace === CurrentWorkspace::NONE) {
                        return;
                    }
                    ['url' => $url] = app(RequestLinks::class)->create($workspace, $user->id, $project instanceof Client ? $project : null, $data['title'] ?? null,
                        ($data['expires'] ?? 'never') === 'never' ? null : now()->addSeconds((int) $data['expires']), (int) $data['max_files'], (int) $data['max_mb']);
                    Notification::make()->title($t('Request link ready. Copy it now: it is shown only once.'))->body($url)->persistent()->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();
        // W-201: the table fits the page at 1024 px in both designs. The title wraps, Open until has its time on a second
        // line, Made is a wide-only column, and Revoke is in the row's ⋮ menu. The new frame dates by its one date rule,
        // in the console's language (only the new frame has another); classic keeps "Nov 17, 2026".
        $v2 = ConsoleV2::shell();
        // A column that made the table too wide: under Columns in the new frame; in classic only on a wide window (from
        // 1280 px), since classic has no Columns menu (Filament draws its labels with x-html, which classic pages never contain).
        $wide = fn (Column $column): Column => $v2 ? V2Tables::inColumns($column) : $column->visibleFrom('xl');
        $actions = [
            Action::make('revoke')->label(__('Revoke'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (RequestLink $link): bool => $link->isActive())
                ->requiresConfirmation()->modalDescription(__('The page stops taking files at once. What was sent stays in the library.'))
                ->action(fn (RequestLink $link) => app(RequestLinks::class)->revoke($link)),
        ];

        return $table
            ->query(fn (): Builder => RequestLink::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->with('client'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Title'))->limit(60)->searchable()->wrap(),
                TextColumn::make('client.name')->label(__('Goes to'))->placeholder(ConsoleV2::library() ? __('All recordings') : __('Inbox')),
                TextColumn::make('received')->label(__('Received'))
                    ->state(fn (RequestLink $link): string => __(':received of :max', ['received' => $link->received, 'max' => $link->max_files]))
                    ->description(fn (RequestLink $link): string => __('up to :mb MB each', ['mb' => $link->max_mb])),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->state(fn (RequestLink $link): string => $link->revoked_at !== null ? 'Revoked' : ($link->isActive() ? 'Open' : 'Expired'))
                    // "Open" is the action (کھولیں) elsewhere: a link's state has its own word (کھلا), English "Open" as before.
                    ->formatStateUsing(fn (string $state): string => $state === 'Open' ? (trans()->has('Open (state)') ? __('Open (state)') : 'Open') : __($state))
                    ->color(fn (string $state): string => $state === 'Open' ? 'success' : 'gray'),
                TextColumn::make('expires_at')->label(__('Open until'))->dateTime('M j, Y · g:i A', $tz)->placeholder(__('Until revoked'))
                    ->when($v2, fn (TextColumn $column): TextColumn => V2Tables::date($column, $tz), fn (TextColumn $column): TextColumn => $column->date('M j, Y', $tz)
                        ->description(fn (RequestLink $link): ?string => $link->expires_at?->setTimezone($tz)->format('g:i A'))),
                TextColumn::make('created_at')->label(__('Made'))->since()->tap($wide),
            ])
            ->recordActions(V2Tables::actions($actions))
            ->emptyStateIcon(Heroicon::OutlinedInboxArrowDown)
            ->emptyStateHeading(__('No request links yet'))
            ->emptyStateDescription(__('Make one to collect voice notes, recordings or documents from anyone, without an account.'));
    }
}
