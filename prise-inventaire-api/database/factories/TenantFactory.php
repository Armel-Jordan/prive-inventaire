<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'nom' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(2),
            'db_name' => 'tenant_'.$this->faker->unique()->numerify('######'),
            'actif' => true,
            'date_expiration' => now()->addYear(),
            'plan' => 'basic',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['date_expiration' => now()->subDay()]);
    }
}
