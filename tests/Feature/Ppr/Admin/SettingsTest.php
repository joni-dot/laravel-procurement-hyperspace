<?php

namespace Tests\Feature\Ppr\Admin;

use App\Models\User;
use App\Modules\Ppr\Enums\SettingKey;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_change_settings()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.risk-warnings.edit'))->assertForbidden();
        $this->put(route('admin.risk-warnings.update'), ['risk_amber_days' => 30, 'risk_red_days' => 5])->assertForbidden();

        $this->assertDatabaseEmpty('settings');
    }

    public function test_risk_thresholds_start_at_their_defaults()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.risk-warnings.edit'))
            ->assertInertia(fn ($page) => $page
                ->component('ppr/admin/RiskWarnings')
                ->where('riskAmberDays', 7)
                ->where('riskRedDays', 0));
    }

    public function test_admin_can_change_the_risk_thresholds()
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->put(route('admin.risk-warnings.update'), ['risk_amber_days' => 30, 'risk_red_days' => -5])->assertSessionHasNoErrors();

        $this->assertSame(30, SettingKey::RiskAmberDays->savedOrDefault());
        $this->assertSame(-5, SettingKey::RiskRedDays->savedOrDefault());
        $this->get(route('admin.risk-warnings.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('riskAmberDays', 30)
                ->where('riskRedDays', -5));
    }

    public function test_red_must_start_closer_to_the_deadline_than_amber()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.risk-warnings.update'), ['risk_amber_days' => 5, 'risk_red_days' => 5])
            ->assertSessionHasErrors('risk_amber_days');

        $this->assertDatabaseEmpty('settings');
    }

    public function test_thresholds_must_be_whole_numbers_within_a_year()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.risk-warnings.update'), ['risk_amber_days' => 400, 'risk_red_days' => 'soon'])
            ->assertSessionHasErrors(['risk_amber_days', 'risk_red_days']);
    }
}
