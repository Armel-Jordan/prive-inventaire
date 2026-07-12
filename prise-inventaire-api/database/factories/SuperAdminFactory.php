<?php

namespace Database\Factories;

use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuperAdmin>
 */
class SuperAdminFactory extends Factory
{
    protected $model = SuperAdmin::class;

    public function definition(): array
    {
        return [
            'nom' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'password',
            'actif' => true,
        ];
    }
}
