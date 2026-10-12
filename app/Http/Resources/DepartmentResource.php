<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'is_active' => $this->is_active,
            'printer' => $this->whenLoaded('printer', fn () => ['id' => $this->printer->id, 'name' => $this->printer->name,
                'printer_type' => $this->printer->printer_type, 'is_active' => $this->printer->is_active])];
    }
}
