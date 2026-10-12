<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    private ?string $currentQuantity = null;

    public function withCurrentQuantity(string $quantity): static
    {
        $this->currentQuantity = $quantity;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'product_id' => $this->product_id,
            'product' => $this->product->only(['id', 'name', 'unit']),
            'transaction_type' => $this->transaction_type, 'direction' => $this->direction,
            'quantity' => $this->quantity, 'reference_order_id' => $this->reference_order_id, 'note' => $this->note,
            'created_by' => $this->created_by, 'creator' => $this->creator->only(['id', 'name']),
            'created_at' => $this->created_at->toISOString(),
            'current_quantity' => $this->when($this->currentQuantity !== null, $this->currentQuantity),
        ];
    }
}
