<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'amount' => $this->amount, 'note' => $this->note,
            'expense_date' => $this->expense_date->format('Y-m-d'), 'status' => $this->status,
            'created_by' => $this->creator->only(['id', 'name']),
            'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
