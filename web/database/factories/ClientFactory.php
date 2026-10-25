<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->value('current_workspace_id'),
            'name' => $this->faker->unique()->company(),
            'color' => '#8B5CF6',
        ];
    }
}
