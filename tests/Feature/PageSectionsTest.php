<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pages\PageSections;
use Inertia\DeferProp;
use InvalidArgumentException;
use Tests\TestCase;

class PageSectionsTest extends TestCase
{
    private PageSections $sections;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sections = new PageSections;
        $this->user = new User;
    }

    public function test_sections_come_in_order_with_their_data_under_their_own_prop()
    {
        $this->sections->add('home', 'second', 20, 'b/Second', fn () => true, fn () => 'two');
        $this->sections->add('home', 'first', 10, 'a/First', fn () => true, fn (User $user, array $context) => $context['n']);
        $this->sections->add('elsewhere', 'other', 1, 'c/Other', fn () => true, fn () => 'no');

        $props = $this->sections->props('home', $this->user, ['n' => 1]);

        $this->assertSame([['key' => 'first', 'component' => 'a/First'], ['key' => 'second', 'component' => 'b/Second']], $props['sections']);
        $this->assertSame(1, $props['section_first']());
        $this->assertSame('two', $props['section_second']());
        $this->assertArrayNotHasKey('section_other', $props);
    }

    public function test_a_section_the_person_does_not_get_is_left_out_and_its_data_never_read()
    {
        $this->sections->add('home', 'hidden', 1, 'a/Hidden', fn () => false, fn () => $this->fail('Read data of a hidden section.'));

        $props = $this->sections->props('home', $this->user);

        $this->assertSame(['sections' => []], $props);
    }

    public function test_data_is_only_read_when_the_response_asks_for_it()
    {
        $read = false;
        $this->sections->add('home', 'lazy', 1, 'a/Lazy', fn () => true, function () use (&$read) {
            return $read = true;
        });

        $this->sections->props('home', $this->user);

        $this->assertFalse($read);
    }

    public function test_a_deferred_section_arrives_after_the_page()
    {
        $this->sections->add('home', 'slow', 1, 'a/Slow', fn () => true, fn () => 'later', deferred: true);

        $this->assertInstanceOf(DeferProp::class, $this->sections->props('home', $this->user)['section_slow']);
    }

    public function test_a_page_without_sections_has_an_empty_list()
    {
        $this->assertSame(['sections' => []], $this->sections->props('nowhere', $this->user));
    }

    public function test_keys_must_be_plain_and_unique_on_a_page()
    {
        $this->sections->add('home', 'taken', 1, 'a/Taken', fn () => true, fn () => null);
        $this->sections->add('elsewhere', 'taken', 1, 'a/Taken', fn () => true, fn () => null);

        foreach (['taken', 'Not-plain', '1st'] as $key) {
            try {
                $this->sections->add('home', $key, 1, 'a/X', fn () => true, fn () => null);
                $this->fail("Accepted section key [{$key}].");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
