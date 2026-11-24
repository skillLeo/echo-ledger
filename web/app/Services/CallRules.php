<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SourceApp;
use App\Exceptions\ApiError;
use App\Models\CallRule;
use App\Models\User;
use App\Support\Api\FieldErrors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A person's call rules (contract 2.0.0-w, docs/API.md "Call rules", B5; docs/PLAN_CALL_RULES.md): the After each call
 * default, When to ask and the short-call setting (users.call_settings), and the rules per contact, contact group and
 * Source (call_rules). Account-wide: the same on every device, whichever workspace it is in. The apps apply them (a
 * `dont_record` is decided on the device before capture); resolve() is the order they follow, kept here once.
 * Numbers and names never go to the logs.
 */
final class CallRules
{
    public const ACTIONS = ['ask', 'always_transcribe', 'always_audio', 'dont_record'];

    public const ASK_WHEN = ['after_call', 'at_start'];

    public const SHORT_CALLS = ['dont_keep', 'keep'];

    /** The contact groups, in the order they are tried (only the phone knows them). */
    public const GROUPS = ['favourites', 'saved_contacts', 'unknown_numbers'];

    public const KINDS = ['contact', 'group', 'source'];

    public const MAX_RULES = 500;

    public const SHORT_CALL_SECONDS = 30;

    /**
     * Since 2.0.0-x (CC5): what the after-call card and Privacy & data remember, kept for the account, with defaults.
     *
     * @var array<string, bool|null>
     */
    public const PREFERENCES = ['hide_live_words' => true, 'block_screenshots' => false, 'hint_seen' => false, 'always_offered' => false, 'streak' => null];

    /** The choices a streak counts (the card's three: Transcribe, Save audio only, Delete). */
    public const STREAK_CHOICES = ['always_transcribe', 'always_audio', 'delete'];

    /** At most this many turned-down offers are kept; past it the oldest goes. */
    public const MAX_DECLINED = 500;

    /** A call the short-call setting drops: shorter than SHORT_CALL_SECONDS, or missed. */
    public const NOT_KEPT = 'not_kept';

    /**
     * The settings as `GET /api/config` gives them (`call_rules`).
     *
     * @return array{after_call: string|null, ask_when: string, short_calls: string, short_call_seconds: int, version: int, preferences: array<string, mixed>}
     */
    public function settings(User $user): array
    {
        $saved = is_array($user->call_settings) ? $user->call_settings : [];

        return [
            'after_call' => in_array($saved['after_call'] ?? null, self::ACTIONS, true) ? $saved['after_call'] : null,
            'ask_when' => in_array($saved['ask_when'] ?? null, self::ASK_WHEN, true) ? $saved['ask_when'] : 'after_call',
            'short_calls' => in_array($saved['short_calls'] ?? null, self::SHORT_CALLS, true) ? $saved['short_calls'] : 'dont_keep',
            'short_call_seconds' => self::SHORT_CALL_SECONDS,
            'version' => (int) ($saved['version'] ?? 0),
            // Since 2.0.0-x (CC5).
            'preferences' => self::preferencesOf($saved),
        ];
    }

    /**
     * The preferences as saved, each key with its default when never set.
     *
     * @param  array<string, mixed>  $saved  users.call_settings
     * @return array<string, mixed>
     */
    private static function preferencesOf(array $saved): array
    {
        $kept = is_array($saved['preferences'] ?? null) ? $saved['preferences'] : [];
        $preferences = [];
        foreach (self::PREFERENCES as $key => $default) {
            $preferences[$key] = array_key_exists($key, $kept) ? $kept[$key] : $default;
        }
        // In the contract's order whatever the database keeps (MySQL's JSON sorts an object's keys).
        if (is_array($preferences['streak'])) {
            $preferences['streak'] = ['choice' => $preferences['streak']['choice'] ?? null, 'count' => $preferences['streak']['count'] ?? null];
        }

        return $preferences;
    }

    /**
     * `GET /api/call-rules`: the settings, when they last changed, and every rule in the order they are tried.
     *
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $saved = is_array($user->call_settings) ? $user->call_settings : [];

        return [
            ...$this->settings($user),
            'updated_at' => is_string($saved['updated_at'] ?? null) ? $saved['updated_at'] : null,
            'rules' => $this->rules($user)->map(fn (CallRule $rule): array => $rule->toApi())->all(),
            // Since 2.0.0-x (CC5): newest first, without the matching key the server keeps beside each.
            'declined' => array_map(fn (array $entry): array => array_filter([
                'kind' => $entry['kind'] ?? null, 'number' => $entry['number'] ?? null, 'name' => $entry['name'] ?? null,
                'source_app' => $entry['source_app'] ?? null,
            ], fn ($value): bool => $value !== null), is_array($saved['declined'] ?? null) ? array_values($saved['declined']) : []),
        ];
    }

    /**
     * `PATCH /api/call-rules/preferences` (since 2.0.0-x): any of PREFERENCES, each on its own; the others keep theirs.
     *
     * @param  array<string, mixed>  $input
     */
    public function updatePreferences(User $user, array $input): void
    {
        $unknown = array_diff(array_keys($input), array_keys(self::PREFERENCES));
        if ($unknown !== []) {
            throw FieldErrors::fail(array_fill_keys(array_map('strval', $unknown), [['invalid', 'That is not one of the preferences.']]));
        }
        $data = FieldErrors::validate($input, [
            'hide_live_words' => ['sometimes', 'required', 'boolean'],
            'block_screenshots' => ['sometimes', 'required', 'boolean'],
            'hint_seen' => ['sometimes', 'required', 'boolean'],
            'always_offered' => ['sometimes', 'required', 'boolean'],
            'streak' => ['sometimes', 'nullable', 'array:choice,count'],
            'streak.choice' => ['required_with:streak', 'string', Rule::in(self::STREAK_CHOICES)],
            'streak.count' => ['required_with:streak', 'integer', 'min:1', 'max:99'],
        ]);
        $changes = [];
        foreach (array_keys(self::PREFERENCES) as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $changes[$key] = $key === 'streak'
                ? (is_array($data['streak'] ?? null) ? ['choice' => (string) $data['streak']['choice'], 'count' => (int) $data['streak']['count']] : null)
                : (bool) $data[$key];
        }
        $this->changed($user, function (array $settings) use ($changes): array {
            $settings['preferences'] = [...(is_array($settings['preferences'] ?? null) ? $settings['preferences'] : []), ...$changes];

            return [$settings, null];
        });
    }

    /**
     * `POST /api/call-rules/declined` (since 2.0.0-x): a contact or Source whose "Always …?" offer was turned down,
     * newest first; one already there moves to the front. At most MAX_DECLINED, the oldest going.
     *
     * @param  array<string, mixed>  $input
     * @return bool whether it is new
     */
    public function decline(User $user, array $input): bool
    {
        $data = FieldErrors::validate($input, [
            'kind' => ['required', 'string', Rule::in(['contact', 'source'])],
            'number' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'min:1', 'max:120'],
            'source_app' => ['required_if:kind,source', 'nullable', 'string', Rule::in(self::sources())],
        ]);
        $fields = $this->fields([...$data, 'action' => 'ask']);
        $entry = array_filter(['kind' => $fields['kind'], 'number' => $fields['number'], 'name' => $fields['name'],
            'source_app' => $fields['source_app'], 'match_key' => $fields['match_key']], fn ($v): bool => $v !== null);

        return $this->changed($user, function (array $settings) use ($entry): array {
            $list = is_array($settings['declined'] ?? null) ? array_values($settings['declined']) : [];
            foreach ($list as $old) {
                // The same person, already kept with the country code: keep that form, as rules do.
                if (self::sameTarget($old, $entry) && isset($old['number'], $entry['number']) && str_starts_with((string) $old['number'], '+')
                    && ! str_starts_with((string) $entry['number'], '+')) {
                    $entry['number'] = $old['number'];
                    $entry['match_key'] = $old['number'];
                }
            }
            $kept = array_values(array_filter($list, fn (array $old): bool => ! self::sameTarget($old, $entry)));
            $new = count($kept) === count($list);
            $settings['declined'] = array_slice([$entry, ...$kept], 0, self::MAX_DECLINED);

            return [$settings, $new];
        });
    }

    /** `DELETE /api/call-rules/declined` (since 2.0.0-x): every turned-down offer forgotten. */
    public function forgetDeclined(User $user): void
    {
        $this->changed($user, function (array $settings): array {
            $settings['declined'] = [];

            return [$settings, null];
        });
    }

    /**
     * Whether two declined entries are the same contact or Source: the same Source, the same number (with or without
     * its country code), or, without numbers, the same name.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameTarget(array $a, array $b): bool
    {
        if (($a['kind'] ?? null) !== ($b['kind'] ?? null)) {
            return false;
        }
        if (isset($a['number'], $b['number'])) {
            return self::sameNumber((string) $a['number'], (string) $b['number']);
        }

        return ($a['match_key'] ?? null) === ($b['match_key'] ?? null);
    }

    /** @return Collection<int, CallRule> contact, then group, then Source; by name within each */
    public function rules(User $user): Collection
    {
        $order = array_flip(self::KINDS);

        return CallRule::query()->where('user_id', $user->id)->get()
            ->sortBy(fn (CallRule $rule): string => $order[$rule->kind].'|'.mb_strtolower((string) ($rule->name ?? $rule->group_key ?? $rule->source_app ?? '')).'|'.$rule->match_key)
            ->values();
    }

    /**
     * `PUT /api/call-rules/settings`: any of after_call, ask_when, short_calls.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateSettings(User $user, array $input): void
    {
        $data = FieldErrors::validate($input, [
            'after_call' => ['sometimes', 'required', 'string', Rule::in(self::ACTIONS)],
            'ask_when' => ['sometimes', 'required', 'string', Rule::in(self::ASK_WHEN)],
            'short_calls' => ['sometimes', 'required', 'string', Rule::in(self::SHORT_CALLS)],
        ]);
        $this->changed($user, fn (array $settings): array => [[...$settings, ...$data], null]);
    }

    /**
     * `POST /api/call-rules`: one rule per contact, group or Source; a second one for the same replaces the first.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: CallRule, 1: bool} the rule, and whether it is new
     */
    public function save(User $user, array $input): array
    {
        $data = FieldErrors::validate($input, [
            'kind' => ['required', 'string', Rule::in(self::KINDS)],
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'number' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'min:1', 'max:120'],
            'group' => ['required_if:kind,group', 'nullable', 'string', Rule::in(self::GROUPS)],
            'source_app' => ['required_if:kind,source', 'nullable', 'string', Rule::in(self::sources())],
        ]);
        $fields = $this->fields($data);

        return $this->changed($user, function (array $settings) use ($user, $fields): array {
            $rule = CallRule::query()->where('user_id', $user->id)->where('kind', $fields['kind'])->where('match_key', $fields['match_key'])->first()
                ?? $this->sameContact($user, $fields['number']);
            if ($rule !== null && $fields['number'] !== null && $rule->number !== null && ! str_starts_with($fields['number'], '+') && str_starts_with($rule->number, '+')) {
                // The same person, already kept with the country code: keep that form.
                $fields['number'] = $rule->number;
                $fields['match_key'] = $rule->number;
            }
            $new = $rule === null;
            if ($new && CallRule::query()->where('user_id', $user->id)->count() >= self::MAX_RULES) {
                throw new ApiError(409, 'too_many_rules', 'You have '.self::MAX_RULES.' call rules, the most there can be. Remove one first.');
            }
            $rule ??= new CallRule(['user_id' => $user->id]);
            $rule->fill($fields)->save();

            return [$settings, [$rule, $new]];
        });
    }

    /**
     * `PATCH /api/call-rules/{id}`: its action and/or its name.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, string $id, array $input): CallRule
    {
        $data = FieldErrors::validate($input, [
            'action' => ['sometimes', 'required', 'string', Rule::in(self::ACTIONS)],
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
        ]);

        return $this->changed($user, function (array $settings) use ($user, $id, $data): array {
            $rule = $this->find($user, $id);
            if (array_key_exists('name', $data)) {
                $name = self::cleanName((string) $data['name']);
                // A contact known only by name is matched by it: renaming it is matching another name, and a rule
                // already there for that name gives way to this one (one rule per contact).
                if ($rule->kind === 'contact' && $rule->number === null) {
                    $rule->match_key = 'name:'.mb_strtolower($name);
                    CallRule::query()->where('user_id', $user->id)->where('kind', 'contact')->where('match_key', $rule->match_key)
                        ->whereKeyNot($rule->id)->delete();
                }
                $rule->name = $name;
            }
            if (array_key_exists('action', $data)) {
                $rule->action = (string) $data['action'];
            }
            $rule->save();

            return [$settings, $rule];
        });
    }

    /** `DELETE /api/call-rules/{id}`. */
    public function delete(User $user, string $id): void
    {
        $this->changed($user, function (array $settings) use ($user, $id): array {
            $this->find($user, $id)->delete();

            return [$settings, null];
        });
    }

    /**
     * What happens to a call (docs/API.md "Which rule wins"): the contact (number, else name), then its groups, then the
     * Source, then the default. A rule of `ask` stops the search and follows the default. A short or missed call is
     * not kept unless short_calls is `keep`. A project's "Always transcribe calls" turns `ask` or `always_audio` into
     * a transcript once the call is filed into it, and never undoes `dont_record` (there is no recording).
     *
     * @param  list<string>  $groups  the contact's groups, as the phone knows them
     * @return string one of ACTIONS (the default's `ask` included), or NOT_KEPT
     */
    public function resolve(User $user, ?string $number = null, ?string $name = null, array $groups = [], ?string $source = null,
        ?int $seconds = null, bool $missed = false, bool $projectAlways = false): string
    {
        $settings = $this->settings($user);
        if ($settings['short_calls'] === 'dont_keep' && ($missed || ($seconds !== null && $seconds < self::SHORT_CALL_SECONDS))) {
            return self::NOT_KEPT;
        }
        $rules = CallRule::query()->where('user_id', $user->id)->get();
        $found = $this->contactRule($rules, $number, $name)
            ?? $this->groupRule($rules, $groups)
            ?? ($source !== null ? $rules->first(fn (CallRule $r): bool => $r->kind === 'source' && $r->source_app === $source) : null);
        $action = $found !== null && $found->action !== 'ask' ? $found->action : ($settings['after_call'] ?? 'ask');
        if ($projectAlways && in_array($action, ['ask', 'always_audio'], true)) {
            return 'always_transcribe';
        }

        return $action;
    }

    /**
     * A phone number as rules keep and compare it: spaces, dashes, dots and brackets removed, a leading 00 made +.
     * Null when what is left is not a number.
     */
    public static function normalizeNumber(?string $number): ?string
    {
        $clean = preg_replace('/[\s\-.()\x{00A0}]+/u', '', trim((string) $number)) ?? '';
        if (str_starts_with($clean, '00')) {
            $clean = '+'.substr($clean, 2);
        }

        return preg_match('/^\+?\d{4,20}$/', $clean) === 1 ? $clean : null;
    }

    /** Whether two normalised numbers are the same person's: equal, or the same last 10 digits when one has no country code. */
    public static function sameNumber(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (str_starts_with($a, '+') && str_starts_with($b, '+')) {
            return false;
        }
        $da = ltrim($a, '+');
        $db = ltrim($b, '+');

        return strlen($da) >= 10 && strlen($db) >= 10 && substr($da, -10) === substr($db, -10);
    }

    /** @return list<string> every Source a rule can name (all but voice notes and Other) */
    public static function sources(): array
    {
        $sources = [];
        foreach (SourceApp::cases() as $app) {
            if ($app !== SourceApp::VoiceNote && $app !== SourceApp::Other) {
                $sources[] = $app->value;
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $data  validated
     * @return array<string, string|null>
     */
    private function fields(array $data): array
    {
        $kind = (string) $data['kind'];
        $action = (string) $data['action'];
        if ($kind === 'group') {
            return ['kind' => $kind, 'action' => $action, 'match_key' => (string) $data['group'], 'group_key' => (string) $data['group'],
                'number' => null, 'name' => null, 'source_app' => null];
        }
        if ($kind === 'source') {
            return ['kind' => $kind, 'action' => $action, 'match_key' => (string) $data['source_app'], 'source_app' => (string) $data['source_app'],
                'number' => null, 'name' => null, 'group_key' => null];
        }
        $rawNumber = is_string($data['number'] ?? null) ? trim($data['number']) : '';
        $number = $rawNumber !== '' ? self::normalizeNumber($rawNumber) : null;
        $name = is_string($data['name'] ?? null) && trim($data['name']) !== '' ? self::cleanName($data['name']) : null;
        if ($rawNumber !== '' && $number === null) {
            throw FieldErrors::fail(['number' => [['invalid_number', 'That is not a phone number.']]]);
        }
        if ($number === null && $name === null) {
            throw FieldErrors::fail(['number' => [['required', 'A contact rule needs the number or the name.']]]);
        }

        return ['kind' => 'contact', 'action' => $action, 'number' => $number, 'name' => $name, 'group_key' => null, 'source_app' => null,
            'match_key' => $number ?? 'name:'.mb_strtolower((string) $name)];
    }

    /** The person's rule for the same number in another form (with or without the country code), if any. */
    private function sameContact(User $user, ?string $number): ?CallRule
    {
        if ($number === null) {
            return null;
        }

        return CallRule::query()->where('user_id', $user->id)->where('kind', 'contact')->whereNotNull('number')->get()
            ->first(fn (CallRule $r): bool => self::sameNumber((string) $r->number, $number));
    }

    /** @param  Collection<int, CallRule>  $rules */
    private function contactRule(Collection $rules, ?string $number, ?string $name): ?CallRule
    {
        $contacts = $rules->where('kind', 'contact');
        $number = self::normalizeNumber($number);
        if ($number !== null) {
            $byNumber = $contacts->first(fn (CallRule $r): bool => $r->number !== null && self::sameNumber($r->number, $number));
            if ($byNumber !== null) {
                return $byNumber;
            }
        }
        $name = $name !== null && trim($name) !== '' ? 'name:'.mb_strtolower(self::cleanName($name)) : null;

        return $name !== null ? $contacts->first(fn (CallRule $r): bool => $r->number === null && $r->match_key === $name) : null;
    }

    /**
     * @param  Collection<int, CallRule>  $rules
     * @param  list<string>  $groups
     */
    private function groupRule(Collection $rules, array $groups): ?CallRule
    {
        foreach (self::GROUPS as $group) {
            if (in_array($group, $groups, true)) {
                $rule = $rules->first(fn (CallRule $r): bool => $r->kind === 'group' && $r->group_key === $group);
                if ($rule !== null) {
                    return $rule;
                }
            }
        }

        return null;
    }

    private function find(User $user, string $id): CallRule
    {
        $rule = CallRule::query()->where('user_id', $user->id)->whereKey($id)->first();
        if (! $rule instanceof CallRule) {
            throw new ApiError(404, 'rule_not_found', 'That call rule is not there any more.');
        }

        return $rule;
    }

    private static function cleanName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 120);
    }

    /**
     * Runs [$work] with the person's settings locked, then saves them with the version one higher: every change to the
     * settings or the rules is one version, so the apps know to read them again.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>, 1: T}  $work
     * @return T|null
     */
    private function changed(User $user, callable $work): mixed
    {
        return DB::transaction(function () use ($user, $work): mixed {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $settings = is_array($locked->call_settings) ? $locked->call_settings : [];
            [$settings, $value] = $work($settings);
            $settings['version'] = (int) ($settings['version'] ?? 0) + 1;
            $settings['updated_at'] = now()->toIso8601ZuluString();
            $locked->forceFill(['call_settings' => $settings])->save();
            $user->setAttribute('call_settings', $settings);

            return $value;
        });
    }
}
