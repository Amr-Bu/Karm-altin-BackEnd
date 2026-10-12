<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id, 'name' => $this->product->name,
            'item_type' => $this->product->item_type, 'unit' => $this->product->unit,
            'current_quantity' => $this->current_quantity, 'min_stock' => $this->product->min_stock,
            'is_low_stock' => $this->product->min_stock !== null && bccomp($this->current_quantity, $this->product->min_stock, 3) <= 0,
            'is_active' => $this->product->is_active,
        ];
    }
}
