<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The last device check an app chose to send (since v1.5, docs/API.md `POST /api/devices/report`): what works on that
 * phone or computer. Never recordings, names or phone numbers.
 *
 * @property string $device_id
 * @property int $user_id
 * @property string $platform
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $os_version
 * @property string|null $app_version
 * @property list<array{key: string, state: string, detail: string|null}> $checks
 * @property Carbon $reported_at
 * @property-read Device $device
 * @property-read User $user
 */
class DeviceReport extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['device_id', 'user_id', 'workspace_id', 'platform', 'brand', 'model', 'os_version', 'app_version', 'checks', 'reported_at'];

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

    /** "3 work · 1 limited · 1 off" for the list. */
    public function summary(): string
    {
        $counts = array_count_values(array_column($this->checks, 'state'));
        $parts = [];
        foreach (['ok' => 'work', 'limited' => 'limited', 'off' => 'off', 'failed' => 'failed'] as $state => $word) {
            if (($counts[$state] ?? 0) > 0) {
                $parts[] = $counts[$state].' '.$word;
            }
        }

        return implode(' · ', $parts);
    }

    protected function casts(): array
    {
        return ['checks' => 'array', 'reported_at' => 'datetime'];
    }
}
