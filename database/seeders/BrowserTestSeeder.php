<?php

namespace Database\Seeders;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Srm\Comments\CommentFeed;
use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Comment;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Notifications\CertificateExpiringNotifications;
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

        $admin = User::factory()->admin()->create(['name' => 'Browser Admin', 'email' => 'admin@browser-test.example']);
        $buyer = User::factory()->buyer()->create(['name' => 'Browser Buyer', 'email' => 'buyer@browser-test.example']);
        $viewer = User::factory()->create(['name' => 'Browser Viewer', 'email' => 'viewer@browser-test.example']);
        // Runs the browser test projects; a company viewer, like the viewer, as rights come from project roles.
        $manager = User::factory()->create(['name' => 'Browser Manager', 'email' => 'manager@browser-test.example']);

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

        app(BrowserTestProjects::class)->add($admin, $viewer, $manager);

        $this->addPlatformAdmin();
        $this->inviteToAnotherCompany($viewer);
        $this->addOtherCompanyOf($buyer, $company);
        $this->addSupplierNotification($admin, $supplier);
        $this->addOnboardingCompanies();
        $this->addCompanyWithoutAdmin();
    }

    /**
     * A company whose admins have all left, with two members, for the platform test that makes one of
     * them its admin (companyWithoutAdmin in test-data.ts). Its own, so the other tests see no change.
     */
    private function addCompanyWithoutAdmin(): void
    {
        $company = Company::factory()->create(['name' => 'Browser Rescue Company', 'slug' => 'browser-rescue']);

        foreach ([['Browser Rescue Member', 'rescue-member@browser-test.example'], ['Browser Rescue Other', 'rescue-other@browser-test.example']] as [$name, $email]) {
            $member = User::factory()->withoutCompany()->create(['name' => $name, 'email' => $email]);
            Membership::factory()->create(['company_id' => $company->id, 'user_id' => $member->id, 'role' => Role::Buyer]);
        }
    }

    /**
     * Two companies of their own for the onboarding tests, so importing suppliers and ticking off the
     * getting-started checklist can't change what the other tests see (importCompany and
     * onboardingCompany in test-data.ts). Each has a single admin who belongs to no other company.
     */
    private function addOnboardingCompanies(): void
    {
        foreach ([
            ['Browser Import Company', 'browser-import', 'Browser Importer', 'importer@browser-test.example'],
            ['Browser Onboarding Company', 'browser-onboarding', 'Browser Newcomer', 'newcomer@browser-test.example'],
        ] as [$companyName, $slug, $adminName, $email]) {
            $company = Company::factory()->create(['name' => $companyName, 'slug' => $slug]);
            $admin = User::factory()->withoutCompany()->create(['name' => $adminName, 'email' => $email]);
            Membership::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id, 'role' => Role::Admin]);
        }
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

    /**
     * For the notifications test: a certificate reminder in the admin's bell about the first supplier, as
     * app:send-certificate-reminders stores it (supplierNotification in test-data.ts). Only the stored
     * notification is added, so the supplier's own certificates and risk stay as the other tests expect.
     */
    private function addSupplierNotification(User $admin, Supplier $supplier): void
    {
        Notification::storeMany([[
            'user' => $admin,
            'type' => CertificateExpiringNotifications::TYPE,
            'data' => [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'certificate_id' => 0,
                'certificate_type' => 'Fire safety',
                'expires_on' => today()->addDays(20)->toDateString(),
            ],
        ]]);
    }
}
