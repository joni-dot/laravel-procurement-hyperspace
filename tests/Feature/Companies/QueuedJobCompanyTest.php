<?php

namespace Tests\Feature\Companies;

use App\Companies\CurrentCompany;
use App\Modules\Srm\Models\Category;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class QueuedJobCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_runs_in_the_company_it_was_queued_from_and_the_previous_company_comes_back()
    {
        $category = Category::factory()->create(['name' => 'Steel']);
        $queuedFrom = $this->company;

        // The sync queue runs the job straight away, inside this "request".
        RenameCategory::dispatch($category, 'Steel and metals');

        $this->assertSame('Steel and metals', $category->fresh()->name);
        $this->assertSame(RenameCategory::$ranIn, $queuedFrom->id);
        $this->assertSame($queuedFrom->id, app(CurrentCompany::class)->id());
    }

    public function test_a_job_queued_outside_any_company_sees_no_company_data()
    {
        Category::factory()->create();
        app(CurrentCompany::class)->clear();

        CountCategories::dispatch();

        $this->assertSame(0, CountCategories::$seen);
        $this->assertNull(app(CurrentCompany::class)->get());
    }
}

class RenameCategory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?int $ranIn = null;

    public function __construct(public Category $category, public string $name) {}

    public function handle(): void
    {
        self::$ranIn = app(CurrentCompany::class)->id();
        $this->category->update(['name' => $this->name]);
    }
}

class CountCategories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?int $seen = null;

    public function handle(): void
    {
        self::$seen = DB::table('categories')->count();
    }
}
