<?php

declare(strict_types=1);

namespace App\Services\CallRules;

use App\Exceptions\ApiError;
use App\Models\CallRule;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * A person's call rules (contract 2.1.0-a, docs/API.md "Call rules and audio clean-up"): the after-call default, short
 * calls, and rules per contact, group and source. Which rule wins: contact > group > source > the default. The server
 * stores and serves them; "Don't record" is decided on the device, before capture.
 */
final class CallRules
{
    /** What a rule or the default can say. */
    public const ACTIONS = ['transcribe', 'keep_audio', 'dont_record', 'ask'];

    /** The quick groups of contacts (Android first). */
    public const GROUPS = ['saved_contacts', 'unknown_numbers', 'favourites'];

    /** The desktop sources a rule can name. */
    public const SOURCES = ['zoom', 'google_meet', 'microsoft_teams', 'slack', 'whatsapp_desktop'];

    /** Calls shorter than this are not kept when short_calls.keep is false. */
    public const SHORT_CALL_SECONDS = 30;

    public const MAX_CONTACTS = 1000;

    /** New accounts start on "Ask me" (D4); a person with no stored default keeps the legacy behaviour. */
    public const NEW_ACCOUNT_DEFAULT = 'ask';

    public function __construct(private readonly Entitlements $entitlements) {}

    /** @return array<string, mixed> the whole document of GET /api/call-rules */
    public function document(User $user): array
    {
        $rules = CallRule::query()->where('user_id', $user->id)->orderBy('id')->get();
        $of = fn (string $kind, array $keys): array => array_combine($keys, array_map(
            fn (string $key): ?string => $rules->first(fn (CallRule $r): bool => $r->kind === $kind && $r->key === $key)?->action, $keys));

        return [
            'revision' => (int) $user->call_rules_revision,
            'default' => ['action' => $user->after_call_default ?? 'transcribe', 'stored' => $user->after_call_default !== null],
            'short_calls' => ['keep' => $this->keepsShortCalls($user), 'seconds' => self::SHORT_CALL_SECONDS],
            'groups' => $of('group', self::GROUPS),
            'sources' => $of('source', self::SOURCES),
            'contacts' => $rules->where('kind', 'contact')->values()->map(fn (CallRule $r): array => [
                'key' => $r->key, 'name' => $r->name, 'action' => $r->action, 'updated_at' => $r->updated_at->toAtomString(),
            ])->all(),
        ];
    }

    /** Short calls are kept unless the person said otherwise; legacy accounts (null) keep them. */
    public function keepsShortCalls(User $user): bool
    {
        return $user->short_calls_keep ?? true;
    }

    /** [$workspaceId]: the workspace the asking device belongs to, whose plan decides auto_transcribe. */
    public function setDefault(User $user, string $action, int $workspaceId): void
    {
        $this->assertAction($user, $action, $workspaceId);
        $user->forceFill(['after_call_default' => $action])->save();
        $this->bump($user);
    }

    public function setShortCalls(User $user, bool $keep): void
    {
        $user->forceFill(['short_calls_keep' => $keep])->save();
        $this->bump($user);
    }

    /** Creates or changes a rule; `null` action removes it ("Use my default"). */
    public function setRule(User $user, int $workspaceId, string $kind, string $key, ?string $action, ?string $name = null): void
    {
        $key = self::normaliseKey($kind, $key);
        if ($action === null) {
            CallRule::query()->where('user_id', $user->id)->where('kind', $kind)->where('key', $key)->delete();
            $this->bump($user);

            return;
        }
        $this->assertAction($user, $action, $workspaceId);
        DB::transaction(function () use ($user, $kind, $key, $action, $name): void {
            $existing = CallRule::query()->where('user_id', $user->id)->where('kind', $kind)->where('key', $key)->lockForUpdate()->first();
            if ($existing === null && $kind === 'contact'
                && CallRule::query()->where('user_id', $user->id)->where('kind', 'contact')->count() >= self::MAX_CONTACTS) {
                throw new ApiError(422, 'too_many_rules', 'You have reached the most contact rules ('.self::MAX_CONTACTS.').');
            }
            CallRule::query()->updateOrCreate(['user_id' => $user->id, 'kind' => $kind, 'key' => $key],
                ['action' => $action, ...($kind === 'contact' ? ['name' => $name] : [])]);
        });
        $this->bump($user);
    }

    /**
     * What the rules say for one call, by the server's own precedence: contact > group > source > default.
     *
     * @return array{action: string, by: string}
     */
    public function resolve(User $user, int $workspaceId, ?string $source = null, ?string $contactKey = null, ?string $group = null): array
    {
        $rules = CallRule::query()->where('user_id', $user->id)->get();
        $find = fn (string $kind, ?string $key): ?string => $key === null ? null
            : $rules->first(fn (CallRule $r): bool => $r->kind === $kind && $r->key === $key)?->action;

        $contact = $contactKey !== null && self::validKey($contactKey) ? $find('contact', self::normaliseKey('contact', $contactKey)) : null;
        [$action, $by] = match (true) {
            $contact !== null => [$contact, 'contact'],
            ($g = $find('group', $group)) !== null => [$g, 'group'],
            ($s = $find('source', $source)) !== null => [$s, 'source'],
            $user->after_call_default !== null => [$user->after_call_default, 'default'],
            default => ['transcribe', 'legacy'],
        };
        // On a plan without auto_transcribe a stored "transcribe" asks instead (legacy keeps today's behaviour).
        if ($action === 'transcribe' && $by !== 'legacy' && ! $this->autoTranscribe($user, $workspaceId)) {
            $action = 'ask';
        }

        return ['action' => $action, 'by' => $by];
    }

    /**
     * Whether "Transcribe automatically" is in the plan of [$workspaceId]: always the workspace of the recording or of
     * the asking device, never the person's current one (a person in a paid and a Free workspace must not carry one
     * plan's feature to the other's recordings).
     */
    public function autoTranscribe(User $user, int $workspaceId): bool
    {
        return $this->entitlements->allows($workspaceId, $user->id, 'auto_transcribe');
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^(tel|wa|app):[^\s]{1,116}$/i', $key) === 1 && strlen($key) >= 3 && strlen($key) <= 120;
    }

    private static function normaliseKey(string $kind, string $key): string
    {
        return $kind === 'contact' ? mb_strtolower($key) : $key;
    }

    private function assertAction(User $user, string $action, int $workspaceId): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new ApiError(422, 'invalid_action', 'That is not one of the choices.');
        }
        if ($action === 'transcribe' && ! $this->autoTranscribe($user, $workspaceId)) {
            $this->entitlements->assertFeature($workspaceId, $user->id, 'auto_transcribe');
        }
    }

    private function bump(User $user): void
    {
        User::query()->whereKey($user->id)->increment('call_rules_revision');
        $user->refresh();
    }
}
