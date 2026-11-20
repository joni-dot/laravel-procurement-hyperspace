<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Modules\Srm\Actions\Ratings\SaveRating;
use App\Modules\Srm\Http\Requests\Suppliers\RatingRequest;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierRating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RatingController extends Controller
{
    public function store(RatingRequest $request, Supplier $supplier, SaveRating $saveRating): RedirectResponse
    {
        $saveRating->handle(
            $supplier,
            $request->user(),
            $request->string('rated_on')->toString(),
            $request->validated('comment'),
            $request->scoresByCriterionId(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rating added.')]);

        return back();
    }

    public function destroy(Supplier $supplier, SupplierRating $rating): RedirectResponse
    {
        DB::transaction(function () use ($supplier, $rating) {
            $rating->delete();
            $supplier->refreshLatestRatingScore();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rating deleted.')]);

        return back();
    }
}
