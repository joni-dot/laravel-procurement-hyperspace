<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Srm\Models\Supplier;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class InvalidTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_that_is_not_valid_utf8_is_a_bad_request()
    {
        $supplier = Supplier::factory()->create();
        $user = User::factory()->buyer()->create();

        $this->actingAs($user)->get(route('suppliers.index').'?search=%ff')->assertBadRequest();
        $this->actingAs($user)->get(route('suppliers.index').'?%ff=1')->assertBadRequest();
        $this->actingAs($user)->post(route('suppliers.comments.store', $supplier), ['body' => "hi\xff"])->assertBadRequest();
        $this->actingAs($user)->patch(route('profile.update'), ['name' => ["X\xff"], 'email' => $user->email])->assertBadRequest();

        $this->actingAs($user)->get(route('suppliers.index').'?search=%C3%A4')->assertOk();
    }
}
