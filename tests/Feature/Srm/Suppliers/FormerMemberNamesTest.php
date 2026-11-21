<?php

namespace Tests\Feature\Srm\Suppliers;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Models\Comment;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierRating;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Someone who has left the company is shown on its suppliers with the name they had when they left,
 * never with their account's later one (Membership::nameOnRecordsSql()).
 */
class FormerMemberNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $lea;

    private Supplier $supplier;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->buyer()->create(['name' => 'Bea Buyer']);
        $this->lea = User::factory()->buyer()->create(['name' => 'Lea Before', 'email' => 'lea.before@example.com']);
        $this->supplier = Supplier::factory()->create();
        $this->supplier->forceFill([
            'responsible_user_id' => $this->lea->id,
            'risk_override' => RiskLevel::High,
            'risk_override_reason' => 'Late deliveries',
            'risk_overridden_by_user_id' => $this->lea->id,
            'risk_overridden_at' => now(),
        ])->save();
        $this->contract = Contract::factory()->for($this->supplier)->create(['owner_user_id' => $this->lea->id]);
        SupplierRating::factory()->create(['supplier_id' => $this->supplier->id, 'rated_by_user_id' => $this->lea->id]);
        Comment::factory()->for($this->supplier)->create(['user_id' => $this->lea->id, 'body' => 'About the supplier']);
        Comment::factory()->aboutContract($this->contract)->create(['user_id' => $this->lea->id, 'body' => 'About the contract']);
    }

    public function test_someone_who_left_keeps_the_name_they_had_then()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.deactivate', $this->lea->memberships()->sole()))
            ->assertRedirect();
        $this->lea->update(['name' => 'Lea After', 'email' => 'lea.after@example.com']);

        $this->assertShowsPerson('Lea Before');
        $this->assertPagesDontMention('Lea After', 'lea.after@example.com');
    }

    public function test_leaving_another_company_does_not_change_the_name_shown_here()
    {
        Membership::factory()->for(Company::factory())->create([
            'user_id' => $this->lea->id,
            'known_name' => 'Lea Elsewhere',
            'known_email' => 'lea.elsewhere@example.com',
            'joined_at' => now()->subYear(),
            'deactivated_at' => now(),
        ]);
        $this->lea->update(['name' => 'Lea After', 'email' => 'lea.after@example.com']);

        $this->assertShowsPerson('Lea After');
        $this->assertPagesDontMention('Lea Before', 'Lea Elsewhere');
    }

    private function assertShowsPerson(string $name): void
    {
        $this->actingAs($this->buyer)
            ->get(route('suppliers.show', $this->supplier))
            ->assertInertia(fn ($page) => $page
                ->where('supplier.responsible_user', $name)
                ->where('supplier.risk.overridden_by', $name)
                ->where('supplier.ratings.0.rated_by', $name)
                ->where('comments.items', fn ($comments) => $comments->pluck('author')->unique()->all() === [$name]));

        $this->actingAs($this->buyer)
            ->get(route('suppliers.contracts.show', [$this->supplier, $this->contract]))
            ->assertInertia(fn ($page) => $page
                ->where('contract.owner', $name)
                ->where('comments.items.0.author', $name));
    }

    private function assertPagesDontMention(string ...$texts): void
    {
        foreach ($this->pages() as $response) {
            foreach ($texts as $text) {
                $response->assertDontSee($text);
            }
        }
    }

    /**
     * @return list<TestResponse<Response>>
     */
    private function pages(): array
    {
        $this->actingAs($this->buyer);

        return [
            $this->get(route('suppliers.show', $this->supplier)),
            $this->get(route('suppliers.contracts.show', [$this->supplier, $this->contract])),
        ];
    }
}
