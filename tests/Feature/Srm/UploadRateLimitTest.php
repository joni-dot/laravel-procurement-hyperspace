<?php

namespace Tests\Feature\Srm;

use App\Models\User;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class UploadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const int UPLOADS_PER_MINUTE = 30;

    public function test_file_uploads_are_limited_per_person_with_a_counter_of_their_own()
    {
        $supplier = Supplier::factory()->create();
        $contract = Contract::factory()->for($supplier)->create();
        $certificate = Certificate::factory()->for($supplier)->create();
        $this->actingAs(User::factory()->buyer()->create());

        // Requests without a file are refused by the form, but still count towards the limit.
        for ($upload = 0; $upload < self::UPLOADS_PER_MINUTE; $upload++) {
            $this->post(route('suppliers.documents.store', $supplier))->assertSessionHasErrors('file');
        }

        $this->post(route('suppliers.documents.store', $supplier))->assertTooManyRequests();
        $this->post(route('suppliers.contracts.documents.store', [$supplier, $contract]))->assertTooManyRequests();
        $this->post(route('suppliers.certificates.store', $supplier))->assertTooManyRequests();
        $this->put(route('suppliers.certificates.update', [$supplier, $certificate]))->assertTooManyRequests();

        // Other limits keep counters of their own.
        $this->post(route('suppliers.comments.store', $supplier), ['body' => 'Delivery was late.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }
}
