<?php

declare(strict_types=1);

namespace App\Filament\Pages\LiveAssist;

use App\Exceptions\ApiError;
use App\Filament\Concerns\LiveAssistOnly;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Services\Assist\AssistAsk;
use App\Services\Assist\AssistRecords;
use App\Services\SettingsRepository;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One Live Assist session's report (v1.9 L7/L8): the summary, the Q&A table, coaching, mistakes against the facts,
 * promises, action items and the follow-up email, as GET /api/assist/sessions/{id}/report gives them; Download as
 * Markdown, Print (or save as PDF from the browser), Delete. Only the person's own session in this workspace opens;
 * anyone else's id is the same 404 as an unknown one. While the report is being made, the page looks again every
 * few seconds.
 */
class AssistReportPage extends Page
{
    use LiveAssistOnly;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'live-assist/sessions/{session}';

    protected string $view = 'filament.pages.assist-report';

    #[Locked]
    public string $sessionId = '';

    /**
     * This visit's questions and their answers (Ask over the session): shown, never stored.
     *
     * @var list<array{question: string, answer: string, citations: list<array{at_ms: int, channel: string, text: string}>}>
     */
    #[Locked]
    public array $answers = [];

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'live-assist.report';
    }

    public function mount(string $session): void
    {
        $this->sessionId = $this->own($session)->id;
    }

    public function getTitle(): string
    {
        $session = $this->session();
        $prep = $session->prep;

        return $prep instanceof AssistPrep && $prep->title !== null ? $prep->title : ($session->practice ? 'Practice report' : 'Live Assist report');
    }

    public function getBreadcrumbs(): array
    {
        return [AssistSessions::getUrl() => 'Live Assist sessions', 'Report'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ask')->label('Ask')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->visible(fn (): bool => self::canChange() && $this->session()->ended() && $this->session()->turns()->exists())
                ->modalHeading('Ask about this session')
                ->modalDescription('Answered from what was said, its summary and its facts, with the moments it rests on. It counts toward this month\'s allowance.')
                ->schema([Textarea::make('question')->label('Your question')->required()->rows(3)->maxLength((int) config('assist.ask.question_chars', 500))
                    ->placeholder('What did they say about the budget?')])
                ->modalSubmitActionLabel('Ask')
                ->action(function (array $data): void {
                    $key = 'ask:'.self::personId();
                    if (RateLimiter::tooManyAttempts($key, 20)) {
                        Notification::make()->title('Too many questions. Try again in a minute.')->danger()->send();

                        throw new Halt;
                    }
                    RateLimiter::hit($key, 60);
                    try {
                        $answer = app(AssistAsk::class)->ask($this->session(), (string) $data['question']);
                    } catch (ApiError $e) {
                        Notification::make()->title($e->getMessage())->danger()->url(NoticeTargets::forError($e))->send();

                        throw new Halt;
                    }
                    array_unshift($this->answers, ['question' => trim((string) $data['question']), ...$answer]);
                }),
            Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (): bool => $this->session()->report !== null)
                ->action(function (): StreamedResponse {
                    $session = $this->session();
                    $markdown = AssistRecords::markdown(['session_id' => $session->id, ...(array) $session->report], app(SettingsRepository::class)->displayTimezone());

                    return response()->streamDownload(fn () => print ($markdown), 'live-assist-report-'.$session->started_at->format('Y-m-d').'.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
                }),
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->visible(fn (): bool => $this->session()->report !== null)
                ->alpineClickHandler('window.print()'),
            Action::make('delete')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn (): bool => self::canChange())
                ->requiresConfirmation()->modalHeading('Delete this session?')
                ->modalDescription('Its words, suggestions and report go now. The time it used still counts this month.')
                ->action(function (): void {
                    AssistRecords::eraseSession($this->session());
                    Notification::make()->title('Session deleted')->success()->send();
                    $this->redirect(AssistSessions::getUrl());
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $session = $this->session();
        $prep = $session->prep;

        return [
            'session' => $session,
            'report' => $session->report,
            'making' => $session->ended() && $session->report_status === 'queued',
            'meetingType' => AssistRecords::MEETING_TYPES[$prep instanceof AssistPrep ? $prep->meeting_type : 'general'],
            'tz' => app(SettingsRepository::class)->displayTimezone(),
            'answers' => $this->answers,
        ];
    }

    public function session(): AssistSession
    {
        return $this->own($this->sessionId);
    }

    private function own(string $id): AssistSession
    {
        return AssistSession::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', self::personId())->whereKey($id)->firstOrFail();
    }
}
