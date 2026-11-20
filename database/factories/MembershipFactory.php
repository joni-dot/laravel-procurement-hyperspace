<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory()->withoutCompany(),
            'role' => Role::Viewer,
            'joined_at' => now(),
        ];
    }

    /**
     * Invited but not accepted yet.
     */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => ['joined_at' => null, 'invitation_sent_at' => now()]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => ['deactivated_at' => now()]);
    }
}
