<?php

namespace Database\Factories\Domain\Company\Models;

use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'type' => $this->faker->randomElement(['buyer', 'vendor']),
            'status' => 'approved',
            'tax_id' => $this->faker->numerify('##############'),
            'email' => $this->faker->companyEmail,
            'phone' => $this->faker->phoneNumber,
            'city' => $this->faker->city,
            'address' => $this->faker->address,
        ];
    }
}
