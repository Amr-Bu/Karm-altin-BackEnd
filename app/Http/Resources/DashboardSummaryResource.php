<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'period' => $this->resource['period'], 'sales' => $this->resource['sales'],
            'expenses' => $this->resource['expenses'], 'net_cash_flow' => $this->resource['net_cash_flow'],
            'orders' => $this->resource['orders'], 'tables' => $this->resource['tables'],
            'top_products' => TopProductResource::collection($this->resource['top_products']),
            'recent_active_orders' => OrderSummaryResource::collection($this->resource['recent_active_orders']),
        ];
    }
}
