<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Tables;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\LibraryOrganiser;
use App\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
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
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('When')->dateTime('M j, Y · g:i A')->sortable()
                    ->description(fn (CaptureSession $r): string => self::duration($r->duration_ms)),
                TextColumn::make('source_device')->label('Source')->badge(),
                TextColumn::make('source_app')->label('App')->badge(),
                TextColumn::make('title')->label('Title')
                    ->state(fn (CaptureSession $r): string => $r->displayTitle())->limit(48)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchDisplayedTitle($query, $search)),
                TextColumn::make('client.name')->label('Client')->placeholder('—')->searchable(),
                TextColumn::make('labels.label')->label('Labels')->badge()->color('gray')->placeholder('')->toggleable(),
                TextColumn::make('device.name')->label('Device')
                    ->state(fn (CaptureSession $r): string => self::deviceLabel($r))->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('finalTranscript.status')->label('Transcript')->badge()->placeholder('—')->toggleable(),
                IconColumn::make('pinned')->label('Pinned')->boolean()->trueIcon(Heroicon::Star)->falseIcon(Heroicon::OutlinedStar)
                    ->trueColor('warning')->falseColor('gray')
                    ->action(fn (CaptureSession $record) => app(LibraryOrganiser::class)->update($record, ['pinned' => ! $record->pinned])),
            ])
            ->filters([
                SelectFilter::make('source_device')->label('Source device')->options(SourceDevice::class)->multiple(),
                SelectFilter::make('source_app')->label('Source app')->options(SourceApp::class)->multiple(),
                SelectFilter::make('client')
                    ->relationship('client', 'name', fn (Builder $query): Builder => $query->where('user_id', auth()->id()))
                    ->searchable()->preload(),
                SelectFilter::make('status')->options(SessionStatus::class),
                SelectFilter::make('tag')->label('Bookmark tag')
                    ->options(fn (): array => array_combine(app(SettingsRepository::class)->markerTags(), app(SettingsRepository::class)->markerTags()))
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('markers', fn (Builder $m): Builder => $m->where('tag', $data['value']))),
                SelectFilter::make('label')->label('Label')
                    ->options(fn (): array => collect(app(LibraryOrganiser::class)->labels((int) auth()->id()))->pluck('label', 'label')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('labels', fn (Builder $l): Builder => $l->where('label', $data['value']))),
                Filter::make('bookmarked')->label('Has bookmarks')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('markers')),
                Filter::make('started_at')
                    ->schema([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until')])
                    ->query(fn (Builder $query, array $data): Builder => self::filterByDisplayDay($query, $data['from'] ?? null, $data['until'] ?? null)),
            ])
            // The trigger always shows the active-filter count as a badge; its accessible name must contain that
            // visible number (WCAG 2.5.3), so the label carries it too.
            ->filtersTriggerAction(fn (Action $action, Table $table): Action => $action
                ->label(fn (): string => 'Filters ('.$table->getActiveFiltersCount().' active)'))
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()->modalDescription('Deletes the recording and transcript everywhere (all devices). This cannot be undone.'),
            ])
            ->toolbarActions([BulkActionGroup::make([...self::bulkActions(), DeleteBulkAction::make()])])
            ->emptyStateHeading(fn (HasTable $livewire, Table $table): string => match (true) {
                $livewire->hasTableSearch() => 'No sessions match your search',
                $table->isFiltered() => 'No sessions match these filters',
                default => 'No sessions yet',
            })
            ->emptyStateDescription(fn (HasTable $livewire, Table $table): string => $livewire->hasTableSearch() || $table->isFiltered()
                ? 'Try other words, or clear the search and filters.'
                : 'Pair a phone or desktop under Devices, or upload a voice note.');
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
                $changed = app(LibraryOrganiser::class)->bulk((int) auth()->id(), self::ids($records), $action, $client, $label);
                Notification::make()->title($changed === 1 ? '1 recording updated' : "{$changed} recordings updated")->success()->send();
            } catch (ApiError $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };

        return [
            BulkAction::make('move')->label('Move to client')->icon(Heroicon::OutlinedFolder)
                ->schema([Select::make('client_id')->label('Client')->placeholder('No client')
                    ->options(fn (): array => Client::query()->where('user_id', auth()->id())->orderByDesc('pinned')->orderBy('name')->pluck('name', 'id')->all())])
                ->action(fn (Collection $records, array $data) => $run($records, 'move', $data['client_id'] ?? null))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('label')->label('Add label')->icon(Heroicon::OutlinedTag)
                ->schema([TextInput::make('label')->label('Label')->required()->maxLength(LibraryOrganiser::MAX_LABEL_CHARS)
                    ->datalist(fn (): array => array_column(app(LibraryOrganiser::class)->labels((int) auth()->id()), 'label'))])
                ->action(fn (Collection $records, array $data) => $run($records, 'label', null, $data['label']))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('unlabel')->label('Remove label')->icon(Heroicon::OutlinedXMark)
                ->schema([Select::make('label')->label('Label')->required()
                    ->options(fn (): array => collect(app(LibraryOrganiser::class)->labels((int) auth()->id()))->pluck('label', 'label')->all())])
                ->action(fn (Collection $records, array $data) => $run($records, 'unlabel', null, $data['label']))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('pin')->label('Pin')->icon(Heroicon::OutlinedStar)
                ->action(fn (Collection $records) => $run($records, 'pin'))->deselectRecordsAfterCompletion(),
            BulkAction::make('unpin')->label('Unpin')->icon(Heroicon::OutlinedMinusCircle)
                ->action(fn (Collection $records) => $run($records, 'unpin'))->deselectRecordsAfterCompletion(),
            BulkAction::make('export')->label('Export transcripts')->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([Select::make('format')->label('Format')->required()->default('docx')->selectablePlaceholder(false)
                    ->options(['docx' => 'Word (.docx)', 'txt' => 'Text (.txt)', 'md' => 'Markdown (.md)', 'srt' => 'Subtitles (.srt)', 'vtt' => 'Web subtitles (.vtt)', 'json' => 'JSON'])])
                ->action(function (Collection $records, array $data): ?StreamedResponse {
                    try {
                        $file = app(LibraryOrganiser::class)->exportZip((int) auth()->id(), self::ids($records), (string) $data['format']);
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
