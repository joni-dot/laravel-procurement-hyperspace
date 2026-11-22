<?php

namespace Tests\Feature\Onboarding;

use App\Models\Company;
use App\Onboarding\OnboardingStep;
use App\Onboarding\OnboardingSteps;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnboardingStepsTest extends TestCase
{
    private OnboardingSteps $steps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->steps = new OnboardingSteps;
    }

    public function test_steps_come_in_order_whatever_order_they_were_added_in()
    {
        $this->addStep('third', 30);
        $this->addStep('first', 10);
        $this->addStep('second', 20);

        $this->assertSame(['first', 'second', 'third'], array_map(fn (OnboardingStep $step) => $step->key, $this->steps->all()));
    }

    public function test_progress_reads_each_steps_text_link_and_check_for_the_company()
    {
        $company = new Company;
        $askedAbout = null;
        $this->steps->add('add_things', 10,
            title: fn () => 'Add things',
            description: fn () => 'Things help.',
            link: fn () => '/acme/things',
            done: function (Company $checked) use (&$askedAbout): bool {
                $askedAbout = $checked;

                return true;
            });
        $this->addStep('later', 20, done: false);

        $this->assertSame([
            ['key' => 'add_things', 'title' => 'Add things', 'description' => 'Things help.', 'url' => '/acme/things', 'done' => true],
            ['key' => 'later', 'title' => 'later', 'description' => 'later', 'url' => '/later', 'done' => false],
        ], $this->steps->progressOf($company));
        $this->assertSame($company, $askedAbout);
    }

    public function test_no_steps_means_no_progress()
    {
        $this->assertSame([], $this->steps->progressOf(new Company));
    }

    public function test_a_key_can_only_be_added_once()
    {
        $this->addStep('add_things', 10);

        $this->expectException(InvalidArgumentException::class);
        $this->addStep('add_things', 20);
    }

    #[DataProvider('badKeys')]
    public function test_keys_are_lower_case_words_with_underscores(string $key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->addStep($key, 10);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badKeys(): array
    {
        return [
            'empty' => [''],
            'capitals' => ['AddThings'],
            'dash' => ['add-things'],
            'starts with a digit' => ['1_step'],
            'new line at the end' => ["add_things\n"],
        ];
    }

    public function test_the_app_has_one_list_that_every_module_adds_its_steps_to()
    {
        $steps = app(OnboardingSteps::class);

        $this->assertSame($steps, app(OnboardingSteps::class));
        $this->assertSame(
            ['add_suppliers', 'choose_certificates', 'choose_reminder_email', 'create_project', 'invite_colleagues'],
            array_map(fn (OnboardingStep $step) => $step->key, $steps->all()),
        );
    }

    private function addStep(string $key, int $order, bool $done = true): void
    {
        $this->steps->add($key, $order, fn () => $key, fn () => $key, fn () => "/{$key}", fn () => $done);
    }
}
