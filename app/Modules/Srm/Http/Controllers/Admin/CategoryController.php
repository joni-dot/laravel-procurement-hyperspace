<?php

namespace App\Modules\Srm\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Srm\Http\Requests\Admin\CategoryRequest;
use App\Modules\Srm\Models\Category;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('srm/admin/categories/Index', [
            'categories' => Category::query()
                ->select(['id', 'name'])
                ->withCount('suppliers')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        Category::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category added.')]);

        return back();
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category renamed.')]);

        return back();
    }

    /**
     * Suppliers in this category are kept; they just lose the category.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category deleted.')]);

        return back();
    }
}
