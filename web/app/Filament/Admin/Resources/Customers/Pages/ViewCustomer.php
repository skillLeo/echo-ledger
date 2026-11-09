<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Admin\Support\OverridesForm;
use App\Filament\Admin\Widgets\CustomerUsageChart;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Models\AdminNote;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\Entitlements\Entitlements;
use App\Services\Staff\Customers;
use App\Services\Staff\StaffAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One customer on the admin panel (since v1.6, P2.2): this month's usage with a chart, devices (Revoke), workspaces, the
 * plan with the person's own features and limits and the plan's end, staff notes, what the audit log says about them,
 * and the support actions. Recordings and transcripts are never shown, unless the person turned on Support access in
 * their profile: then, until its end, staff may open them and every look is in the audit log.
 *
 * @property User $record
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        return $this->customer()->name;
    }

    public function getSubheading(): string
    {
        $user = $this->customer();

        return $user->email.' · '.Customers::status($user).' · signed up '.$user->created_at?->format('M j, Y');
    }

    protected function getHeaderWidgets(): array
    {
        return [CustomerUsageChart::make(['userId' => $this->customer()->id])];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.customer')->viewData(fn (): array => $this->viewData()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $manage = fn (): bool => StaffAccess::can('customers.manage');
        $support = fn (): bool => StaffAccess::can('customers.support');
        $notStaff = fn (): bool => ! $this->customer()->is_super_admin && ! StaffAccess::isStaff($this->customer());

        return [
            Action::make('changePlan')->label('Change plan')->icon(Heroicon::OutlinedArrowsRightLeft)
                ->authorize($manage)
                ->modalDescription('Their own workspace goes on the plan at once. With an end date it goes back to the default plan then.')
                ->fillForm(fn (): array => ['plan_id' => app(Customers::class)->plan($this->customer())->id, 'until' => $this->homeWorkspace()?->plan_expires_at])
                ->schema([
                    Select::make('plan_id')->label('Plan')->required()->selectablePlaceholder(false)
                        ->options(fn (): array => Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all()),
                    DateTimePicker::make('until')->label('Until (optional)')->seconds(false),
                ])
                ->action(function (array $data): void {
                    $plan = Plan::query()->findOrFail((int) $data['plan_id']);
                    $until = filled($data['until'] ?? null) ? Carbon::parse((string) $data['until']) : null;
                    $done = app(Customers::class)->changePlan($this->customer(), $plan, $until, $this->staff());
                    Notification::make()->title($done ? "On {$plan->name} now" : 'This person has no workspace of their own')->{$done ? 'success' : 'danger'}()->send();
                }),
            Action::make('overrides')->label('Features and limits')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
                ->authorize($manage)
                ->modalDescription('For this person only, on top of their workspace\'s plan. "The plan\'s" keeps what the plan says.')
                ->fillForm(fn (): array => OverridesForm::toForm($this->customer()))
                ->schema(fn (): array => OverridesForm::fields())
                ->action(function (array $data): void {
                    $user = $this->customer();
                    $user->forceFill(['entitlement_overrides' => OverridesForm::fromForm($data)])->save();
                    app(Entitlements::class)->forget();
                    app(AuditLog::class)->record('entitlements_changed', $this->staff()->id, $user, 'Features and limits changed for '.Str::limit($user->email, 120, ''));
                    Notification::make()->title('Saved')->success()->send();
                }),
            Action::make('addNote')->label('Add note')->icon(Heroicon::OutlinedChatBubbleBottomCenterText)->color('gray')
                ->authorize($manage)
                ->modalDescription('For staff only; the person never sees it.')
                ->schema([Textarea::make('body')->label('Note')->required()->maxLength(5000)->rows(4)])
                ->action(function (array $data): void {
                    app(Customers::class)->addNote($this->customer(), $this->staff(), (string) $data['body']);
                    Notification::make()->title('Note added')->success()->send();
                }),
            ActionGroup::make([
                Action::make('resendVerification')->label('Send the confirmation email again')->icon(Heroicon::OutlinedEnvelope)
                    ->authorize($support)
                    ->visible(fn (): bool => $this->customer()->email_verified_at === null)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(Customers::class)->resendVerification($this->customer(), $this->staff());
                        Notification::make()->title('Sent')->success()->send();
                    }),
                Action::make('resetTwoFactor')->label('Reset two-step sign-in')->icon(Heroicon::OutlinedKey)
                    ->authorize($support)
                    ->visible(fn (): bool => $this->customer()->hasTwoFactor() && $notStaff())
                    ->requiresConfirmation()
                    ->modalDescription('For someone who lost their authenticator and recovery codes: they sign in with their password alone and can set it up again. Make sure it is really them first.')
                    ->action(function (): void {
                        app(Customers::class)->resetTwoFactor($this->customer(), $this->staff());
                        Notification::make()->title('Two-step sign-in is off for them now')->success()->send();
                    }),
                Action::make('signOutEverywhere')->label('Sign out every device')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->authorize($support)
                    ->requiresConfirmation()
                    ->modalDescription('Every phone, computer, browser and connected app of theirs is signed out. They can sign in again.')
                    ->action(function (): void {
                        $count = app(Customers::class)->signOutEverywhere($this->customer(), $this->staff());
                        Notification::make()->title($count === 1 ? '1 device signed out' : "{$count} devices signed out")->success()->send();
                    }),
                Action::make('suspend')->label(fn (): string => $this->customer()->isSuspended() ? 'Restore' : 'Suspend')
                    ->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->authorize($manage)
                    ->visible($notStaff)
                    ->modalDescription(fn (): string => $this->customer()->isSuspended()
                        ? 'They can sign in again and their devices work again.'
                        : 'They cannot sign in anywhere, and their phones and computers are refused on every call until you restore them.')
                    ->schema(fn (): array => $this->customer()->isSuspended() ? [] : [TextInput::make('reason')->label('Why (for you)')->maxLength(500)])
                    ->action(function (array $data): void {
                        $on = ! $this->customer()->isSuspended();
                        app(Customers::class)->suspend($this->customer(), $on, $data['reason'] ?? null, $this->staff());
                        Notification::make()->title($on ? 'Suspended' : 'Restored')->success()->send();
                    }),
                Action::make('delete')->label('Delete account')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->authorize(fn (): bool => StaffAccess::can('customers.delete'))
                    ->visible(fn (): bool => $notStaff() && ! $this->customer()->isBeingDeleted())
                    ->requiresConfirmation()
                    ->modalHeading('Delete this account?')
                    ->modalDescription('As if they asked for it: they are signed out everywhere, get an email with the date, and everything only they own is erased after 7 days. Signing in before then and choosing "Keep my account" cancels it.')
                    ->action(function (): void {
                        try {
                            $when = app(Customers::class)->delete($this->customer(), $this->staff());
                            Notification::make()->title('Erased on '.$when->format('M j, Y'))->success()->send();
                        } catch (ApiError $e) {
                            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();
                        }
                    }),
            ])->label('Support')->icon(Heroicon::OutlinedLifebuoy)->button()->color('gray'),
        ];
    }

    /** Revoke one of their devices (from the devices list): it stops working at once and its row stays. */
    public function revokeDeviceAction(): Action
    {
        return Action::make('revokeDevice')
            ->authorize(fn (): bool => StaffAccess::can('customers.support'))
            ->requiresConfirmation()->color('danger')
            ->modalHeading('Revoke this device?')
            ->modalDescription('It stops working at once and asks to sign in again. Its past recordings stay.')
            ->modalSubmitActionLabel('Revoke')
            ->action(function (array $arguments): void {
                $device = Device::query()->withoutGlobalScopes()->where('user_id', $this->customer()->id)->whereKey((string) ($arguments['device'] ?? ''))->firstOrFail();
                if ($device->signed_out_at === null) {
                    $device->signOut('admin', $this->staff()->id);
                }
                Notification::make()->title('Revoked: '.$device->readableName())->success()->send();
            });
    }

    /**
     * Opens one recording's transcript while the person lets support look (Profile → Support access). Every look is in
     * the audit log, theirs to see too.
     */
    public function openRecordingAction(): Action
    {
        return Action::make('openRecording')
            ->authorize(fn (): bool => StaffAccess::can('customers.support') && Customers::supportAccess($this->customer()))
            ->modalHeading(fn (array $arguments): string => $this->supportSession($arguments)->displayTitle())
            ->mountUsing(function (array $arguments): void {
                $session = $this->supportSession($arguments);
                app(AuditLog::class)->record('support_content_viewed', $this->staff()->id, $session, 'Support opened "'.Str::limit($session->displayTitle(), 80, '…').'" of '.Str::limit($this->customer()->email, 100, ''), ['customer_id' => $this->customer()->id]);
            })
            ->modalContent(function (array $arguments): HtmlString {
                $session = $this->supportSession($arguments);
                $text = (string) (Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Final)
                    ->where('status', TranscriptStatus::Completed)->latest('created_at')->value('text') ?? '');

                return new HtmlString('<div style="white-space:pre-wrap;max-height:60vh;overflow:auto" dir="auto">'.e($text !== '' ? $text : 'No transcript yet.').'</div>');
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /** @param  array<string, mixed>  $arguments */
    private function supportSession(array $arguments): CaptureSession
    {
        abort_unless(StaffAccess::can('customers.support') && Customers::supportAccess($this->customer()), 403);

        return CaptureSession::acrossPeople()->withoutGlobalScope(WorkspaceScope::class)
            ->where('user_id', $this->customer()->id)->whereKey((string) ($arguments['session'] ?? ''))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $user = $this->customer();
        $customers = app(Customers::class);
        $memberships = DB::table('workspace_user')->where('user_id', $user->id)->get(['workspace_id', 'role'])->keyBy('workspace_id');
        $support = Customers::supportAccess($user);

        return [
            'stats' => $customers->stats($user),
            'plan' => $customers->plan($user),
            'planUntil' => $this->homeWorkspace()?->plan_expires_at,
            'overrides' => OverridesForm::summary($user),
            'devices' => Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderByDesc('last_seen_at')->limit(50)->get(),
            'workspaces' => Workspace::query()->with('plan')->withCount('members')->whereIn('id', $memberships->keys()->all())->orderBy('id')->get()
                ->map(fn (Workspace $w): array => ['name' => $w->name, 'role' => ucfirst((string) $memberships[$w->id]->role), 'plan' => $w->plan->name, 'members' => (int) $w->getAttribute('members_count')]),
            'notes' => AdminNote::query()->with('author:id,name')->where('user_id', $user->id)->latest()->limit(50)->get(),
            'audit' => AuditEvent::query()->where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($r) => $r->where('subject_type', 'user')->where('subject_id', (string) $user->id)))
                ->latest('id')->limit(30)->get(),
            'support' => $support,
            'supportUntil' => $user->support_access_until,
            'recordings' => $support && StaffAccess::can('customers.support')
                ? CaptureSession::acrossPeople()->withoutGlobalScope(WorkspaceScope::class)->where('user_id', $user->id)->latest('started_at')->limit(30)->get()
                : collect(),
            'canSupport' => StaffAccess::can('customers.support'),
        ];
    }

    private function customer(): User
    {
        /** @var User $user */
        $user = $this->getRecord();

        return $user;
    }

    private function homeWorkspace(): ?Workspace
    {
        $id = app(Customers::class)->homeWorkspaceId($this->customer());

        return $id === null ? null : Workspace::query()->find($id);
    }

    private function staff(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
