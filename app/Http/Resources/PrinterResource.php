<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrinterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'printer_type' => $this->printer_type,
            'connection_type' => $this->connection_type, 'connection_config' => $this->connection_config, 'is_active' => $this->is_active];
    }
}
