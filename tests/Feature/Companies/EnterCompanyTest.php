<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\RatingCriterion;
use App\Modules\Srm\Models\Supplier;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class EnterCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_open_their_company()
    {
        $this->actingAs(User::factory()->create())
            ->get("/{$this->company->slug}/suppliers")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('company.slug', $this->company->slug));
    }

    public function test_people_outside_the_company_get_not_found_like_for_a_company_that_does_not_exist()
    {
        $outsider = User::factory()->withoutCompany()->create();

        $this->actingAs($outsider)->get("/{$this->company->slug}/suppliers")->assertNotFound();
        $this->actingAs($outsider)->get('/no-such-company/suppliers')->assertNotFound();
    }

    public function test_invited_and_deactivated_members_cannot_open_the_company()
    {
        $invited = User::factory()->invited()->create();
        $deactivated = User::factory()->create();
        $deactivated->memberships()->update(['deactivated_at' => now()]);

        $this->actingAs($invited)->get("/{$this->company->slug}/suppliers")->assertNotFound();
        $this->actingAs($deactivated)->get("/{$this->company->slug}/suppliers")->assertNotFound();
    }

    public function test_a_record_of_another_company_is_not_found_inside_this_one()
    {
        $user = User::factory()->create();
        $ours = $this->company;
        $other = Company::factory()->create();
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $user->id]);
        $this->enterCompany($other);
        $theirSupplier = Supplier::factory()->create();

        $this->actingAs($user)->get("/{$ours->slug}/suppliers/{$theirSupplier->id}")->assertNotFound();
        $this->actingAs($user)->get("/{$other->slug}/suppliers/{$theirSupplier->id}")->assertOk();
    }

    public function test_an_old_address_forwards_members_to_the_current_one()
    {
        $oldSlug = $this->company->slug;
        $this->company->update(['slug' => 'renamed-company']);
        $member = User::factory()->create();

        $this->actingAs($member)
            ->get("/{$oldSlug}/suppliers?status=active")
            ->assertRedirect('/renamed-company/suppliers?status=active')
            ->assertStatus(301);

        $this->actingAs(User::factory()->withoutCompany()->create())->get("/{$oldSlug}/suppliers")->assertNotFound();
        $this->actingAs($member)->post("/{$oldSlug}/suppliers")->assertNotFound();
    }

    public function test_an_address_is_never_given_to_another_company()
    {
        $oldSlug = $this->company->slug;
        $this->company->update(['slug' => 'renamed-company']);

        $this->expectException(\LogicException::class);
        Company::factory()->create(['slug' => $oldSlug]);
    }

    public function test_members_of_a_suspended_company_see_that_it_is_suspended()
    {
        $member = User::factory()->create();
        $this->company->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($member)
            ->get("/{$this->company->slug}/suppliers")
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('companies/Suspended'));
    }

    public function test_opening_a_company_remembers_it_as_the_last_one_used()
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get("/{$this->company->slug}/suppliers")->assertOk();

        $this->assertSame($this->company->id, $member->fresh()->last_company_id);
    }

    public function test_roles_belong_to_the_company()
    {
        $person = User::factory()->admin()->create();
        $other = Company::factory()->create();
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $person->id]);

        $this->actingAs($person)->get("/{$this->company->slug}/admin/categories")->assertOk();
        $this->actingAs($person)->get("/{$other->slug}/admin/categories")->assertForbidden();
    }

    public function test_each_new_company_starts_with_its_own_lists()
    {
        $other = Company::factory()->create();
        $this->enterCompany($other);

        $this->assertSame(5, ContractType::query()->count());
        $this->assertSame(100, (int) RatingCriterion::query()->sum('weight'));
    }
}
