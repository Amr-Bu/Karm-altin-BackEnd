<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProductDetailsResource extends ProductResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['ingredients' => RecipeIngredientResource::collection($this->whenLoaded('ingredients'))];
    }
}
