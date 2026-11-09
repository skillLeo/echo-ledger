<?php

declare(strict_types=1);

namespace App\Services\Connector;

use App\Models\User;
use App\Services\AuditLog;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Support\ValidationMessages;
use Laravel\Passport\AccessToken;
use Throwable;

/**
 * What every tool call of the connector goes through (since v1.5, docs/API.md "Claude and ChatGPT connector"): 60 calls
 * a minute per person, an account that can still be used, the plan's `connector`, then the tool, and an audit row
 * with the tool, its arguments without content and the size of the answer. Whatever goes wrong comes back as a tool
 * error in plain words; the AI never sees an internal message.
 */
final class ConnectorCalls
{
    public const PER_MINUTE = 60;

    public const NOT_IN_PLAN = 'The connector is not in your plan.';

    private const FAILED = 'Something went wrong reading that. Try again in a moment.';

    /** Arguments that say which thing was asked for; anything else is left out of the audit log. */
    private const LOGGED_ARGUMENTS = ['id', 'project_id', 'parent_id', 'view', 'include_screens', 'limit', 'n'];

    public function __construct(
        private readonly PlanLimits $plans,
        private readonly Workspaces $workspaces,
        private readonly ConnectedApps $apps,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Runs one tool. `$answer` gets the person and their current workspace, and returns the text.
     *
     * @param  Closure(User, int): string  $answer
     */
    public function run(string $tool, Request $request, Closure $answer): Response
    {
        $person = $request->user();
        if (! $person instanceof User) {
            return Response::error('This connection has ended. Add EchoLedger again.');
        }
        $token = $person->currentAccessToken();
        $clientId = $token instanceof AccessToken ? $token->oauth_client_id : null;
        $text = null;
        $failure = null;
        try {
            $workspaceId = $this->admit($person);
            $text = $answer($person, $workspaceId);
        } catch (ToolError $e) {
            $failure = $e->getMessage();
        } catch (ValidationException $e) {
            $failure = ValidationMessages::from($e);
        } catch (Throwable $e) {
            report($e);
            $failure = self::FAILED;
        }
        $this->record($person, $clientId, $tool, $request, $text, $failure);

        return $text !== null ? Response::text($text) : Response::error($failure ?? self::FAILED);
    }

    /** The checks before a tool runs; the person's current workspace when they pass. */
    private function admit(User $person): int
    {
        $key = 'mcp-tools:'.$person->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw new ToolError('Too many requests: at most '.self::PER_MINUTE.' a minute. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }
        RateLimiter::hit($key, 60);
        if ($person->isBeingDeleted()) {
            throw new ToolError('This account is being deleted. Sign in on the console and choose "Keep my account" to use it again.');
        }
        $workspace = $this->workspaces->current($person);
        if ($workspace === null) {
            throw new ToolError('Your account is in no workspace.');
        }
        if (! $this->plans->allows($workspace->id, 'connector', $person->id)) {
            throw new ToolError(self::NOT_IN_PLAN);
        }

        return $workspace->id;
    }

    private function record(User $person, ?string $clientId, string $tool, Request $request, ?string $text, ?string $failure): void
    {
        $name = ConnectedApps::name($clientId);
        if ($clientId !== null) {
            $this->apps->used($person, $clientId);
        }
        $this->audit->record('connector_tool_call', $person->id, null, "{$name} used {$tool}", [
            'tool' => $tool,
            'client' => $name,
            'client_id' => $clientId,
            'arguments' => self::loggedArguments($request->all()),
            'result_bytes' => strlen((string) ($text ?? $failure)),
            'error' => $failure !== null,
        ]);
    }

    /**
     * The arguments without content: which recording or project, how many, which view. A search query is a person's own
     * words, so only its length is kept.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, bool|float|int|string>
     */
    private static function loggedArguments(array $arguments): array
    {
        $kept = [];
        foreach (self::LOGGED_ARGUMENTS as $key) {
            $value = $arguments[$key] ?? null;
            if (is_scalar($value)) {
                $kept[$key] = is_string($value) ? mb_substr($value, 0, 64) : $value;
            }
        }
        if (is_string($arguments['query'] ?? null)) {
            $kept['query_length'] = mb_strlen($arguments['query']);
        }

        return $kept;
    }
}
