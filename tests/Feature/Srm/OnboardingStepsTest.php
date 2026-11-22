<?php

namespace Tests\Feature\Srm;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Models\Setting;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Supplier;
use App\Onboarding\OnboardingSteps;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The supplier module's steps of the getting-started checklist, each ticked by the company's own data.
 */
class OnboardingStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_supplier_ticks_the_supplier_step()
    {
        $this->givenAnotherCompanyHasDoneEverything();
        $this->assertFalse($this->done('add_suppliers'));

        // Archived or not, the company has started on its suppliers.
        Supplier::factory()->archived()->create();

        $this->assertTrue($this->done('add_suppliers'));
    }

    public function test_adding_a_certificate_type_ticks_the_certificate_step()
    {
        $this->givenAnotherCompanyHasDoneEverything();
        $this->assertFalse($this->done('choose_certificates'));

        CertificateType::factory()->create();

        $this->assertTrue($this->done('choose_certificates'));
    }

    public function test_setting_the_reminder_email_ticks_the_reminder_step()
    {
        $this->givenAnotherCompanyHasDoneEverything();
        $this->assertFalse($this->done('choose_reminder_email'));

        Setting::put(SettingKey::ReminderEmail, null);
        $this->assertFalse($this->done('choose_reminder_email'));

        Setting::put(SettingKey::ReminderEmail, '');
        $this->assertFalse($this->done('choose_reminder_email'));

        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');
        $this->assertTrue($this->done('choose_reminder_email'));
    }

    public function test_each_step_links_to_where_it_is_done()
    {
        $urls = array_column(app(OnboardingSteps::class)->progressOf($this->company), 'url', 'key');

        $this->assertSame(route('suppliers.import.create', absolute: false), $urls['add_suppliers']);
        $this->assertSame(route('admin.certificate-types.index', absolute: false), $urls['choose_certificates']);
        $this->assertSame(route('admin.settings.edit', absolute: false), $urls['choose_reminder_email']);
        $this->assertStringStartsWith("/{$this->company->slug}/", $urls['add_suppliers']);
    }

    private function givenAnotherCompanyHasDoneEverything(): void
    {
        app(CurrentCompany::class)->runAs(Company::factory()->create(), function (): void {
            Supplier::factory()->create();
            CertificateType::factory()->create();
            Setting::put(SettingKey::ReminderEmail, 'other@example.com');
        });
    }

    private function done(string $key): bool
    {
        return array_column(app(OnboardingSteps::class)->progressOf($this->company), 'done', 'key')[$key];
    }
}
