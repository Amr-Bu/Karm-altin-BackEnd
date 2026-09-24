<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;

class OrderCancellationService
{
    public function __construct(private OrderDataService $data, private InventoryService $inventory, private DepartmentPrintingService $printing) {}

    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->data->editable($order);
            $table = RestaurantTable::lockForUpdate()->findOrFail($order->table_id);
            if ($order->order_status === 'PREPARING') {
                $net = [];
                foreach ($order->stockTransactions()->whereIn('transaction_type', ['ORDER_CONSUMPTION', 'ORDER_RETURN'])->get() as $movement) {
                    $id = $movement->product_id;
                    if ($movement->transaction_type === 'ORDER_CONSUMPTION' && $movement->direction === 'OUT') {
                        $net[$id] = bcadd($net[$id] ?? '0', $movement->quantity, 3);
                    } elseif ($movement->transaction_type === 'ORDER_RETURN' && $movement->direction === 'IN') {
                        $net[$id] = bcsub($net[$id] ?? '0', $movement->quantity, 3);
                    }
                }
                $returns = array_filter($net, fn (string $amount) => bccomp($amount, '0', 3) > 0);
                $this->inventory->apply($order, [], $returns);
                $order->load('orderItems.product.department.printer', 'table');
                $this->printing->enqueue($order, $order->orderItems->where('status', 'ACTIVE')->map(fn ($item) => [
                    'product' => $item->product, 'type' => 'CANCELLATION', 'quantity' => $item->quantity, 'note' => $item->note,
                ])->all());
            }
            $order->update(['order_status' => 'CANCELLED', 'cancelled_at' => now()]);
            $table->update(['status' => 'AVAILABLE']);

            return $order->refresh()->load(OrderDataService::RELATIONS);
        }, 3);
    }
}
