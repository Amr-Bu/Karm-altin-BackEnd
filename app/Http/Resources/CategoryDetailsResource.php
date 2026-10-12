<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CategoryDetailsResource extends CategoryResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['products' => ProductResource::collection($this->whenLoaded('products'))];
    }
}
