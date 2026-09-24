<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Exceptions\OrderAlreadyPaidException;
use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function pay(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($order->payment_status === 'PAID') {
                throw new OrderAlreadyPaidException;
            }
            if ($order->order_status !== 'PREPARING') {
                throw new InvalidOrderStateException('لا يمكن الدفع، الطلب ليس قيد التحضير');
            }
            $table = RestaurantTable::lockForUpdate()->findOrFail($order->table_id);
            $now = now();
            $order->update(['payment_status' => 'PAID', 'order_status' => 'CLOSED', 'paid_at' => $now, 'closed_at' => $now]);
            $table->update(['status' => 'AVAILABLE']);

            return $order->refresh()->load(OrderDataService::RELATIONS);
        }, 3);
    }
}
