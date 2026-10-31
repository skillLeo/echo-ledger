<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Tables;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\LibraryOrganiser;
use App\Services\SettingsRepository;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CaptureSessionsTable
{
    /** How often the list and a recording page refresh while something is uploading or being transcribed. */
    public const LIVE_POLL = '5s';

    /** An upload or transcript untouched for this long is not live any more: an abandoned upload must not poll for ever. */
    public const LIVE_HOURS = 6;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('labels'))
            // The phone's list: one row per recording (tile, title, time, a second line, a status only when needed).
            ->columns([
                Split::make([
                    ViewColumn::make('title')->label('Title')->view('filament.library.row')
                        ->searchable(query: fn (Builder $query, string $search): Builder => self::searchTitleOrClient($query, $search)),
                    // Sorting by date needs a visible column; the row already shows the time.
                    ViewColumn::make('started_at')->label('Date')->view('filament.library.sort-anchor')->sortable()->grow(false),
                    IconColumn::make('pinned')->label('Pinned')->boolean()->trueIcon(Heroicon::Star)->falseIcon(Heroicon::OutlinedStar)
                        ->trueColor('warning')->falseColor('gray')->grow(false)
                        ->tooltip(fn (CaptureSession $record): string => $record->pinned ? 'Unpin' : 'Pin to the top')
                        ->extraAttributes(fn (CaptureSession $record): array => ['class' => $record->pinned ? 'el-pin el-on' : 'el-pin'])
                        ->action(fn (CaptureSession $record) => app(LibraryOrganiser::class)->update($record, ['pinned' => ! $record->pinned])),
                ])->extraAttributes(['class' => 'el-split']),
                // What the row already folds into its lines (the tile names the app and device, the status shows
                // only when it needs the person), kept as columns for the state they carry.
                TextColumn::make('source_device')->label('Source')->hidden(),
                TextColumn::make('source_app')->label('App')->hidden(),
                TextColumn::make('client.name')->label('Client')->hidden(),
                TextColumn::make('labels.label')->label('Labels')->hidden(),
                TextColumn::make('device.name')->label('Device')->state(fn (CaptureSession $r): string => self::deviceLabel($r))->hidden(),
                TextColumn::make('status')->hidden(),
                TextColumn::make('finalTranscript.status')->label('Transcript')->hidden(),
            ])
            ->recordClasses(fn (CaptureSession $record, HasTable $livewire): ?string => property_exists($livewire, 'selected') && $livewire->selected === $record->id ? 'el-current' : null)
            ->filters([
                SelectFilter::make('source_device')->label('Source device')->options(SourceDevice::class)->multiple(),
                SelectFilter::make('source_app')->label('Source app')->options(SourceApp::class)->multiple(),
                SelectFilter::make('client')
                    ->relationship('client', 'name', fn (Builder $query): Builder => $query->where('workspace_id', CurrentWorkspace::id()))
                    ->searchable()->preload(),
                SelectFilter::make('status')->options(SessionStatus::class),
                SelectFilter::make('tag')->label('Bookmark tag')
                    ->options(fn (): array => array_combine(app(SettingsRepository::class)->markerTags(), app(SettingsRepository::class)->markerTags()))
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('markers', fn (Builder $m): Builder => $m->where('tag', $data['value']))),
                SelectFilter::make('label')->label('Label')
                    ->options(fn (): array => collect(app(LibraryOrganiser::class)->labels((int) CurrentWorkspace::id()))->pluck('label', 'label')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('labels', fn (Builder $l): Builder => $l->where('label', $data['value']))),
                Filter::make('bookmarked')->label('Has bookmarks')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('markers')),
                Filter::make('started_at')
                    ->schema([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until')])
                    ->query(fn (Builder $query, array $data): Builder => self::filterByDisplayDay($query, $data['from'] ?? null, $data['until'] ?? null)),
            ])
            // The trigger shows the active-filter count as a badge once a filter is on; its accessible name must
            // contain that visible number (WCAG 2.5.3), so the label carries it too. It sits inside the search pill.
            ->filtersTriggerAction(fn (Action $action, Table $table): Action => $action
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->extraAttributes(fn (): array => ['data-active-filters' => (string) $table->getActiveFiltersCount()])
                ->label(fn (): string => 'Filters ('.$table->getActiveFiltersCount().' active)'))
            // Live status: the list refreshes every few seconds while it shows a recording that is uploading or being
            // transcribed, and stops by itself once none is.
            ->poll(fn (HasTable $livewire): ?string => self::showsPending($livewire->getTableRecords()) ? self::LIVE_POLL : null)
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('Open'),
                    self::renameAction(),
                    DeleteAction::make()->modalDescription('Deletes the recording and transcript on every device. You can restore it from Recently deleted for 48 hours.'),
                ])->label('More')->tooltip('More')->color('gray'),
            ])
            ->toolbarActions([BulkActionGroup::make([...self::bulkActions(), DeleteBulkAction::make()->authorizeIndividualRecords('delete')])])
            ->emptyStateIcon(Heroicon::OutlinedPlayCircle)
            ->emptyStateHeading(fn (HasTable $livewire, Table $table): string => match (true) {
                $livewire->hasTableSearch() => 'No recordings match your search',
                $table->isFiltered() => 'No recordings match these filters',
                default => 'No recordings yet',
            })
            ->emptyStateDescription(fn (HasTable $livewire, Table $table): string => $livewire->hasTableSearch() || $table->isFiltered()
                ? 'Try other words, or clear the search and filters.'
                : 'Calls, meetings and voice notes you capture will appear here. Pair a phone or desktop under Devices, or upload a voice note.')
            // C3: one clear next step in the empty state.
            ->emptyStateActions([
                Action::make('clearSearchAndFilters')->label('Clear search and filters')->color('gray')
                    ->visible(fn (HasTable $livewire, Table $table): bool => $livewire->hasTableSearch() || $table->isFiltered())
                    // Only the sessions list page shows this table, and its page has the filter form.
                    ->action(function (ListRecords $livewire): void {
                        $livewire->resetTableSearch();
                        $livewire->removeTableFilters();
                    }),
                Action::make('pairDevice')->label('Pair a device')->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->visible(fn (HasTable $livewire, Table $table): bool => ! $livewire->hasTableSearch() && ! $table->isFiltered())
                    ->url(fn (): string => DeviceResource::getUrl('index')),
            ]);
    }

    /**
     * Bulk actions on the selected recordings (B15): move to a client, add or remove a label, pin, unpin and export
     * the finished transcripts as one zip file.
     *
     * @return list<BulkAction>
     */
    public static function bulkActions(): array
    {
        $run = function (Collection $records, string $action, ?string $client = null, ?string $label = null): void {
            try {
                $changed = app(LibraryOrganiser::class)->bulk((int) CurrentWorkspace::id(), self::ids($records), $action, $client, $label, auth()->user());
                Notification::make()->title($changed === 1 ? '1 recording updated' : "{$changed} recordings updated")->success()->send();
            } catch (ApiError $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };

        return [
            BulkAction::make('move')->label('Move to client')->icon(Heroicon::OutlinedFolder)
                ->schema([Select::make('client_id')->label('Client')->placeholder('No client')
                    ->options(fn (): array => Client::query()->where('workspace_id', CurrentWorkspace::id())->orderByDesc('pinned')->orderBy('name')->pluck('name', 'id')->all())])
                ->action(fn (Collection $records, array $data) => $run($records, 'move', $data['client_id'] ?? null))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('label')->label('Add label')->icon(Heroicon::OutlinedTag)
                ->schema([TextInput::make('label')->label('Label')->required()->maxLength(LibraryOrganiser::MAX_LABEL_CHARS)
                    ->datalist(fn (): array => array_column(app(LibraryOrganiser::class)->labels((int) CurrentWorkspace::id()), 'label'))])
                ->action(fn (Collection $records, array $data) => $run($records, 'label', null, $data['label']))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('unlabel')->label('Remove label')->icon(Heroicon::OutlinedXMark)
                ->schema([Select::make('label')->label('Label')->required()
                    ->options(fn (): array => collect(app(LibraryOrganiser::class)->labels((int) CurrentWorkspace::id()))->pluck('label', 'label')->all())])
                ->action(fn (Collection $records, array $data) => $run($records, 'unlabel', null, $data['label']))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('pin')->label('Pin')->icon(Heroicon::OutlinedStar)
                ->action(fn (Collection $records) => $run($records, 'pin'))->deselectRecordsAfterCompletion(),
            BulkAction::make('unpin')->label('Unpin')->icon(Heroicon::OutlinedMinusCircle)
                ->action(fn (Collection $records) => $run($records, 'unpin'))->deselectRecordsAfterCompletion(),
            BulkAction::make('keep')->label('Keep forever')->icon(Heroicon::OutlinedLockClosed)
                ->action(fn (Collection $records) => $run($records, 'keep'))->deselectRecordsAfterCompletion(),
            BulkAction::make('unkeep')->label('Stop keeping')->icon(Heroicon::OutlinedLockOpen)
                ->action(fn (Collection $records) => $run($records, 'unkeep'))->deselectRecordsAfterCompletion(),
            BulkAction::make('export')->label('Export transcripts')->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([
                    Select::make('format')->label('Format')->required()->default('docx')->selectablePlaceholder(false)
                        ->options(['docx' => 'Word (.docx)', 'txt' => 'Text (.txt)', 'md' => 'Markdown (.md)', 'srt' => 'Subtitles (.srt)', 'vtt' => 'Web subtitles (.vtt)', 'json' => 'JSON']),
                    Select::make('view')->label('Show as')->placeholder('Original words')
                        ->options(TranscriptViews::VIEWS)
                        ->helperText('A recording whose view is not made yet keeps its original words.'),
                ])
                ->action(function (Collection $records, array $data): ?StreamedResponse {
                    $view = is_string($data['view'] ?? null) && array_key_exists($data['view'], TranscriptViews::VIEWS) ? $data['view'] : null;
                    try {
                        $file = app(LibraryOrganiser::class)->exportZip((int) CurrentWorkspace::id(), self::ids($records), (string) $data['format'], $view);
                    } catch (ApiError $e) {
                        Notification::make()->title($e->getMessage())->warning()->send();

                        return null;
                    }

                    return response()->streamDownload(function () use ($file): void {
                        echo $file['body'];
                    }, $file['filename'], ['Content-Type' => 'application/zip']);
                }),
        ];
    }

    /**
     * "Rename" in the list and on the recording page: the same `title` devices change with PATCH /api/sessions/{id};
     * empty clears it, so the automatic title shows again.
     */
    public static function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Rename recording')
            ->modalWidth('lg')
            ->fillForm(fn (CaptureSession $record): array => ['title' => $record->title])
            ->schema([
                TextInput::make('title')->label('Title')->maxLength(200)->autofocus()
                    ->placeholder(fn (CaptureSession $record): string => self::automaticTitle($record))
                    ->helperText('Leave it empty to use the automatic title.'),
            ])
            ->modalSubmitActionLabel('Save')
            ->action(function (CaptureSession $record, array $data): void {
                app(LibraryOrganiser::class)->update($record, ['title' => $data['title'] ?? null]);
                Notification::make()->title('Renamed to “'.$record->displayTitle().'”')->success()->send();
            });
    }

    /** The title the recording shows without one of its own ("WhatsApp call", "Call with Omar Khan"). */
    public static function automaticTitle(CaptureSession $record): string
    {
        $untitled = clone $record;
        $untitled->title = null;

        return $untitled->displayTitle();
    }

    /** Uploading, assembling or being transcribed, and touched recently enough to still be moving. */
    public static function isPending(CaptureSession $session): bool
    {
        if ($session->updated_at->lt(now()->subHours(self::LIVE_HOURS))) {
            return false;
        }
        if (in_array($session->status, [SessionStatus::Uploading, SessionStatus::Processing], true)) {
            return true;
        }
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();

        return $final !== null && in_array($final->status, [TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing], true);
    }

    /** @param  iterable<mixed>|null  $records  the rows the list shows now */
    public static function showsPending(?iterable $records): bool
    {
        foreach ($records ?? [] as $record) {
            if ($record instanceof CaptureSession && self::isPending($record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, CaptureSession>  $records
     * @return list<string>
     */
    private static function ids(Collection $records): array
    {
        return $records->map(fn (CaptureSession $session): string => $session->id)->values()->all();
    }

    /** mm:ss below an hour, then h:mm:ss with the hours counting on past a day (no wrap at 24 h). */
    public static function duration(?int $ms): string
    {
        if ($ms === null) {
            return '';
        }
        $seconds = intdiv(max(0, $ms), 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $hours > 0 ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest) : sprintf('%02d:%02d', $minutes, $rest);
    }

    /** The device name, or where the session came from when there is no device (web upload or a revoked device). */
    public static function deviceLabel(CaptureSession $session): string
    {
        if ($session->device !== null) {
            return $session->device->name;
        }

        return $session->source_device === SourceDevice::Web ? 'Web upload' : (string) $session->source_device->getLabel();
    }

    /**
     * The Library's search: the title as the row shows it, or the client the recording is filed under.
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    public static function searchTitleOrClient(Builder $query, string $search): Builder
    {
        $pattern = '%'.strtr(mb_strtolower(trim($search)), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return $query->where(fn (Builder $q): Builder => self::searchDisplayedTitle($q, $search)
            ->orWhereHas('client', fn (Builder $c): Builder => $c->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])));
    }

    /**
     * Search the title as the list shows it: the stored title, or for untitled sessions the generated one
     * ("WhatsApp call", "Voice note", ...). Wildcards in the search are matched literally.
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    public static function searchDisplayedTitle(Builder $query, string $search): Builder
    {
        $needle = mb_strtolower(trim($search));
        $title = $query->qualifyColumn('title');
        $pattern = '%'.strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $apps = array_values(array_map(
            fn (SourceApp $app): string => $app->value,
            array_filter(SourceApp::cases(), fn (SourceApp $app): bool => str_contains(mb_strtolower(CaptureSession::fallbackTitleFor($app)), $needle)),
        ));

        return $query->where(function (Builder $query) use ($title, $pattern, $apps): void {
            $query->whereRaw("LOWER({$title}) LIKE ? ESCAPE '!'", [$pattern]);
            if ($apps !== []) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->whereNull($title)->orWhere($title, ''))
                    ->whereIn($q->qualifyColumn('source_app'), $apps));
            }
        });
    }

    /**
     * Date filter by calendar days in the console's time zone (the zone the list shows times in).
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    public static function filterByDisplayDay(Builder $query, mixed $from, mixed $until): Builder
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $column = $query->qualifyColumn('started_at');

        if (is_string($from) && $from !== '') {
            $query->where($column, '>=', Carbon::parse($from, $zone)->startOfDay()->utc());
        }
        if (is_string($until) && $until !== '') {
            $query->where($column, '<', Carbon::parse($until, $zone)->startOfDay()->addDay()->utc());
        }

        return $query;
    }
}
