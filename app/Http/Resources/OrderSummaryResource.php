<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'order_number' => $this->order_number,
            'table' => $this->table->only(['id', 'table_number']),
            'employee' => $this->employee->only(['id', 'name']),
            'order_status' => $this->order_status, 'payment_status' => $this->payment_status,
            'total_amount' => $this->total_amount, 'created_at' => $this->created_at,
        ];
    }
}
