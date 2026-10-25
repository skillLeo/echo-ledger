<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\LibrarySearch;
use App\Services\SettingsRepository;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Search every recording's title and transcript (v1.1), with filters, and jump to the moment that matched.
 *
 * @property-read Schema $form
 */
class Search extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Search';

    #[Url(as: 'q')]
    public string $query = '';

    /** @var array<string, mixed> */
    public array $filters = [];

    public int $page = 1;

    public function mount(): void
    {
        $this->form->fill(['q' => $this->query]);
    }

    public function form(Schema $schema): Schema
    {
        $workspaceId = (int) CurrentWorkspace::id();

        return $schema->statePath('filters')->components([
            TextInput::make('q')->hiddenLabel()->placeholder('Search titles and transcripts — "quoted words" stay together')
                ->autofocus()->live(debounce: 400)->maxLength(200)->afterStateUpdated(function (?string $state): void {
                    $this->query = (string) $state;
                    $this->page = 1;
                }),
            Grid::make(['default' => 2, 'lg' => 4])->schema([
                Select::make('client_id')->label('Client')->live()->placeholder('Any client')
                    ->options(fn () => Client::query()->where('workspace_id', $workspaceId)->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('source_app')->label('App')->live()->placeholder('Any app')->options(SourceApp::class),
                Select::make('source_device')->label('Device')->live()->placeholder('Any device')->options(SourceDevice::class),
                Select::make('status')->label('Status')->live()->placeholder('Any status')->options(SessionStatus::class),
                DatePicker::make('from')->label('From')->live()->native(false),
                DatePicker::make('to')->label('To')->live()->native(false),
                Select::make('tag')->label('Bookmark tag')->live()->placeholder('Any tag')
                    ->options(fn () => array_combine($tags = app(SettingsRepository::class)->markerTags(), $tags)),
                Toggle::make('bookmarked')->label('Bookmarked only')->live()->inline(false),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            View::make('filament.pages.search-results')->viewData(fn (): array => $this->results()),
        ]);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    /** @return array<string, mixed> */
    public function results(): array
    {
        $state = $this->filters;
        $query = trim((string) ($state['q'] ?? $this->query));
        if (mb_strlen($query) < 2 || LibrarySearch::terms($query) === []) {
            return ['items' => collect(), 'hits' => [], 'more' => false, 'query' => $query, 'page' => $this->page, 'urlFor' => null, 'tz' => 'UTC'];
        }
        $search = app(LibrarySearch::class);
        $result = $search->search((int) CurrentWorkspace::id(), $query, [
            'client_id' => $state['client_id'] ?? null, 'source_app' => self::value($state['source_app'] ?? null),
            'source_device' => self::value($state['source_device'] ?? null), 'status' => self::value($state['status'] ?? null),
            'from' => $state['from'] ?? null, 'to' => $state['to'] ?? null, 'tag' => $state['tag'] ?? null,
            'bookmarked' => (bool) ($state['bookmarked'] ?? false),
        ], 20, $this->page);

        return [
            ...$result,
            'query' => $query,
            'page' => $this->page,
            'urlFor' => fn (CaptureSession $s, ?int $ms = null): string => CaptureSessionResource::getUrl('view', ['record' => $s]).($ms !== null ? '?t='.$ms : ''),
            'tz' => app(SettingsRepository::class)->displayTimezone(),
        ];
    }

    private static function value(mixed $state): ?string
    {
        return $state instanceof BackedEnum ? (string) $state->value : (is_string($state) && $state !== '' ? $state : null);
    }
}
