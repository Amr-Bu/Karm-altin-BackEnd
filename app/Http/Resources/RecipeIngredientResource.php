<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeIngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->id, 'product_id' => (int) $this->raw_material_id,
            'name' => $this->rawMaterial->name, 'item_type' => $this->rawMaterial->item_type,
            'quantity_required' => $this->quantity_required, 'unit' => $this->rawMaterial->unit];
    }
}
