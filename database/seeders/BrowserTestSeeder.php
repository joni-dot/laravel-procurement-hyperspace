<?php

namespace Database\Seeders;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Srm\Comments\CommentFeed;
use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Comment;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Starting data for the browser tests in tests/Browser. They read these values
 * from tests/Browser/support/test-data.ts, so change both together.
 */
class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Browser test data must never be added to production.');
        }

        // tests/Browser/support/test-data.ts expects this address.
        $company = Company::factory()->create(['name' => 'Browser Test Company', 'slug' => 'browser-test']);
        app(CurrentCompany::class)->set($company);

        User::factory()->admin()->create(['name' => 'Browser Admin', 'email' => 'admin@browser-test.example']);
        $buyer = User::factory()->buyer()->create(['name' => 'Browser Buyer', 'email' => 'buyer@browser-test.example']);
        $viewer = User::factory()->create(['name' => 'Browser Viewer', 'email' => 'viewer@browser-test.example']);

        CertificateType::factory()->required()->create(['name' => 'ISO 9001']);

        $supplier = Supplier::factory()->active()->create(['name' => 'Browser Test Supplier']);

        $contract = Contract::factory()->create([
            'supplier_id' => $supplier->id,
            'contract_type_id' => ContractType::query()->firstOrFail()->id,
            'title' => 'Confidential browser test contract',
            'value' => 123456,
        ]);

        Comment::factory()->for($supplier)->for($buyer, 'author')->create(['body' => 'Browser test supplier comment']);
        Comment::factory()->aboutContract($contract)->for($buyer, 'author')->create(['body' => 'Secret negotiation note']);

        $busySupplier = Supplier::factory()->active()->create(['name' => 'Busy Comment Supplier']);
        Comment::factory()->for($busySupplier)->for($buyer, 'author')->count(CommentFeed::PAGE_SIZE + 5)
            ->sequence(fn ($sequence) => ['body' => "Comment number {$sequence->index}", 'created_at' => now()->subMinutes($sequence->index)])
            ->create();

        $supplier->spends()->create([
            'year' => today()->year,
            'amount' => 987654,
            'currency_code' => Currency::Eur,
        ]);

        $this->addPlatformAdmin();
        $this->inviteToAnotherCompany($viewer);
        $this->addOtherCompanyOf($buyer, $company);
    }

    /**
     * A second company the buyer belongs to, for the company switcher test. The buyer keeps landing
     * in the first company, so the other tests start where they always did.
     */
    private function addOtherCompanyOf(User $buyer, Company $firstCompany): void
    {
        $otherCompany = Company::factory()->create(['name' => 'Browser Other Company', 'slug' => 'browser-other']);
        Membership::factory()->create(['company_id' => $otherCompany->id, 'user_id' => $buyer->id, 'role' => Role::Buyer]);
        $buyer->forceFill(['last_company_id' => $firstCompany->id])->save();

        app(CurrentCompany::class)->runAs(
            $otherCompany,
            fn () => Supplier::factory()->active()->create(['name' => 'Other Company Supplier']),
        );
    }

    /**
     * A pending invitation of the viewer, for the invitations test (inviteCompany in test-data.ts).
     * Until it's accepted, the viewer keeps landing in the first company.
     */
    private function inviteToAnotherCompany(User $viewer): void
    {
        $inviteCompany = Company::factory()->create(['name' => 'Browser Invite Company', 'slug' => 'browser-invite']);
        $inviter = User::factory()->withoutCompany()->create(['name' => 'Browser Inviter', 'email' => 'inviter@browser-test.example']);
        Membership::factory()->create(['company_id' => $inviteCompany->id, 'user_id' => $inviter->id, 'role' => Role::Admin]);
        Membership::factory()->invited()->create([
            'company_id' => $inviteCompany->id,
            'user_id' => $viewer->id,
            'invited_by_user_id' => $inviter->id,
        ]);
    }

    /**
     * Platform admins must use two-factor sign-in. The browser tests compute the sign-in code from
     * this secret (platformTwoFactorSecret in test-data.ts); it only ever exists in test data.
     */
    private function addPlatformAdmin(): void
    {
        User::factory()->platformAdmin()->create([
            'name' => 'Browser Platform Admin',
            'email' => 'platform@browser-test.example',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['browser-test-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
