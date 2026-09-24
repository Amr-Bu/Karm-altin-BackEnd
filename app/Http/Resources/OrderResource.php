<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'order_number' => $this->order_number,
            'employee_id' => $this->employee_id, 'table_id' => $this->table_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee->name),
            'table_number' => $this->whenLoaded('table', fn () => $this->table->table_number),
            'chairs_count' => $this->chairs_count, 'chair_price' => $this->chair_price,
            'items_subtotal' => $this->items_subtotal, 'chairs_total' => $this->chairs_total, 'total_amount' => $this->total_amount,
            'amount_paid' => $this->amount_paid, 'change_amount' => $this->change_amount,
            'order_status' => $this->order_status, 'payment_status' => $this->payment_status,
            'approved_by' => $this->approved_by, 'created_at' => $this->created_at, 'approved_at' => $this->approved_at,
            'paid_at' => $this->paid_at, 'closed_at' => $this->closed_at, 'cancelled_at' => $this->cancelled_at,
            'items' => OrderItemResource::collection($this->whenLoaded('orderItems')),
        ];
    }
}
