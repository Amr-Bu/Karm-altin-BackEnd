<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->status === 'OCCUPIED' && $this->relationLoaded('orders') ? $this->orders->first() : null;

        return ['id' => $this->id, 'table_number' => $this->table_number, 'default_chairs' => $this->default_chairs,
            'status' => $this->status, 'is_active' => $this->is_active,
            'active_order' => $order ? ['id' => $order->id, 'order_number' => $order->order_number,
                'order_status' => $order->order_status, 'payment_status' => $order->payment_status] : null];
    }
}
