<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderApprovalService
{
    public function __construct(private InventoryService $inventory, private DepartmentPrintingService $printing) {}

    public function approve(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($order->order_status !== 'PENDING_APPROVAL') {
                throw new InvalidOrderStateException('لا يمكن اعتماد طلب ليس بانتظار الموافقة');
            }
            $order->load('orderItems.product.ingredients.rawMaterial', 'orderItems.product.department.printer', 'table');
            $out = [];
            $changes = [];
            foreach ($order->orderItems->where('status', 'ACTIVE') as $item) {
                $this->inventory->add($out, $this->inventory->requirements($item->product, $item->quantity));
                $changes[] = ['product' => $item->product, 'type' => 'NEW_ORDER', 'quantity' => $item->quantity, 'note' => $item->note];
            }
            $this->inventory->apply($order, $out);
            $order->update(['order_status' => 'PREPARING', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            $this->printing->enqueue($order, $changes);

            return $order->refresh()->load(OrderDataService::RELATIONS);
        }, 3);
    }
}
