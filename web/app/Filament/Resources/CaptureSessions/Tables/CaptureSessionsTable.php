<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Tables;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\FailedUpload;
use App\Filament\Support\LibraryView;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\Shell;
use App\Filament\Support\WhatsAppActions;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\FinalTranscriptCoordinator;
use App\Services\LibraryOrganiser;
use App\Services\SettingsRepository;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\ConsoleAccess;
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
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Size;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
            // Since v1.5 (Phase 1C): Group by and Sort by from the Library's toolbar menus, remembered per person and view.
            ->defaultSort(fn (Builder $query, HasTable $livewire): Builder => LibraryView::applySort(
                $query,
                property_exists($livewire, 'librarySort') ? (string) $livewire->librarySort : LibraryView::DEFAULT_SORT,
                CurrentWorkspace::userId(),
            ))
            ->groups(LibraryView::groups())
            ->groupingSettingsHidden()
            ->groupingDirectionSettingHidden()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(ConsoleV2::library() ? ['labels', 'finalTranscript', 'client'] : 'labels'))
            // The phone's list: one row per recording (tile, title, time, a second line, a status only when needed).
            // Since v1.6 (P4.4) the Library's List/Table switch shows a table instead, its columns chosen in Columns.
            // Since v2.0 (ui_v2.library) the rows of the apps' Library: filament.v2.library-row.
            ->columns(self::isTable($table) ? self::tableColumns() : (ConsoleV2::library() ? self::v2Columns() : [
                Split::make([
                    ViewColumn::make('title')->label('Title')->view('filament.library.row')
                        ->searchable(query: fn (Builder $query, string $search): Builder => self::searchTitleOrClient($query, $search)),
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
                TextColumn::make('client.name')->label('Project')->hidden(),
                TextColumn::make('labels.label')->label('Labels')->hidden(),
                TextColumn::make('device.name')->label('Device')->state(fn (CaptureSession $r): string => self::deviceLabel($r))->hidden(),
                TextColumn::make('status')->hidden(),
                TextColumn::make('finalTranscript.status')->label('Transcript')->hidden(),
            ]))
            ->columnManagerTriggerAction(fn (Action $action): Action => $action->label('Columns')->icon(Heroicon::OutlinedViewColumns))
            ->recordClasses(fn (CaptureSession $record, HasTable $livewire): ?string => property_exists($livewire, 'selected') && $livewire->selected === $record->id ? 'el-current' : null)
            ->filters([
                SelectFilter::make('source_device')->label('Source device')->options(SourceDevice::class)->multiple(),
                SelectFilter::make('source_app')->label('Source app')->options(SourceApp::class)->multiple(),
                SelectFilter::make('client')->label('Project')
                    ->relationship('client', 'name', fn (Builder $query): Builder => $query->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId()))
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
            // Since v1.5 members and viewers only open their recordings here (a read-only console).
            ->recordActions(ConsoleAccess::readOnly() ? [ViewAction::make()->label('Open')] : [
                // Since v1.6 (P5.3): in Failed uploads, Retry where something here can fix it, and Delete.
                ...self::failedActions(),
                ActionGroup::make([
                    ViewAction::make()->label('Open'),
                    self::renameAction(),
                    // Since v1.6 (P5.5): the text of the view it opens in, the text and the voice, or a 7-day link.
                    WhatsAppActions::send(),
                    DeleteAction::make()->modalDescription('Deletes the recording and transcript on every device. You can restore it from Recently deleted for '.CaptureSession::binHours().' hours.'),
                ])->label('More')->tooltip('More')->color('gray'),
            ])
            // Since v1.6 (P5.2) the selection bar holds them: "8 selected · Move · Label · Export · Delete · More · Clear".
            ->toolbarActions(ConsoleAccess::readOnly() ? [] : self::bulkBar())
            // Since v1.6 (P5.2): an empty state for each way the list can be empty, with one clear next step.
            ->emptyStateIcon(fn (HasTable $livewire, Table $table): string => self::emptyState($livewire, $table)['icon'])
            ->emptyStateHeading(fn (HasTable $livewire, Table $table): string => self::emptyState($livewire, $table)['heading'])
            ->emptyStateDescription(fn (HasTable $livewire, Table $table): string => self::emptyState($livewire, $table)['description'])
            ->emptyStateActions([
                Action::make('clearSearchAndFilters')->label('Clear search and filters')->color('gray')
                    ->visible(fn (HasTable $livewire, Table $table): bool => self::emptyState($livewire, $table)['kind'] === 'search')
                    // Only the sessions list page shows this table, and its page has the filter form.
                    ->action(function (ListRecords $livewire): void {
                        $livewire->resetTableSearch();
                        $livewire->removeTableFilters();
                    }),
                Action::make('showAll')->label('Show all recordings')->color('gray')
                    ->visible(fn (HasTable $livewire, Table $table): bool => self::emptyState($livewire, $table)['kind'] === 'view')
                    ->action(function (HasTable $livewire): void {
                        if (method_exists($livewire, 'showAllRecordings')) {
                            $livewire->showAllRecordings();
                        }
                    }),
                Action::make('uploadFirst')->label('Upload a voice note')->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn (HasTable $livewire, Table $table): bool => self::emptyState($livewire, $table)['kind'] === 'empty' && ! ConsoleAccess::readOnly())
                    ->alpineClickHandler("\$dispatch('el-upload-pick')"),
                Action::make('pairDevice')->label('Sign in a device')->icon(Heroicon::OutlinedQrCode)->color('gray')
                    ->visible(fn (HasTable $livewire, Table $table): bool => self::emptyState($livewire, $table)['kind'] === 'empty')
                    ->url(fn (): string => DeviceResource::getUrl('index')),
            ]);
    }

    /**
     * The v2.0 Library's list (ui_v2.library): one row per recording as the apps draw it, the source's icon, the
     * title, one status at most, the second line, and the time above the length.
     *
     * @return list<Split>
     */
    public static function v2Columns(): array
    {
        return [
            Split::make([
                ViewColumn::make('title')->label(__('Title'))->view('filament.v2.library-row')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchTitleOrClient($query, $search)),
            ]),
        ];
    }

    /** Whether the Library shows its table rather than its list (the page's List/Table switch). */
    public static function isTable(Table $table): bool
    {
        $livewire = $table->getLivewire();

        return property_exists($livewire, 'libraryLayout') && $livewire->libraryLayout === 'table';
    }

    /**
     * The Library as a table (since v1.6, P4.4): title, app, project, length, date and status, with the device and
     * the labels in Columns. A plain click opens the drawer as in the list.
     *
     * @return list<TextColumn>
     */
    public static function tableColumns(): array
    {
        $zone = fn (): string => app(SettingsRepository::class)->displayTimezone();

        return [
            TextColumn::make('title')->label('Title')->weight('medium')
                ->state(fn (CaptureSession $r): string => $r->displayTitle())
                ->extraAttributes(fn (CaptureSession $r): array => ['data-recording' => $r->id, 'draggable' => ConsoleAccess::readOnly() ? 'false' : 'true', 'class' => 'el-table-title'])
                ->searchable(query: fn (Builder $query, string $search): Builder => self::searchTitleOrClient($query, $search)),
            TextColumn::make('source_app')->label('App')->toggleable(),
            TextColumn::make('client.name')->label('Project')->placeholder('Inbox')->toggleable(),
            TextColumn::make('device.name')->label('Device')->state(fn (CaptureSession $r): string => self::deviceLabel($r))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('duration_ms')->label('Length')->formatStateUsing(fn (?int $state): string => self::duration($state))->toggleable(),
            TextColumn::make('started_at')->label('Date')->toggleable()
                ->formatStateUsing(fn (CaptureSession $r): string => $r->started_at->copy()->setTimezone($zone())->format('M j, Y · '.Shell::timeFormat())),
            // A status only when it needs the person, as in the list: never "Completed".
            TextColumn::make('status')->label('Status')->badge()->toggleable()
                ->state(fn (CaptureSession $r): ?SessionStatus => $r->status === SessionStatus::Completed ? null : $r->status),
            TextColumn::make('labels.label')->label('Labels')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * The Failed uploads view's buttons on each row (since v1.6, P5.3): Retry transcribes the recording again (its audio
     * is on the server) or opens the file picker for an upload from this browser; Delete puts it in Recently deleted.
     *
     * @return list<Action>
     */
    public static function failedActions(): array
    {
        $failedView = fn (HasTable $livewire): bool => property_exists($livewire, 'activeTab') && (string) $livewire->activeTab === 'failed';
        $retry = fn (CaptureSession $record): ?string => FailedUpload::for($record)['retry'] ?? null;

        return [
            Action::make('retryFailed')->label('Retry')->icon(Heroicon::OutlinedArrowPath)->button()->size(Size::Small)
                ->visible(fn (CaptureSession $record, HasTable $livewire): bool => $failedView($livewire) && $retry($record) === FailedUpload::RETRY_TRANSCRIBE)
                ->rateLimit(10)
                ->action(function (CaptureSession $record, FinalTranscriptCoordinator $coordinator): void {
                    if ($record->primaryRecording()->first()?->isReady() !== true) {
                        Notification::make()->title('The audio is not on the server yet')->warning()->send();

                        return;
                    }
                    $coordinator->start($record, $record->finalTranscript()->first());
                    Notification::make()->title('Transcription queued')->body('It leaves Failed uploads while it is transcribed.')
                        ->url(CaptureSessionResource::getUrl('view', ['record' => $record]))->success()->send();
                }),
            Action::make('retryUpload')->label('Retry')->icon(Heroicon::OutlinedArrowUpTray)->button()->size(Size::Small)
                ->visible(fn (CaptureSession $record, HasTable $livewire): bool => $failedView($livewire) && $retry($record) === FailedUpload::RETRY_UPLOAD)
                ->tooltip('Choose the same file again')
                ->alpineClickHandler("\$dispatch('el-upload-pick')"),
            DeleteAction::make('deleteFailed')->label('Delete')->button()->size(Size::Small)->color('gray')
                ->visible(fn (HasTable $livewire): bool => $failedView($livewire))
                ->modalDescription('Deletes the recording and transcript on every device. You can restore it from Recently deleted for '.CaptureSession::binHours().' hours.'),
        ];
    }

    /**
     * Why the list is empty and what to say (since v1.6, P5.2): a search or filter that matches nothing, a smart view or
     * folder that holds nothing, or a Library with nothing in it yet.
     *
     * @return array{kind: string, icon: string, heading: string, description: string}
     */
    public static function emptyState(HasTable $livewire, Table $table): array
    {
        if ($livewire->hasTableSearch() || $table->isFiltered()) {
            return ['kind' => 'search', 'icon' => 'heroicon-o-magnifying-glass', 'heading' => $livewire->hasTableSearch() ? 'No recordings match your search' : 'No recordings match these filters',
                'description' => 'Try other words, or clear the search and filters.'];
        }
        $view = property_exists($livewire, 'activeTab') ? (string) ($livewire->activeTab ?: 'all') : 'all';
        $folder = property_exists($livewire, 'folder') && is_string($livewire->folder) ? $livewire->folder : null;
        if ($view !== 'all') {
            return ['kind' => 'view', ...match ($view) {
                'today' => ['icon' => 'heroicon-o-calendar', 'heading' => 'Nothing recorded today', 'description' => 'Calls and voice notes from today show here as soon as they are uploaded.'],
                'this_week' => ['icon' => 'heroicon-o-calendar-days', 'heading' => 'Nothing recorded this week', 'description' => 'This week starts on Monday.'],
                'waiting' => ['icon' => 'heroicon-o-clock', 'heading' => 'No calls waiting for a decision', 'description' => 'When the phone or computer asks whether to keep a call, it waits here until you decide.'],
                'bookmarked' => ['icon' => 'heroicon-o-bookmark', 'heading' => 'No bookmarks yet', 'description' => 'Add a bookmark while recording, or on a recording\'s page, to find a moment again.'],
                'failed' => ['icon' => 'heroicon-o-check-circle', 'heading' => 'No failed uploads', 'description' => 'Every recording reached the server and was transcribed.'],
                'pinned' => ['icon' => 'heroicon-o-star', 'heading' => 'Nothing pinned', 'description' => 'Pin a recording with its star to keep it here and at the top of the list.'],
                default => ['icon' => 'heroicon-o-funnel', 'heading' => 'Nothing to show here', 'description' => 'Choose All recordings to see everything.'],
            }];
        }
        if ($folder !== null) {
            $name = ListCaptureSessions::FOLDER_LABELS[$folder] ?? 'this folder';

            return ['kind' => 'view', 'icon' => 'heroicon-o-folder-open', 'heading' => $folder === 'inbox' ? 'The Inbox is empty' : 'No recordings in '.$name,
                'description' => $folder === 'inbox' ? 'Every recording is filed in a project.' : 'Recordings from '.$name.' show here once there are some.'];
        }

        return ['kind' => 'empty', 'icon' => 'heroicon-o-microphone', 'heading' => 'No recordings yet',
            'description' => 'Calls, meetings and voice notes you capture will appear here. Sign in a phone or computer under Devices, or upload a voice note.'];
    }

    /**
     * The selection bar's actions (since v1.6, P5.2), in the order it shows them: Move, Label (add or remove), Export,
     * Delete, and More (pin, unpin, keep forever, stop keeping). The table shows them only while something is selected.
     *
     * @return list<BulkAction|BulkActionGroup>
     */
    public static function bulkBar(): array
    {
        if (ConsoleV2::library()) {
            return self::v2BulkBar();
        }
        $actions = collect(self::bulkActions())->keyBy(fn (BulkAction $action): string => (string) $action->getName());
        $small = fn (BulkAction $action): BulkAction => $action->button()->size(Size::Small);

        return [
            $small($actions['move'])->label('Move')->color('gray'),
            BulkActionGroup::make([$actions['label'], $actions['unlabel']])->label('Label')->icon(Heroicon::OutlinedTag)
                ->button()->size(Size::Small)->color('gray'),
            $small($actions['export'])->label('Export')->color('gray'),
            $small(DeleteBulkAction::make()->authorizeIndividualRecords('delete'))->label('Delete'),
            BulkActionGroup::make([$actions['pin'], $actions['unpin'], $actions['keep'], $actions['unkeep']])->label('More')
                ->icon(Heroicon::OutlinedEllipsisHorizontal)->button()->size(Size::Small)->color('gray'),
        ];
    }

    /**
     * The v2.0 selection bar (docs/PLAN_V2_PARITY.md section 3): four main actions as icon buttons (Move to project,
     * Label, Export, Delete), then ⋮ with every other one in groups: Organise, Export, Danger (Delete last, red).
     * Delete moves to Trash at once and offers Undo, as the apps do (the Trash keeps them for trash.keep_hours).
     *
     * @return list<BulkAction|BulkActionGroup>
     */
    public static function v2BulkBar(): array
    {
        $actions = collect(self::bulkActions())->keyBy(fn (BulkAction $action): string => (string) $action->getName());
        // The ⋮ menu's own copies (an action is drawn once, and the bar's are icon buttons).
        $menu = collect(self::bulkActions())->keyBy(fn (BulkAction $action): string => (string) $action->getName())
            ->map(fn (BulkAction $action): BulkAction => $action->name($action->getName().'InMenu'));
        $icon = fn (BulkAction|BulkActionGroup $action, string $label): BulkAction|BulkActionGroup => $action->label(__($label))->tooltip(__($label))->iconButton()->color('gray');

        return [
            $icon($actions['move'], 'Move to project'),
            $icon(BulkActionGroup::make([$actions['label'], $actions['unlabel']])->icon(Heroicon::OutlinedTag), 'Label'),
            $icon(BulkActionGroup::make(self::exportFormatActions('export'))->icon(Heroicon::OutlinedArrowDownTray), 'Export'),
            $icon(self::trashBulkAction('trash'), 'Delete')->color('danger')->extraAttributes(['data-selection-delete' => true]),
            BulkActionGroup::make([
                BulkActionGroup::make([$menu['pin'], $menu['unpin'], $menu['keep'], $menu['unkeep'], $menu['move'], $menu['label'], $menu['unlabel']])
                    ->label(__('Organise'))->dropdown(false),
                BulkActionGroup::make(self::exportFormatActions('exportInMenu'))->label(__('Export'))->dropdown(false),
                BulkActionGroup::make([self::trashBulkAction('trashFromMenu')->label(__('Delete'))->color('danger')])->label(__('Danger'))->dropdown(false),
            ])->label(__('More'))->tooltip(__('More'))->icon(Heroicon::OutlinedEllipsisVertical)->iconButton()->color('gray')
                ->extraAttributes(['data-selection-more' => true]),
        ];
    }

    /** The v2.0 Export sub-menu's formats (parity plan 3), in its order. */
    public const V2_EXPORT_FORMATS = [
        'docx' => 'Word (.docx)', 'txt' => 'Text (.txt)', 'md' => 'Markdown (.md)', 'srt' => 'Subtitles (.srt)', 'vtt' => 'Subtitles (.vtt)',
        'html' => 'Web page (.html)', 'json' => 'JSON',
    ];

    /**
     * One action per format of the v2.0 Export sub-menu: each asks only which view (Show as) and downloads one zip.
     *
     * @return list<BulkAction>
     */
    public static function exportFormatActions(string $prefix): array
    {
        $out = [];
        foreach (self::V2_EXPORT_FORMATS as $format => $label) {
            $out[] = BulkAction::make($prefix.ucfirst($format))->label(__($label))
                ->modalHeading(__('Export as :format', ['format' => __($label)]))->modalSubmitActionLabel(__('Export'))
                ->schema([
                    Select::make('view')->label(__('Show as'))->placeholder(__('Original words'))->options(TranscriptViews::VIEWS)
                        ->helperText(__('A recording whose view is not made yet keeps its original words.')),
                ])
                ->action(fn (Collection $records, array $data): ?StreamedResponse => self::exportDownload($records, $format, $data['view'] ?? null));
        }

        return $out;
    }

    /** Where the v2.0 selection's last Delete waits for its Undo (one batch, one person). */
    public static function undoKey(string $token): string
    {
        return 'el-library-undo:'.auth()->id().':'.$token;
    }

    /**
     * Delete for the v2.0 selection: the recordings go to Trash at once (each one the person may delete), and the
     * notice offers Undo, which brings them back while the Trash still has them.
     */
    public static function trashBulkAction(string $name): BulkAction
    {
        return BulkAction::make($name)->label(__('Delete'))->icon(Heroicon::OutlinedTrash)
            ->authorizeIndividualRecords('delete')
            ->action(function (Collection $records): void {
                // A recording still being made or uploaded stays where it is: Trash and Undo would race its upload.
                $busy = $records->filter(fn (Model $r): bool => $r instanceof CaptureSession && in_array($r->status, [SessionStatus::Recording, SessionStatus::Uploading], true));
                $ids = [];
                foreach ($records->diff($busy) as $record) {
                    $record->delete();
                    $ids[] = (string) $record->getKey();
                }
                $count = count($ids);
                $notice = Notification::make()->title($count === 1 ? __('1 recording moved to Trash') : __(':count recordings moved to Trash', ['count' => $count]));
                if ($busy->isNotEmpty()) {
                    $notice->body($busy->count() === 1 ? __('1 recording is still recording or uploading and was left alone.')
                        : __(':count recordings are still recording or uploading and were left alone.', ['count' => $busy->count()]));
                }
                if ($count > 0) {
                    // Undo brings back this batch only: the ids wait under a one-off token for the person who deleted them.
                    $token = Str::random(32);
                    Cache::put(self::undoKey($token), $ids, now()->addMinutes(30));
                    $notice->actions([Action::make('undo')->label(__('Undo'))->button()->dispatch('el-library-undo-delete', ['token' => $token])->close()]);
                }
                ($count > 0 ? $notice->success() : $notice->warning())->send();
            })
            ->deselectRecordsAfterCompletion();
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
                Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();
            }
        };

        return [
            BulkAction::make('move')->label('Move to project')->icon(Heroicon::OutlinedFolder)->modalHeading('Move to project')
                ->schema([Select::make('client_id')->label('Project')->placeholder('No project')
                    ->options(fn (): array => Client::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->orderByDesc('pinned')->orderBy('name')->pluck('name', 'id')->all())])
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
            BulkAction::make('export')->label('Export transcripts')->icon(Heroicon::OutlinedArrowDownTray)->modalHeading('Export transcripts')
                ->schema([
                    Select::make('format')->label('Format')->required()->default('docx')->selectablePlaceholder(false)
                        ->options(['docx' => 'Word (.docx)', 'txt' => 'Text (.txt)', 'md' => 'Markdown (.md)', 'srt' => 'Subtitles (.srt)', 'vtt' => 'Web subtitles (.vtt)', 'json' => 'JSON']),
                    Select::make('view')->label('Show as')->placeholder('Original words')
                        ->options(TranscriptViews::VIEWS)
                        ->helperText('A recording whose view is not made yet keeps its original words.'),
                ])
                ->action(fn (Collection $records, array $data): ?StreamedResponse => self::exportDownload($records, (string) $data['format'], $data['view'] ?? null)),
        ];
    }

    /**
     * The finished transcripts of [$records] in one zip, in [$format] and the Show as [$view] (null: original words).
     *
     * @param  Collection<int, CaptureSession>  $records
     */
    public static function exportDownload(Collection $records, string $format, mixed $view): ?StreamedResponse
    {
        $view = is_string($view) && array_key_exists($view, TranscriptViews::VIEWS) ? $view : null;
        try {
            $file = app(LibraryOrganiser::class)->exportZip((int) CurrentWorkspace::id(), self::ids($records), $format, $view);
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();

            return null;
        }

        return response()->streamDownload(function () use ($file): void {
            echo $file['body'];
        }, $file['filename'], ['Content-Type' => 'application/zip']);
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
                Notification::make()->title('Renamed to “'.$record->displayTitle().'”')->url(CaptureSessionResource::getUrl('view', ['record' => $record]))->success()->send();
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

    /** Pending, or its transcript is done and the view it opens in is being made (since v1.5, Phase 1C). */
    public static function isMoving(CaptureSession $session): bool
    {
        if (self::isPending($session)) {
            return true;
        }
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();

        return $final !== null && $final->status === TranscriptStatus::Completed && ($final->meta['views']['status'] ?? null) === 'making'
            && app(TranscriptViews::class)->state($final)['status'] === 'making';
    }

    /** @param  iterable<mixed>|null  $records  the rows the list shows now */
    public static function showsPending(?iterable $records): bool
    {
        foreach ($records ?? [] as $record) {
            if ($record instanceof CaptureSession && self::isMoving($record)) {
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
