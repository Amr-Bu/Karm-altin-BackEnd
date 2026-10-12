<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RestaurantTable;

class DashboardService
{
    public function __construct(private FinancialReportService $reports) {}

    public function summary(array $filters): array
    {
        $period = $this->reports->period($filters, true);
        $counts = $this->reports->applyPeriod(Order::query(), 'created_at', $period)
            ->selectRaw('order_status, COUNT(*) as total')->groupBy('order_status')->pluck('total', 'order_status');
        $orders = ['total' => (int) $counts->sum()];
        foreach (['PENDING_APPROVAL', 'APPROVED', 'PREPARING', 'READY', 'CLOSED', 'CANCELLED'] as $status) {
            $orders[strtolower($status)] = (int) ($counts[$status] ?? 0);
        }
        $tables = RestaurantTable::where('is_active', true)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->reports->totals($period) + [
            'orders' => $orders,
            'tables' => ['available' => (int) ($tables['AVAILABLE'] ?? 0), 'occupied' => (int) ($tables['OCCUPIED'] ?? 0)],
            'top_products' => $this->reports->topProducts($period, 5),
            'recent_active_orders' => Order::with(['table', 'employee'])->whereNotIn('order_status', ['CLOSED', 'CANCELLED'])
                ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(),
        ];
    }
}
