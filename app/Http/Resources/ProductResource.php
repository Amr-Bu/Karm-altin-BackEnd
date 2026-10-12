<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'item_type' => $this->item_type, 'unit' => $this->unit,
            'price' => $this->price, 'min_stock' => $this->min_stock,
            'is_stock_tracked' => $this->is_stock_tracked, 'is_active' => $this->is_active,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name]),
            'department' => $this->whenLoaded('department', fn () => ['id' => $this->department->id, 'name' => $this->department->name]),
        ];
    }
}
