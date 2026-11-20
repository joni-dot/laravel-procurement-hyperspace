<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
     * Invited but not accepted yet, with a link that works. Its token isn't known; tests that open
     * the link send the invitation with SendInvitation.
     */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'joined_at' => null,
            'invitation_token' => hash('sha256', Str::random(64)),
            'invitation_sent_at' => now(),
        ])->afterMaking(function (Membership $membership): void {
            $membership->invited_name ??= $membership->user->name;
            $membership->invited_email ??= $membership->user->email;
        });
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => ['deactivated_at' => now()]);
    }
}
