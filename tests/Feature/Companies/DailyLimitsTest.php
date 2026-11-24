<?php

namespace Tests\Feature\Companies;

use App\Companies\DailyLimits;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\Supplier;
use App\Notifications\UserInvitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DailyLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const string INVITATIONS_FULL = 'This company has sent 200 invitations today, the most allowed in a day. Try again tomorrow.';

    private const string UPLOADS_FULL = 'This company has uploaded 1000 files today, the most allowed in a day. Try again tomorrow.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
    }

    public function test_a_company_sends_at_most_its_daily_number_of_invitations()
    {
        $admin = User::factory()->admin()->create();
        $this->useUp('invitations', DailyLimits::INVITATIONS_PER_DAY - 1);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Last One', 'email' => 'last@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'One Too Many', 'email' => 'too-many@example.com', 'role' => 'viewer'])
            ->assertSessionHasErrors(['email' => self::INVITATIONS_FULL]);

        Notification::assertSentTimes(UserInvitation::class, 1);
        $this->assertFalse(User::query()->where('email', 'too-many@example.com')->exists());

        $this->travel(1)->days();
        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Next Day', 'email' => 'next-day@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors();
    }

    public function test_sent_and_resent_invitations_count()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Ivan Invited', 'email' => 'ivan@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.users.invitation.resend', Membership::query()->where('known_email', 'ivan@example.com')->sole()))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->used('invitations'));
    }

    public function test_a_resent_invitation_is_refused_when_the_company_has_none_left()
    {
        $admin = User::factory()->admin()->create();
        $invitation = Membership::factory()->invited()->create(['company_id' => $this->company->id]);
        $this->useUp('invitations', DailyLimits::INVITATIONS_PER_DAY);

        $this->actingAs($admin)
            ->post(route('admin.users.invitation.resend', $invitation))
            ->assertSessionHasErrors(['email' => self::INVITATIONS_FULL]);

        Notification::assertNothingSent();
    }

    public function test_the_limit_is_per_company()
    {
        $this->useUp('invitations', DailyLimits::INVITATIONS_PER_DAY);
        $this->useUp('uploads', DailyLimits::UPLOADS_PER_DAY);
        $other = $this->enterCompany(Company::factory()->create());
        $supplier = Supplier::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.store', ['company' => $other->slug]), ['name' => 'Someone', 'email' => 'someone@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors();
        $this->post(route('suppliers.documents.store', $supplier), ['file' => $this->pdf()])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $supplier->documents()->count());
    }

    public function test_refused_invitations_do_not_count()
    {
        $victim = $this->company;
        $viewer = User::factory()->create();
        $this->enterCompany(Company::factory()->create());
        $outsider = User::factory()->admin()->create();

        $this->actingAs($outsider)
            ->post("/{$victim->slug}/admin/users", ['name' => 'Someone', 'email' => 'someone@example.com', 'role' => 'viewer'])
            ->assertNotFound();
        $this->enterCompany($victim);
        $this->actingAs($viewer)
            ->post(route('admin.users.store'), ['name' => 'Someone', 'email' => 'someone@example.com', 'role' => 'viewer'])
            ->assertForbidden();
        $this->post(route('admin.users.invitation.resend', Membership::factory()->invited()->create(['company_id' => $victim->id])))
            ->assertForbidden();

        $this->assertSame(0, $this->used('invitations'));
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uploadRoutes(): array
    {
        return [
            'document' => ['suppliers.documents.store'],
            'contract document' => ['suppliers.contracts.documents.store'],
            'new certificate' => ['suppliers.certificates.store'],
            'changed certificate' => ['suppliers.certificates.update'],
        ];
    }

    #[DataProvider('uploadRoutes')]
    public function test_stored_files_count(string $route)
    {
        $this->actingAs(User::factory()->buyer()->create());

        $this->upload($route, $this->pdf())->assertSessionHasNoErrors();

        $this->assertSame(1, $this->used('uploads'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    #[DataProvider('uploadRoutes')]
    public function test_uploads_are_refused_when_the_company_has_none_left(string $route)
    {
        $this->actingAs(User::factory()->buyer()->create());
        $this->useUp('uploads', DailyLimits::UPLOADS_PER_DAY);

        $this->upload($route, $this->pdf())->assertSessionHasErrors(['file' => self::UPLOADS_FULL]);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, Certificate::query()->whereNotNull('file_path')->count());
    }

    #[DataProvider('uploadRoutes')]
    public function test_refused_uploads_do_not_count(string $route)
    {
        $this->actingAs(User::factory()->create());

        $this->upload($route, $this->pdf())->assertForbidden();

        $this->assertSame(0, $this->used('uploads'));
    }

    #[DataProvider('uploadRoutes')]
    public function test_files_the_form_refuses_do_not_count(string $route)
    {
        $this->actingAs(User::factory()->buyer()->create());

        $this->upload($route, UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, $this->used('uploads'));
    }

    public function test_saving_a_certificate_without_a_file_neither_counts_nor_is_refused()
    {
        $this->actingAs(User::factory()->buyer()->create());
        $this->useUp('uploads', DailyLimits::UPLOADS_PER_DAY);

        $this->upload('suppliers.certificates.update', null)->assertSessionHasNoErrors();

        $this->assertSame(DailyLimits::UPLOADS_PER_DAY, $this->used('uploads'));
    }

    private function upload(string $route, ?UploadedFile $file): TestResponse
    {
        $supplier = Supplier::factory()->create();
        $input = array_filter(['file' => $file]);

        return match ($route) {
            'suppliers.documents.store' => $this->post(route($route, $supplier), $input),
            'suppliers.contracts.documents.store' => $this->post(route($route, [$supplier, Contract::factory()->for($supplier)->create()]), $input),
            'suppliers.certificates.store' => $this->post(route($route, $supplier), [...$this->certificateInput(), ...$input]),
            'suppliers.certificates.update' => $this->put(route($route, [$supplier, Certificate::factory()->for($supplier)->create()]), [...$this->certificateInput(), ...$input]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateInput(): array
    {
        return [
            'certificate_type_id' => CertificateType::factory()->create()->id,
            'issued_on' => today()->subYear()->toDateString(),
            'expires_on' => today()->addYear()->toDateString(),
            'reminder_days' => [],
        ];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('offer.pdf', 10, 'application/pdf');
    }

    private function useUp(string $counter, int $times): void
    {
        RateLimiter::increment($this->key($counter), 86_400, $times);
    }

    private function used(string $counter): int
    {
        return RateLimiter::attempts($this->key($counter));
    }

    private function key(string $counter): string
    {
        return "company-{$counter}|{$this->company?->id}";
    }
}
