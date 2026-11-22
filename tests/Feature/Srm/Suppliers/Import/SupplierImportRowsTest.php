<?php

namespace Tests\Feature\Srm\Suppliers\Import;

use App\Models\User;
use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Enums\DeliveryTerm;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\Contact;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SupplierImportRowsTest extends TestCase
{
    use ImportsSupplierFiles;
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->buyer()->create(['email' => 'buyer@example.com']);
        $this->actingAs($this->buyer);
    }

    public function test_a_buyer_imports_suppliers_with_every_column()
    {
        $category = Category::factory()->create(['name' => 'Steel']);

        $this->import($this->csvFile([
            ['Name', 'Supplier number', 'Status', 'Country', 'Business ID', 'VAT number', 'Street address', 'Postal code', 'City',
                'Website', 'Email', 'Phone', 'Payment terms', 'Currency', 'Delivery term', 'Delivery place', 'Notes', 'Categories',
                'Responsible buyer', 'Contact name', 'Contact email', 'Contact phone'],
            ['Nordic Steel Oy', 'S-100', 'Active', 'FI', '0112038-9', 'FI01120389', 'Teollisuuskatu 1', '00510', 'Helsinki',
                'https://nordicsteel.fi', 'sales@nordicsteel.fi', '+358 9 123 4567', '30 days net', 'EUR', 'DAP', 'Helsinki warehouse', 'Reliable.', 'steel',
                'Buyer@Example.com', 'Maija Meikäläinen', 'maija@nordicsteel.fi', '+358 40 123 4567'],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('suppliers.index'));

        $supplier = Supplier::sole();
        $this->assertSame('Nordic Steel Oy', $supplier->name);
        $this->assertSame('S-100', $supplier->supplier_number);
        $this->assertSame(SupplierStatus::Active, $supplier->status);
        $this->assertSame('FI', $supplier->country_code);
        $this->assertSame('0112038-9', $supplier->business_id);
        $this->assertSame('00510', $supplier->postal_code);
        $this->assertSame(Currency::Eur, $supplier->currency_code);
        $this->assertSame(DeliveryTerm::Dap, $supplier->delivery_term);
        $this->assertSame('Reliable.', $supplier->notes);
        $this->assertSame($this->buyer->id, $supplier->responsible_user_id);
        $this->assertSame($this->company->id, $supplier->company_id);
        $this->assertSame([$category->id], $supplier->categories->pluck('id')->all());

        $contact = Contact::sole();
        $this->assertSame($supplier->id, $contact->supplier_id);
        $this->assertSame('Maija Meikäläinen', $contact->name);
        $this->assertTrue($contact->is_primary);
        $this->assertSame($this->company->id, $contact->company_id);

        $this->assertSame('1 supplier added.', session(SessionKey::FLASH_DATA)['toast']['message']);
    }

    public function test_values_are_tidied_like_the_supplier_form_does()
    {
        $this->import($this->csvFile([
            ['Name', 'Country', 'Business ID', 'VAT number', 'Website', 'Email', 'Currency', 'Delivery term'],
            ['Nordic Steel Oy', 'fi', '0112038-9', 'fi 0112 0389', 'nordicsteel.fi', 'Sales@NordicSteel.fi', 'eur', 'dap'],
            ['German Parts GmbH', 'Germany', 'hrb 12345', '', '', '', '', ''],
        ]))->assertSessionHasNoErrors();

        $finnish = Supplier::where('name', 'Nordic Steel Oy')->sole();
        $this->assertSame('FI', $finnish->country_code);
        $this->assertSame('FI01120389', $finnish->vat_number);
        $this->assertSame('https://nordicsteel.fi', $finnish->website);
        $this->assertSame('sales@nordicsteel.fi', $finnish->email);
        $this->assertSame(Currency::Eur, $finnish->currency_code);
        $this->assertSame(DeliveryTerm::Dap, $finnish->delivery_term);

        $german = Supplier::where('name', 'German Parts GmbH')->sole();
        $this->assertSame('DE', $german->country_code);
        $this->assertSame('HRB 12345', $german->business_id);
        $this->assertNull($german->vat_number);
        $this->assertNull($german->website);
    }

    public function test_the_status_may_be_a_label_or_a_value_and_defaults_like_the_form()
    {
        $this->import($this->csvFile([
            ['Name', 'Country', 'Status'],
            ['By label', 'FI', 'Blocked'],
            ['By value', 'FI', 'inactive'],
            ['No status', 'FI', ''],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(SupplierStatus::Blocked, Supplier::where('name', 'By label')->sole()->status);
        $this->assertSame(SupplierStatus::Inactive, Supplier::where('name', 'By value')->sole()->status);
        $this->assertSame(SupplierStatus::Prospect, Supplier::where('name', 'No status')->sole()->status);
    }

    public function test_rows_with_errors_are_listed_and_left_out_and_the_rest_are_imported()
    {
        $preview = $this->preview($this->csvFile([
            ['Name', 'Country', 'Business ID', 'Website', 'Phone', 'Status', 'Currency', 'Contact name', 'Contact email'],
            ['Good Oy', 'FI', '0112038-9', '', '', '', '', '', ''],
            ['', 'FI', '', '', '', '', '', '', ''],
            ['Bad check digit Oy', 'FI', '0112038-8', '', '', '', '', '', ''],
            ['Bad country', 'Atlantis', '', '', '', '', '', '', ''],
            ['Bad website', 'SE', '', 'javascript:alert(1)', '', '', '', '', ''],
            ['Bad phone', 'SE', '', '', '<script>', '', '', '', ''],
            ['Bad status', 'SE', '', '', '', 'friendly', '', '', ''],
            ['Bad currency', 'SE', '', '', '', '', 'BTC', '', ''],
            ['Bad contact', 'SE', '', '', '', '', '', 'Maija', 'not an email'],
        ]));

        $this->assertSame(['add' => 1, 'skipped' => 0, 'errors' => 8], $preview['counts']);
        $this->assertSame([
            '3: Name: The name field is required.',
            '4: Business ID: The business ID is not a valid Finnish business ID.',
            '5: Country: The selected country is invalid.',
            '6: Website: The website field must be a valid URL.',
            '7: Phone: The phone number may only contain digits, spaces and + ( ) -.',
            '8: Status: The selected status is invalid.',
            '9: Currency: The selected currency is invalid.',
            '10: Contact email: The email field must be a valid email address.',
        ], $this->problems($preview, 'error'));
        $this->assertSame(0, Supplier::count(), 'The preview saves nothing.');
    }

    public function test_suppliers_the_company_already_has_are_skipped_and_never_changed()
    {
        $byNumber = Supplier::factory()->create(['name' => 'Old Name Oy', 'supplier_number' => 'S-1', 'city' => 'Turku']);
        Supplier::factory()->create(['name' => 'Archived Ab', 'country_code' => 'SE', 'business_id' => '556677-8899', 'archived_at' => now()]);

        $preview = $this->preview($this->csvFile([
            ['Name', 'Supplier number', 'Country', 'Business ID', 'City'],
            ['New Name Oy', 'S-1', 'FI', '', 'Oulu'],
            ['Archived again', '', 'SE', '556677-8899', ''],
            ['Same ID, other country', '', 'NO', '556677-8899', ''],
        ]));

        $this->assertSame(['add' => 1, 'skipped' => 2, 'errors' => 0], $preview['counts']);
        $this->assertSame([
            '2: Supplier number: Already exists: Old Name Oy',
            '3: Business ID: Already exists: Archived Ab',
        ], $this->problems($preview, 'skipped'));

        $this->import($this->csvFile([
            ['Name', 'Supplier number', 'Country', 'City'],
            ['New Name Oy', 'S-1', 'FI', 'Oulu'],
        ]))->assertSessionHasErrors(['file' => 'No row of the file can be added.']);
        $this->assertSame('Old Name Oy', $byNumber->refresh()->name);
        $this->assertSame('Turku', $byNumber->city);
    }

    public function test_a_supplier_number_or_business_id_of_zero_is_matched_like_any_other()
    {
        Supplier::factory()->create(['name' => 'Zero Oy', 'supplier_number' => '0']);
        Supplier::factory()->create(['name' => 'Zero Ab', 'country_code' => 'SE', 'business_id' => '0']);

        $preview = $this->preview($this->csvFile([
            ['Name', 'Supplier number', 'Country', 'Business ID'],
            ['Again Oy', '0', 'FI', ''],
            ['Again Ab', '', 'SE', '0'],
        ]));

        $this->assertSame(['add' => 0, 'skipped' => 2, 'errors' => 0], $preview['counts']);
    }

    public function test_repeated_rows_of_the_file_are_skipped()
    {
        $preview = $this->preview($this->csvFile([
            ['Name', 'Supplier number', 'Country', 'Business ID'],
            ['First Oy', 'S-1', 'FI', ''],
            ['Second Ab', '', 'SE', '556677-8899'],
            ['First again', 'S-1', 'FI', ''],
            ['Second again', '', 'se', '556677-8899'],
            ['No keys Oy', '', 'FI', ''],
            ['No keys Oy', '', 'FI', ''],
        ]));

        $this->assertSame(['add' => 3, 'skipped' => 3, 'errors' => 0], $preview['counts']);
        $this->assertSame([
            '4: Supplier number: Same as row 2',
            '5: Business ID: Same as row 3',
            '7: : Same as row 6',
        ], $this->problems($preview, 'skipped'));
    }

    public function test_a_row_repeating_a_row_with_errors_is_added_instead()
    {
        $preview = $this->preview($this->csvFile([
            ['Name', 'Supplier number', 'Country'],
            ['Broken', 'S-1', 'Atlantis'],
            ['Fixed', 'S-1', 'FI'],
        ]));

        $this->assertSame(['add' => 1, 'skipped' => 0, 'errors' => 1], $preview['counts']);
        $this->assertSame('Fixed', $preview['suppliers'][0]['name']);
    }

    public function test_an_admin_creates_unknown_categories()
    {
        $this->actingAs(User::factory()->admin()->create());
        $steel = Category::factory()->create(['name' => 'Steel']);

        $preview = $this->preview($this->csvFile([
            ['Name', 'Country', 'Categories'],
            ['First Oy', 'FI', 'STEEL; Logistics ;;'],
            ['Second Oy', 'FI', 'logistics;Packaging'],
        ]));
        $this->assertSame(['Logistics', 'Packaging'], $preview['newCategories']);
        $this->assertSame([], $preview['problems']);

        $this->import($this->csvFile([
            ['Name', 'Country', 'Categories'],
            ['First Oy', 'FI', 'STEEL; Logistics ;;'],
            ['Second Oy', 'FI', 'logistics;Packaging'],
        ]))->assertSessionHasNoErrors();

        $logistics = Category::where('name', 'Logistics')->sole();
        $packaging = Category::where('name', 'Packaging')->sole();
        $this->assertSame(3, Category::count());
        $this->assertEqualsCanonicalizing([$steel->id, $logistics->id], Supplier::where('name', 'First Oy')->sole()->categories->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$logistics->id, $packaging->id], Supplier::where('name', 'Second Oy')->sole()->categories->pluck('id')->all());
    }

    public function test_a_file_naming_more_than_100_new_categories_is_refused()
    {
        $this->actingAs(User::factory()->admin()->create());
        $names = implode(';', array_map(fn (int $number) => "Category {$number}", range(1, 101)));

        $this->post(route('suppliers.import.preview'), ['file' => $this->csvFile([['Name', 'Country', 'Categories'], ['First Oy', 'FI', $names]])])
            ->assertSessionHasErrors(['file' => 'The file names more than 100 new categories. Check the Categories column, or add the categories first.']);
        $this->assertSame(0, Category::count());
    }

    public function test_a_buyer_gets_a_warning_for_unknown_categories_and_they_are_left_off()
    {
        $steel = Category::factory()->create(['name' => 'Steel']);

        $preview = $this->preview($this->csvFile([
            ['Name', 'Country', 'Categories'],
            ['First Oy', 'FI', 'Steel;Logistics'],
        ]));

        $this->assertSame([], $preview['newCategories']);
        $this->assertSame(['2: Categories: There is no category "Logistics", so it is left off. An admin can add it.'], $this->problems($preview, 'warning'));
        $this->assertSame(1, $preview['counts']['add']);

        $this->import($this->csvFile([
            ['Name', 'Country', 'Categories'],
            ['First Oy', 'FI', 'Steel;Logistics'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Category::count());
        $this->assertSame([$steel->id], Supplier::sole()->categories->pluck('id')->all());
    }

    public function test_the_responsible_buyer_must_be_an_active_buyer_or_admin_of_the_company()
    {
        User::factory()->create(['email' => 'viewer@example.com']);
        User::factory()->buyer()->deactivated()->create(['email' => 'gone@example.com']);
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $preview = $this->preview($this->csvFile([
            ['Name', 'Country', 'Responsible buyer'],
            ['Viewer Oy', 'FI', 'viewer@example.com'],
            ['Gone Oy', 'FI', 'gone@example.com'],
            ['Nobody Oy', 'FI', 'nobody@example.com'],
            ['Admin Oy', 'FI', 'ADMIN@example.com'],
        ]));

        $this->assertSame(4, $preview['counts']['add']);
        $this->assertCount(3, $this->problems($preview, 'warning'));
        $this->assertSame('2: Responsible buyer: No active buyer or admin has this email, so the responsible buyer is left empty.', $this->problems($preview, 'warning')[0]);

        $this->import($this->csvFile([
            ['Name', 'Country', 'Responsible buyer'],
            ['Viewer Oy', 'FI', 'viewer@example.com'],
            ['Admin Oy', 'FI', 'ADMIN@example.com'],
        ]))->assertSessionHasNoErrors();

        $this->assertNull(Supplier::where('name', 'Viewer Oy')->sole()->responsible_user_id);
        $this->assertSame($admin->id, Supplier::where('name', 'Admin Oy')->sole()->responsible_user_id);
    }

    public function test_a_contact_without_a_name_is_left_off_with_a_warning()
    {
        $preview = $this->preview($this->csvFile([
            ['Name', 'Country', 'Contact name', 'Contact email', 'Contact phone'],
            ['First Oy', 'FI', '', 'someone@example.com', ''],
        ]));

        $this->assertSame(['2: Contact name: The contact is left off because it has no name.'], $this->problems($preview, 'warning'));
    }

    public function test_fields_the_form_does_not_allow_cannot_be_set()
    {
        $preview = $this->preview($this->csvFile([
            ['Name', 'Country', 'archived_at', 'risk_override', 'latest_rating_score', 'company_id', 'id'],
            ['Sneaky Oy', 'FI', '2026-01-01', 'low', '5', '999', '12345'],
        ]));
        $this->assertSame(['archived_at', 'risk_override', 'latest_rating_score', 'company_id', 'id'], $preview['ignoredColumns']);

        $this->import($this->csvFile([
            ['Name', 'Country', 'archived_at', 'risk_override', 'latest_rating_score', 'company_id', 'id'],
            ['Sneaky Oy', 'FI', '2026-01-01', 'low', '5', '999', '12345'],
        ]))->assertSessionHasNoErrors();

        $supplier = Supplier::sole();
        $this->assertNull($supplier->archived_at);
        $this->assertNull($supplier->risk_override);
        $this->assertNull($supplier->latest_rating_score);
        $this->assertSame($this->company->id, $supplier->company_id);
        $this->assertNotSame(12345, $supplier->id);
    }

    public function test_the_summary_counts_skipped_rows_and_rows_with_errors()
    {
        Supplier::factory()->create(['supplier_number' => 'S-1']);

        $this->import($this->csvFile([
            ['Name', 'Supplier number', 'Country'],
            ['Old', 'S-1', 'FI'],
            ['New Oy', 'S-2', 'FI'],
            ['Newer Oy', 'S-3', 'FI'],
            ['Broken', 'S-4', 'Atlantis'],
        ]))->assertRedirect(route('suppliers.index'));

        $this->assertSame('2 suppliers added. 1 row skipped. 1 row with errors left out.', session(SessionKey::FLASH_DATA)['toast']['message']);
        $this->assertSame(['New Oy', 'Newer Oy'], Supplier::where('supplier_number', '!=', 'S-1')->orderBy('name')->pluck('name')->all());
    }

    public function test_the_preview_lists_at_most_200_problems_and_the_total()
    {
        $rows = [['Name', 'Country']];

        for ($i = 0; $i < 250; $i++) {
            $rows[] = ["Supplier {$i}", 'Atlantis'];
        }

        $preview = $this->preview($this->csvFile($rows));

        $this->assertCount(200, $preview['problems']);
        $this->assertSame(250, $preview['problemCount']);
        $this->assertSame(250, $preview['counts']['errors']);
    }

    public function test_reading_and_checking_take_the_same_queries_however_many_rows_the_file_has()
    {
        Category::factory()->create(['name' => 'Steel']);

        $queriesFor = function (int $rowCount): int {
            $rows = [['Name', 'Supplier number', 'Country', 'Business ID', 'Categories', 'Responsible buyer']];

            for ($i = 0; $i < $rowCount; $i++) {
                $rows[] = ["Supplier {$i}", "S-{$i}", 'SE', "5566-{$i}", 'Steel;Unknown', 'buyer@example.com'];
            }

            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->preview($this->csvFile($rows));
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queriesFor(1); // The first request of a test makes a few one-time queries of its own.

        $this->assertSame($queriesFor(3), $queriesFor(300));
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function bulkInsertedModels(): array
    {
        return ['suppliers' => [Supplier::class], 'contacts' => [Contact::class]];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('bulkInsertedModels')]
    public function test_no_model_events_are_missed_by_the_imports_bulk_inserts(string $model)
    {
        new $model; // Registers the model's own listeners.

        $this->assertCount(1, Event::getRawListeners()["eloquent.creating: {$model}"] ?? [],
            'Only BelongsToCompany may listen: ImportSuppliers sets the company itself.');

        foreach (['created', 'saving', 'saved'] as $event) {
            $this->assertFalse(
                Event::hasListeners("eloquent.{$event}: {$model}"),
                "ImportSuppliers inserts without the model, but something now listens to {$event}. Do the same in ImportSuppliers.",
            );
        }
    }

    public function test_saving_takes_the_same_queries_however_many_rows_the_file_has()
    {
        Category::factory()->create(['name' => 'Steel']);

        $queriesFor = function (int $rowCount, int $offset): int {
            $rows = [['Name', 'Supplier number', 'Country', 'Categories', 'Contact name']];

            for ($i = $offset; $i < $offset + $rowCount; $i++) {
                $rows[] = ["Supplier {$i}", "S-{$i}", 'SE', 'Steel', 'Contact'];
            }

            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->import($this->csvFile($rows))->assertSessionHasNoErrors();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queriesFor(1, 5000); // The first request of a test makes a few one-time queries of its own.
        $this->assertSame($queriesFor(3, 0), $queriesFor(300, 1000));
    }
}
