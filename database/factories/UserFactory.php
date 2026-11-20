<?php

namespace Database\Factories;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * The role new users get in the current company; null leaves them outside every company.
     */
    private ?Role $companyRole = Role::Viewer;

    private bool $joinsCompany = true;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * An admin of the current company.
     */
    public function admin(): static
    {
        return $this->withCompanyRole(Role::Admin);
    }

    /**
     * A buyer in the current company.
     */
    public function buyer(): static
    {
        return $this->withCompanyRole(Role::Buyer);
    }

    /**
     * An account that belongs to no company.
     */
    public function withoutCompany(): static
    {
        return $this->withCompanyRole(null);
    }

    public function platformAdmin(): static
    {
        return $this->withoutCompany()->state(fn (array $attributes) => ['is_platform_admin' => true]);
    }

    /**
     * Invited to the current company but hasn't accepted or set a password yet.
     */
    public function invited(): static
    {
        $factory = $this->state(fn (array $attributes) => ['password' => null]);
        $factory->joinsCompany = false;

        return $factory;
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => ['deactivated_at' => now()]);
    }

    /**
     * Users join the current company after every other after-creating step, with the role the
     * states chose. Tests in several companies attach more memberships themselves.
     *
     * @param  Collection<int, User>  $instances
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null)
    {
        parent::callAfterCreating($instances, $parent);

        $companyId = app(CurrentCompany::class)->id();

        if ($this->companyRole === null || $companyId === null) {
            return;
        }

        $instances->each(fn (User $user) => Membership::query()->forceCreate([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'role' => $this->companyRole,
            'joined_at' => $this->joinsCompany ? now() : null,
            ...($this->joinsCompany ? [] : [
                'known_name' => $user->name,
                'known_email' => $user->email,
                'invitation_token' => hash('sha256', Str::random(64)),
                'invitation_sent_at' => now(),
            ]),
        ]));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $factory = parent::newInstance($arguments);
        $factory->companyRole = $this->companyRole;
        $factory->joinsCompany = $this->joinsCompany;

        return $factory;
    }

    private function withCompanyRole(?Role $role): static
    {
        $factory = $this->newInstance();
        $factory->companyRole = $role;

        return $factory;
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
