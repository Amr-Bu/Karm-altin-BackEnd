<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'order' => $this->order->only(['id', 'order_number']),
            'department' => $this->department?->only(['id', 'name']),
            'printer' => $this->printer->only(['id', 'name']),
            'job_type' => $this->job_type, 'status' => $this->status, 'attempts' => $this->attempts,
            'payload' => $this->payload, 'created_at' => $this->created_at, 'printed_at' => $this->printed_at,
        ];
    }
}
