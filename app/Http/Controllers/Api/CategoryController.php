<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::query()
            ->withCount(['posts' => fn ($query) => $query->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function show(Category $category)
    {
        return new CategoryResource($category->loadCount(['posts' => fn ($query) => $query->published()]));
    }

    public function store(Request $request)
    {
        $category = Category::create($this->validated($request));

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(Request $request, Category $category)
    {
        $category->update($this->validated($request, $category));

        return new CategoryResource($category);
    }

    public function destroy(Category $category)
    {
        if ($category->posts()->exists()) {
            return response()->json(['message' => 'Category still has posts.'], 409);
        }

        $category->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        $required = $category ? 'sometimes' : 'required';

        return $request->validate([
            'slug' => [$required, 'string', 'max:255', 'alpha_dash', Rule::unique('categories')->ignore($category)],
            'name' => [$required, 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
