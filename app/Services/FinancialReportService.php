<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /** Dates use the same local wall-clock storage convention as existing Eloquent writes. */
    public function period(array $filters, bool $defaultToday = false): array
    {
        if ($defaultToday && ! isset($filters['from']) && ! isset($filters['to'])) {
            $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

            return ['from' => $today, 'to' => $today];
        }

        return ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null];
    }

    public function applyPeriod(Builder $query, string $column, array $period): Builder
    {
        // MySQL DATE() sees timestamps in the same DB session timezone used on write.
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if ($period[$field] !== null) {
                $query->whereDate($column, $operator, $period[$field]);
            }
        }

        return $query;
    }

    private function paidOrders(array $period): Builder
    {
        return $this->applyPeriod(
            Order::where('payment_status', Order::PAYMENT_PAID)->where('order_status', '!=', Order::STATUS_CANCELLED),
            'paid_at', $period,
        );
    }

    public function totals(array $period): array
    {
        $sales = bcadd((string) $this->paidOrders($period)->sum('total_amount'), '0', 2);
        $expenses = bcadd((string) $this->applyPeriod(Expense::where('status', Expense::STATUS_ACTIVE), 'expense_date', $period)->sum('amount'), '0', 2);

        return ['period' => $period, 'sales' => $sales, 'expenses' => $expenses, 'net_cash_flow' => bcsub($sales, $expenses, 2)];
    }

    public function financial(array $filters): array
    {
        $period = $this->period($filters);
        $salesByDay = $this->paidOrders($period)->selectRaw('DATE(paid_at) as day, SUM(total_amount) as amount')
            ->groupByRaw('DATE(paid_at)')->pluck('amount', 'day');
        $expensesByDay = $this->applyPeriod(Expense::where('status', Expense::STATUS_ACTIVE), 'expense_date', $period)
            ->selectRaw('expense_date as day, SUM(amount) as amount')->groupBy('expense_date')->pluck('amount', 'day');
        $salesTotal = $expensesTotal = '0.00';
        $breakdown = [];
        $end = CarbonImmutable::parse($period['to'], config('app.timezone'))->startOfDay();
        for ($day = CarbonImmutable::parse($period['from'], config('app.timezone'))->startOfDay(); $day <= $end; $day = $day->addDay()) {
            $date = $day->toDateString();
            $sales = bcadd((string) ($salesByDay[$date] ?? '0'), '0', 2);
            $expenses = bcadd((string) ($expensesByDay[$date] ?? '0'), '0', 2);
            $salesTotal = bcadd($salesTotal, $sales, 2);
            $expensesTotal = bcadd($expensesTotal, $expenses, 2);
            $breakdown[] = ['date' => $date, 'sales' => $sales, 'expenses' => $expenses, 'net_cash_flow' => bcsub($sales, $expenses, 2)];
        }

        return ['period' => $period, 'sales' => $salesTotal, 'expenses' => $expensesTotal,
            'net_cash_flow' => bcsub($salesTotal, $expensesTotal, 2), 'breakdown' => $breakdown];
    }

    public function topProducts(array $period, int $limit = 10): Collection
    {
        return $this->rankedProducts($period, $limit)->toBase()->get()->map($this->formatProduct(...));
    }

    public function paginatedTopProducts(array $filters): LengthAwarePaginator
    {
        $ranked = $this->rankedProducts($this->period($filters), $filters['limit'] ?? 10);

        return DB::query()->fromSub($ranked->toBase(), 'ranked_products')
            ->orderByDesc('quantity_sold')->orderBy('product_id')
            ->paginate($filters['per_page'] ?? 20)->withQueryString()->through($this->formatProduct(...));
    }

    private function rankedProducts(array $period, int $limit): Builder
    {
        // Aggregate the saved item values; current product prices/active flags do not participate.
        $query = Order::query()->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.payment_status', Order::PAYMENT_PAID)->where('order_items.status', 'ACTIVE');
        $this->applyPeriod($query, 'orders.paid_at', $period);

        return $query->selectRaw('products.id as product_id, products.name as product_name, SUM(order_items.quantity) as quantity_sold, SUM(order_items.subtotal) as sales_amount')
            ->groupBy('products.id', 'products.name')->orderByDesc('quantity_sold')->orderBy('products.id')->limit($limit);
    }

    private function formatProduct(object $row): array
    {
        return ['product_id' => (int) $row->product_id, 'product_name' => $row->product_name,
            'quantity_sold' => bcadd((string) $row->quantity_sold, '0', 2), 'sales_amount' => bcadd((string) $row->sales_amount, '0', 2)];
    }
}
