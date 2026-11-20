<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Rules\CompanySlug;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A company whose address equals a path of the app itself could never be opened, or would hide
 * that path. These tests make sure the reserved list keeps up when routes or public files are added.
 */
class CompanySlugTest extends TestCase
{
    public function test_every_first_segment_of_a_route_outside_a_company_is_reserved()
    {
        $unreserved = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => Str::before($route->uri(), '/'))
            ->reject(fn (string $segment) => $segment === '' || $segment === '{company}')
            ->unique()
            ->reject(fn (string $segment) => CompanySlug::isReserved($segment))
            ->values()
            ->all();

        $this->assertSame([], $unreserved, 'Add these first path segments to CompanySlug::RESERVED.');
    }

    public function test_every_public_file_or_folder_that_looks_like_an_address_is_reserved()
    {
        $names = collect(File::files(public_path()))->map->getFilename()
            ->merge(collect(File::directories(public_path()))->map(fn (string $path) => basename($path)));

        $unreserved = $names
            ->filter(fn (string $name) => preg_match('/^'.Company::SLUG_PATTERN.'$/D', $name) === 1)
            ->reject(fn (string $name) => CompanySlug::isReserved($name))
            ->values()
            ->all();

        $this->assertSame([], $unreserved, 'Add these public files or folders to CompanySlug::RESERVED.');
    }
}
