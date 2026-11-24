<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SourceApp;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\ActivityFeed;
use App\Services\TranscriptExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A call an app saw but did not record (since v1.8, docs/API.md `POST /api/devices/missed-recordings`): its app, when
 * it started, how long it lasted and why nothing was recorded. Never names, phone numbers, audio or free text. Its
 * person reads it in Activity ("Missed recording"); staff read it only as the counts of Admin → Diagnostics → Missed
 * recordings. Kept 90 days from the call (call data), then pruned by `model:prune`; it goes earlier with its person or
 * workspace, and its Activity item always goes with it (contract 1.8.0-c, ActivityFeed::listen).
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $device_id
 * @property string $client_ref
 * @property string $platform
 * @property string|null $app_version
 * @property string $source_app
 * @property Carbon $call_started_at
 * @property int|null $call_duration_s
 * @property string $reason
 * @property Carbon $created_at
 * @property-read Device|null $device
 * @property-read User $user
 */
class MissedRecording extends Model
{
    // One by one, not in bulk: each deleted row takes its Activity item with it (ActivityFeed::listen).
    use BelongsToWorkspace, HasUlids, Prunable;

    /** Why nothing was recorded, and how Activity says it to the person. */
    public const REASONS = [
        'helper_not_armed' => 'The helper was not connected',
        'permission' => 'A permission was off',
        'detection' => 'The call was not detected',
        'mic_busy' => 'Another app was using the microphone',
        'crash' => 'The app stopped during the call',
        'plan' => 'Your plan did not allow the recording',
        'unknown' => 'The reason is not known',
    ];

    /** A meeting in a browser that no known meeting app was recognised in. */
    public const BROWSER = 'browser';

    /** The oldest call an app may report. */
    public const MAX_AGE_DAYS = 30;

    /** How far ahead of the server's clock a device's clock may run: a call that started "now" is not in the future. */
    public const CLOCK_DRIFT_MINUTES = 5;

    /** Days a row is kept after its call (docs/API.md: call data). */
    public const KEEP_DAYS = 90;

    public const UPDATED_AT = null;

    /** The app's own id for the event (contract 1.8.0-c): opaque, a UUID fits. */
    public const CLIENT_REF_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/D';

    protected $fillable = ['user_id', 'device_id', 'workspace_id', 'client_ref', 'platform', 'app_version', 'source_app', 'call_started_at', 'call_duration_s', 'reason'];

    /**
     * Every `source_app` an app may send: the sessions' wire values (App\Enums\SourceApp), or `browser`.
     *
     * @return list<string>
     */
    public static function sourceApps(): array
    {
        return [...array_map(fn (SourceApp $app): string => $app->value, SourceApp::cases()), self::BROWSER];
    }

    /** "WhatsApp call", "Phone call", "Call in a browser", or "Call" for an app EchoLedger does not name. */
    public static function callName(string $sourceApp): string
    {
        if ($sourceApp === self::BROWSER) {
            return 'Call in a browser';
        }
        $app = SourceApp::tryFrom($sourceApp);

        return match ($app) {
            null, SourceApp::Other => 'Call',
            SourceApp::PhoneCall, SourceApp::VoiceNote => $app->getLabel(),
            default => $app->getLabel().' call',
        };
    }

    /** "WhatsApp call · 5:12 · The helper was not connected", without the length when the app did not know it. */
    public function activityBody(): string
    {
        return ActivityFeed::text($this->activityLine(), 'en');
    }

    /**
     * The same in the reader's language (W-218): the call's name and the reason are lines of lang/ur.json, the length
     * stays as it is.
     *
     * @return array{key: string, params: array<string, mixed>}
     */
    public function activityLine(): array
    {
        $app = SourceApp::tryFrom($this->source_app);
        $call = match (true) {
            $this->source_app === self::BROWSER => ActivityFeed::line('Call in a browser'),
            $app === null, $app === SourceApp::Other => ActivityFeed::line('Call'),
            $app === SourceApp::PhoneCall, $app === SourceApp::VoiceNote => ActivityFeed::line((string) $app->getLabel()),
            default => ActivityFeed::line(':app call', ['app' => ActivityFeed::line((string) $app->getLabel())]),
        };
        $reason = ActivityFeed::line(self::REASONS[$this->reason] ?? self::REASONS['unknown']);

        return $this->call_duration_s !== null
            ? ActivityFeed::line(':call · :length · :reason', ['call' => $call, 'length' => TranscriptExporter::clock($this->call_duration_s * 1000), 'reason' => $reason])
            : ActivityFeed::line(':call · :reason', ['call' => $call, 'reason' => $reason]);
    }

    /**
     * Rows whose call is older than KEEP_DAYS, in every workspace (the daily `model:prune`).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('call_started_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['call_started_at' => 'datetime', 'call_duration_s' => 'integer', 'created_at' => 'datetime'];
    }
}
