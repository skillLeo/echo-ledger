<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Share Check result a desktop app chose to send (since v1.8.1, docs/API.md "Screen-share privacy"): whether
 * EchoLedger's test window stayed out of a capture on that computer. Never pictures, window titles or contents. Read
 * only by staff, and only as the counts of Admin → Diagnostics → Screen-share checks.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $device_id
 * @property string $platform
 * @property string $os_build
 * @property string $app_version
 * @property string $method
 * @property string $result
 * @property string|null $reason
 * @property string|null $meeting_app
 * @property string|null $meeting_app_version
 * @property string|null $share_mode
 * @property Carbon $created_at
 * @property-read Device|null $device
 * @property-read User $user
 */
class ShareCheckResult extends Model
{
    public const PLATFORMS = ['windows', 'macos', 'android'];

    public const METHODS = ['wgc', 'dxgi', 'sck', 'readback', 'guided', 'recording_callback'];

    public const RESULTS = ['pass', 'fail', 'error'];

    public const REASONS = ['permission_missing', 'capture_failed', 'control_missing', 'old_windows', 'remote_desktop', 'flag_mismatch'];

    public const MEETING_APPS = ['zoom', 'meet', 'teams', 'slack', 'whatsapp', 'other'];

    public const SHARE_MODES = ['screen', 'window', 'tab'];

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'device_id', 'platform', 'os_build', 'app_version', 'method', 'result', 'reason', 'meeting_app', 'meeting_app_version', 'share_mode'];

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
        return ['created_at' => 'datetime'];
    }
}
