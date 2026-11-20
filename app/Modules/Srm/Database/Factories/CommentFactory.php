<?php

namespace App\Modules\Srm\Database\Factories;

use App\Models\User;
use App\Modules\Srm\Models\Comment;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'user_id' => User::factory()->buyer(),
            'body' => fake()->sentence(),
        ];
    }

    public function aboutContract(Contract $contract): static
    {
        return $this->state(fn (array $attributes) => [
            'supplier_id' => $contract->supplier_id,
            'contract_id' => $contract->id,
        ]);
    }
}
