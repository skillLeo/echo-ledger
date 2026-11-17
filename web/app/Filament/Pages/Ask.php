<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\ApiError;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\PlanLock;
use App\Models\Client;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

/**
 * Ask (v2.0, docs/v2/IA.md 4): the same Ask as the phone and computer apps, over one project's brief, memory, files
 * and transcripts (POST /api/clients/{id}/ask), with the recording moment or file page each answer comes from. The
 * server answers per project, so "All recordings" is not offered yet. Only under ui_v2.shell; the classic console
 * asks on a project's own page.
 */
class Ask extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'ask';

    protected string $view = 'filament.v2.ask';

    #[Url(as: 'project')]
    public string $project = '';

    public string $question = '';

    public string $askLanguage = 'en';

    /** @var array{answer: string, citations: list<array<string, mixed>>}|null */
    public ?array $answer = null;

    public ?string $askError = null;

    public static function canAccess(): bool
    {
        return ConsoleV2::shell() && ClientResource::canAccess();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Ask');
    }

    public function mount(): void
    {
        if ($this->project === '' || ! $this->projects()->contains('id', $this->project)) {
            $this->project = (string) ($this->projects()->first()->id ?? '');
        }
    }

    /** @return Collection<int, Client> the person's projects in this workspace, by name */
    public function projects(): Collection
    {
        return $this->ownProjects()->orderBy('name')->limit(500)->get(['id', 'name']);
    }

    /** @return Builder<Client> */
    private function ownProjects(): Builder
    {
        return Client::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId());
    }

    public function ask(): void
    {
        if (! PlanLock::allows('ai_notes')) {
            $this->mountAction('upgradeAsk');

            return;
        }
        $this->validate([
            'project' => ['required', 'string'],
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'askLanguage' => ['required', 'in:en,roman_urdu'],
        ], [], ['question' => __('question'), 'project' => __('project')]);
        $client = $this->ownProjects()->find($this->project);
        if (! $client instanceof Client) {
            $this->addError('project', __('Pick one of your projects.'));

            return;
        }
        $this->askError = null;
        try {
            $this->answer = app(ClientWorkspace::class)->ask($client, trim($this->question), $this->askLanguage);
        } catch (ApiError $e) {
            $this->answer = null;
            $this->askError = $e->getMessage();
        }
    }

    public function updatedProject(): void
    {
        $this->answer = null;
        $this->askError = null;
    }

    /** The upgrade sheet when the plan leaves AI notes (and so Ask) out. */
    public function upgradeAskAction(): Action
    {
        return PlanLock::sheet('upgradeAsk', 'ai_notes', 'Ask');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(ClientWorkspace::class);

        return [
            'projects' => $this->projects(),
            'configured' => $service->configured(),
            'locked' => ! PlanLock::allows('ai_notes'),
            'badge' => PlanLock::badge('ai_notes'),
        ];
    }
}
