<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // 'password' is hashed by the model's 'hashed' cast on save
            // (argon2id; test cost tuned in phpunit.xml).
            'password_hash' => 'password',
            'email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ];
    }
}
