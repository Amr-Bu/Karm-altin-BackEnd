<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddRecipeIngredientRequest;
use App\Http\Requests\UpdateRecipeIngredientRequest;
use App\Http\Resources\RecipeIngredientResource;
use App\Models\Product;
use App\Services\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RecipeIngredientController extends Controller
{
    public function __construct(private RecipeService $recipes) {}

    public function store(AddRecipeIngredientRequest $request, Product $product): JsonResponse
    {
        return (new RecipeIngredientResource($this->recipes->add($product, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateRecipeIngredientRequest $request, Product $product, int $ingredient): RecipeIngredientResource
    {
        return new RecipeIngredientResource($this->recipes->update($product, $ingredient, $request->validated()));
    }

    public function destroy(Product $product, int $ingredient): Response
    {
        $this->recipes->delete($product, $ingredient);

        return response()->noContent();
    }
}
