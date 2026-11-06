<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\SourceApp;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\FilingRule;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Callers\CallerMatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Filing rules (docs/API.md "Filing rules", since v1.5). A recording that ends without a project is filed by the first
 * match: the caller is one of a project's contacts (CallerMatcher, as since v1.4), then a `title` rule (its title, or
 * the meeting window's or calendar's name, contains the text, capitals ignored), then an `app` rule. A rule never
 * moves a recording that is already in a project. A kind and value belong to one project of the workspace.
 */
final class FilingRules
{
    /**
     * The value as stored and what is compared, or `422 validation_failed` on `value`.
     *
     * @return array{value: string, match: string}
     */
    public static function normalise(string $kind, string $value): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', '', $value)));
        $invalid = fn (string $message): ApiError => new ApiError(422, 'validation_failed', $message, ['errors' => ['value' => [$message]]]);
        if ($clean === '' || mb_strlen($clean) > 120) {
            throw $invalid('Give a value of at most 120 characters.');
        }

        return match ($kind) {
            'title' => ['value' => $clean, 'match' => mb_strtolower($clean)],
            'app' => SourceApp::tryFrom($clean) !== null
                ? ['value' => $clean, 'match' => $clean]
                : throw $invalid('Choose one of the apps EchoLedger records.'),
            'contact' => self::contactMatch($clean) ?? throw $invalid('Give a phone number or an email address.'),
            default => throw $invalid('Unknown kind of rule.'),
        };
    }

    /** @return array{value: string, match: string}|null */
    private static function contactMatch(string $value): ?array
    {
        if (str_contains($value, '@')) {
            $email = mb_strtolower($value);

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? ['value' => $email, 'match' => 'email:'.$email] : null;
        }
        $digits = preg_match('/^[\d\s+().-]+$/', $value) === 1 ? CallerMatcher::digits($value) : null;

        return $digits !== null ? ['value' => $value, 'match' => 'phone:'.$digits] : null;
    }

    /**
     * Adds a rule to the project. The same kind and value on this project returns the rule it already has
     * (`created` false); on another project of the same person it is `409 rule_exists` naming that project (since v1.5
     * each person's rules are their own, as their projects are). A contact
     * rule also adds the contact (or uses the project's contact with that number or email); only a contact it added
     * is remembered, so removing the rule never deletes a contact the project already had.
     *
     * @return array{rule: FilingRule, created: bool}
     */
    public function add(Client $project, string $kind, string $value): array
    {
        ['value' => $value, 'match' => $match] = self::normalise($kind, $value);

        return DB::transaction(function () use ($project, $kind, $value, $match): array {
            // Adds of one workspace one at a time, so the lookup below sees a rule another request just added.
            ProjectTree::lock($project->workspace_id);
            $existing = $this->existing($project, $kind, $match);
            if ($existing instanceof FilingRule) {
                return self::taken($project, $existing);
            }
            $contact = null;
            if ($kind === 'contact') {
                $contact = $this->contactFor($project, $value, $match);
            }
            try {
                $rule = FilingRule::query()->create([
                    'workspace_id' => $project->workspace_id,
                    'user_id' => $project->user_id,
                    'client_id' => $project->id,
                    'kind' => $kind,
                    'value' => $value,
                    'match_value' => $match,
                    'client_contact_id' => $contact?->wasRecentlyCreated === true ? $contact->id : null,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Taken in the meantime all the same: answered like a request that came second.
                $existing = $this->existing($project, $kind, $match, latest: true);

                return $existing instanceof FilingRule ? self::taken($project, $existing) : throw $e;
            }

            return ['rule' => $rule, 'created' => true];
        });
    }

    /**
     * The person's rule of this kind and value in the workspace; `latest` reads past this transaction's snapshot (a
     * locking read).
     */
    private function existing(Client $project, string $kind, string $match, bool $latest = false): ?FilingRule
    {
        $rule = FilingRule::query()->withoutGlobalScope(WorkspaceScope::class)->with('client')
            ->where('workspace_id', $project->workspace_id)->where('user_id', $project->user_id)->where('kind', $kind)->where('match_value', $match)
            ->when($latest, fn ($q) => $q->lockForUpdate())->first();

        return $rule instanceof FilingRule ? $rule : null;
    }

    /**
     * The project's own rule (`created` false), or `409 rule_exists` naming the project that has it.
     *
     * @return array{rule: FilingRule, created: bool}
     */
    private static function taken(Client $project, FilingRule $existing): array
    {
        if ($existing->client_id === $project->id) {
            return ['rule' => $existing, 'created' => false];
        }

        throw self::exists($existing->client);
    }

    /** The project's contact with this number or email, else a new one; another project's is `409 rule_exists`. */
    private function contactFor(Client $project, string $value, string $match): ClientContact
    {
        $projects = Client::acrossPeople()->where('workspace_id', $project->workspace_id)->where('user_id', $project->user_id)->select('id');
        $email = str_starts_with($match, 'email:') ? substr($match, 6) : null;
        $digits = str_starts_with($match, 'phone:') ? substr($match, 6) : null;
        $same = ClientContact::query()->with('client')->whereIn('client_id', $projects)
            ->where(fn ($q) => $email !== null ? $q->whereRaw('LOWER(email) = ?', [$email]) : $q->where('phone_digits', $digits))
            ->orderByRaw('CASE WHEN client_id = ? THEN 0 ELSE 1 END', [$project->id])->first();
        if ($same instanceof ClientContact) {
            if ($same->client_id !== $project->id && $same->client !== null) {
                throw self::exists($same->client);
            }

            return $same;
        }

        return ClientContact::query()->create([
            'client_id' => $project->id,
            'name' => $value,
            'phone' => $email === null ? $value : null,
            'email' => $email,
        ]);
    }

    private static function exists(?Client $owner): ApiError
    {
        $message = $owner !== null ? "“{$owner->name}” already has this rule." : 'Another project already has this rule.';

        return new ApiError(409, 'rule_exists', $message, ['client_id' => $owner?->id, 'client_name' => $owner?->name]);
    }

    /** Removes a rule; a contact rule takes the contact it added with it (a contact the project already had stays). */
    public function remove(FilingRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $contactId = $rule->kind === 'contact' ? $rule->client_contact_id : null;
            $rule->delete();
            if ($contactId !== null) {
                ClientContact::query()->whereKey($contactId)->first()?->delete();
            }
        });
    }

    /**
     * Removes the contact rules of a project that none of its contacts carries any more (a contact was deleted, or its
     * number or email changed, on the console's Contacts): such a rule would file nothing and still keep the number or
     * email from other projects. Called by ClientContact when one is deleted or changed.
     */
    public static function forgetUncarried(string $projectId): void
    {
        $rules = FilingRule::query()->withoutGlobalScope(WorkspaceScope::class)->where('client_id', $projectId)
            ->where('kind', 'contact')->get(['id', 'match_value']);
        foreach ($rules as $rule) {
            [$type, $value] = explode(':', $rule->match_value, 2) + [1 => ''];
            $carried = ClientContact::query()->where('client_id', $projectId)
                ->where(fn ($q) => $type === 'email' ? $q->whereRaw('LOWER(email) = ?', [$value]) : $q->where('phone_digits', $value))
                ->exists();
            if (! $carried) {
                $rule->delete();
            }
        }
    }

    /**
     * Files a recording that ended without a project by the title and app rules of the person who made it (the contact
     * match already ran when the caller was stored). Returns true when it was filed.
     */
    public function apply(CaptureSession $session): bool
    {
        if ($session->client_id !== null) {
            return false;
        }
        $rules = FilingRule::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $session->workspace_id)->where('user_id', $session->user_id)
            ->whereIn('kind', ['title', 'app'])->orderBy('id')->get(['id', 'client_id', 'kind', 'match_value']);
        if ($rules->isEmpty()) {
            return false;
        }
        $title = mb_strtolower(self::titleOf($session));
        $match = $title === '' ? null : $rules->first(fn (FilingRule $r): bool => $r->kind === 'title' && str_contains($title, $r->match_value));
        $match ??= $rules->first(fn (FilingRule $r): bool => $r->kind === 'app' && $r->match_value === $session->source_app->value);
        if (! $match instanceof FilingRule) {
            return false;
        }
        $session->forceFill(['client_id' => $match->client_id, 'filed_by' => CaptureSession::FILED_BY_RULE])->save();

        return true;
    }

    /** The title a rule reads: the recording's own, else the meeting window's or calendar's name or the caller's. */
    private static function titleOf(CaptureSession $session): string
    {
        $title = trim((string) $session->title);

        return $title !== '' ? $title : trim((string) $session->caller_name);
    }
}
