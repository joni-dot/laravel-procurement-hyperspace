<?php

namespace Tests\Feature\Ppr\Dashboard;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Modules\Ppr\Models\Project;
use App\Onboarding\OnboardingSteps;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The project module's step of the getting-started checklist.
 */
class OnboardingStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_project_ticks_the_project_step()
    {
        app(CurrentCompany::class)->runAs(Company::factory()->create(), fn () => Project::factory()->create());
        $this->assertFalse($this->step()['done']);

        Project::factory()->create();

        $this->assertTrue($this->step()['done']);
    }

    public function test_the_step_leads_to_the_projects_page_where_new_projects_start()
    {
        $this->assertSame(route('projects.index', absolute: false), $this->step()['url']);
    }

    /**
     * @return array{key: string, title: string, description: string, url: string, done: bool}
     */
    private function step(): array
    {
        $progress = app(OnboardingSteps::class)->progressOf($this->company);

        return collect($progress)->firstWhere('key', 'create_project');
    }
}
