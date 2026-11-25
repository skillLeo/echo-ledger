<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Models\CaptureSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CaptureSession> */
class CaptureSessionFactory extends Factory
{
    protected $model = CaptureSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $started = now()->subHours($this->faker->numberBetween(1, 200));

        return [
            'user_id' => User::factory(),
            'workspace_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('current_workspace_id'),
            'client_ref' => (string) Str::uuid(),
            'source_device' => SourceDevice::Phone,
            'source_app' => SourceApp::WhatsApp,
            'title' => null,
            'started_at' => $started,
            'ended_at' => $started->copy()->addMinutes(10),
            'duration_ms' => 600000,
            'status' => SessionStatus::Completed,
            'final_provider' => 'assemblyai',
        ];
    }

    /**
     * A call waiting for the person's choice (contract 2.0.0-z): an open session whose call is over (ended_at sent by
     * PATCH /api/sessions/{id}), not finished yet.
     */
    public function waiting(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinute(),
            'duration_ms' => 240_000,
            'status' => SessionStatus::Recording,
        ]);
    }

    /** An open session: the recording is still running (docs/API.md "Session lifecycle"). */
    public function open(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now(),
            'ended_at' => null,
            'duration_ms' => null,
            'status' => SessionStatus::Recording,
        ]);
    }
}
