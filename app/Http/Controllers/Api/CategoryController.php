<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCategoryRequest;
use App\Http\Requests\ListCategoriesRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryDetailsResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function __construct(private CategoryService $service) {}

    public function index(ListCategoriesRequest $request): AnonymousResourceCollection
    {
        return CategoryResource::collection($this->service->list($request->validated()));
    }

    public function show(Category $category): CategoryDetailsResource
    {
        return new CategoryDetailsResource($this->service->details($category));
    }

    public function store(CreateCategoryRequest $request): JsonResponse
    {
        return (new CategoryResource($this->service->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        return new CategoryResource($this->service->update($category, $request->validated()));
    }
}
