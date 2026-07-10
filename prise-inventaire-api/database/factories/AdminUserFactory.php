<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nom' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
            'actif' => true,
        ];
    }
}
