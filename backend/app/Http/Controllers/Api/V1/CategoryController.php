<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    /** Most-used (last 90 days) first, so quick-add chips put the likely pick up front. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $since = now()->subDays(90)->toDateString();
        $categories = Category::ownedBy($request->user())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->withCount(['transactions as usage_count' => fn ($q) => $q->where('date', '>=', $since)])
            ->orderByDesc('usage_count')->orderBy('sort')->orderBy('id')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $request->user()->categories()->create($request->validated());

        return (new CategoryResource($category->refresh()))->response()->setStatusCode(201);
    }

    public function show(Category $category): CategoryResource
    {
        Gate::authorize('view', $category);

        return new CategoryResource($category);
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        $data = $request->validated();
        if (isset($data['type']) && $data['type'] !== $category->type->value && $category->transactions()->exists()) {
            abort(response()->json(['message' => 'Category type cannot change once used.', 'code' => 'has_transactions'], 422));
        }
        $category->fill($data);
        // A renamed default stops following the built-in translation.
        if ($category->isDirty('name')) {
            $category->key = null;
        }
        $category->save();

        return new CategoryResource($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        Gate::authorize('delete', $category);
        $category->delete(); // transactions keep their amounts; category_id becomes null

        return response()->json(null, 204);
    }
}
