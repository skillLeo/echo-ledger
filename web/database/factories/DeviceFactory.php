<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('current_workspace_id'),
            'name' => $this->faker->randomElement(['Pixel', 'Galaxy', 'Work laptop', 'MacBook']).' '.$this->faker->numberBetween(1, 99),
            'platform' => DevicePlatform::Android,
            'app_version' => '1.0.0',
            'os_version' => '16',
            'last_seen_at' => now(),
        ];
    }

    public function desktop(): static
    {
        return $this->state(['platform' => DevicePlatform::Windows, 'os_version' => '11']);
    }
}
