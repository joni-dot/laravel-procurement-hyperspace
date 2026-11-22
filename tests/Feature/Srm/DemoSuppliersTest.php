<?php

namespace Tests\Feature\Srm;

use App\Actions\Companies\AcceptInvitation;
use App\Actions\Companies\CreateDemoCompany;
use App\Models\User;
use App\Modules\Srm\Dashboard\SupplierAttention;
use App\Modules\Srm\Demo\DemoSuppliers;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Contact;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierRating;
use App\Modules\Srm\Rules\FinnishBusinessId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DemoSuppliersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $invitation = app(CreateDemoCompany::class)->handle('Demo Oy', 'demo-oy', 'Anna Admin', 'anna@example.com');
        app(AcceptInvitation::class)->handle($invitation);
        $this->admin = $invitation->user;
        $this->enterCompany($invitation->company);
    }

    public function test_every_tile_of_the_supplier_dashboard_has_something_to_show()
    {
        $counts = SupplierAttention::for($this->admin)->counts();

        foreach ($counts as $tile => $count) {
            $this->assertGreaterThan(0, $count, "The {$tile} tile is empty.");
        }

        $this->assertNotEmpty(SupplierAttention::for($this->admin)->upcoming());
    }

    public function test_the_suppliers_vary_like_a_real_companys()
    {
        $this->assertSame(DemoSuppliers::count(), Supplier::query()->count());

        foreach (SupplierStatus::cases() as $status) {
            $this->assertTrue(Supplier::query()->where('status', $status)->exists(), "No {$status->value} supplier.");
        }

        $this->assertTrue(Supplier::query()->whereNotNull('archived_at')->exists());
        $this->assertSame(['DE', 'EE', 'FI', 'NL', 'PL', 'SE'], Supplier::query()->distinct()->orderBy('country_code')->pluck('country_code')->all());
        $this->assertSame(0, Supplier::query()->whereDoesntHave('categories')->count());
        $this->assertSame(Supplier::query()->count(), Supplier::query()->distinct()->count('name'));
    }

    public function test_finnish_suppliers_have_valid_business_ids_and_no_id_repeats()
    {
        $finnishIds = Supplier::query()->where('country_code', 'FI')->pluck('business_id')->all();

        $this->assertNotEmpty($finnishIds);
        foreach ($finnishIds as $businessId) {
            $this->assertTrue(Validator::make(['id' => $businessId], ['id' => [new FinnishBusinessId]])->passes(), "{$businessId} is not a valid business ID.");
        }

        $this->assertSame(0, Supplier::query()->select('country_code', 'business_id')->groupBy('country_code', 'business_id')->havingRaw('count(*) > 1')->count());
    }

    public function test_every_supplier_has_one_primary_contact_and_every_address_is_one_that_never_receives_mail()
    {
        $this->assertSame(0, Supplier::query()->whereDoesntHave('contacts', fn ($contacts) => $contacts->where('is_primary', true))->count());
        $this->assertSame(Supplier::query()->count(), Contact::query()->count());

        $addresses = [...Supplier::query()->pluck('email')->all(), ...Contact::query()->pluck('email')->all()];

        foreach ($addresses as $address) {
            $this->assertMatchesRegularExpression('/^[a-z.]+@example\.(com|org)$/', $address);
        }
    }

    public function test_overall_scores_are_the_weighted_averages_of_the_scores_and_the_newest_one_is_the_suppliers()
    {
        $rating = SupplierRating::query()->with('scores.criterion')->orderBy('id')->firstOrFail();
        $weighted = $rating->scores->sum(fn ($score) => $score->score * $score->criterion->weight) / $rating->scores->sum(fn ($score) => $score->criterion->weight);

        $this->assertEqualsWithDelta($weighted, (float) $rating->overall_score, 0.005);
        $this->assertSame(0, SupplierRating::query()->where('overall_score', 0)->count());

        $latest = DB::table('supplier_ratings')->where('supplier_id', $rating->supplier_id)->orderByDesc('rated_on')->orderByDesc('id')->value('overall_score');
        $this->assertSame($latest, Supplier::query()->findOrFail($rating->supplier_id)->latest_rating_score);
    }

    public function test_the_first_admin_is_the_only_person_named_and_no_reminders_are_scheduled()
    {
        $people = [
            ...Supplier::query()->whereNotNull('responsible_user_id')->distinct()->pluck('responsible_user_id')->all(),
            ...DB::table('supplier_ratings')->distinct()->pluck('rated_by_user_id')->all(),
            ...DB::table('contracts')->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id')->all(),
        ];

        $this->assertSame([$this->admin->id], array_values(array_unique($people)));
        $this->assertSame(0, DB::table('certificate_reminders')->count());
        $this->assertSame(0, DB::table('contract_reminders')->count());
    }
}
