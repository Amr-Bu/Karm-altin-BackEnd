<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Exceptions\OrderAlreadyPaidException;
use App\Models\Order;
use App\Models\Product;

/** Shared decimal and order-data operations; no model events are involved. */
class OrderDataService
{
    public const RELATIONS = ['orderItems.product', 'table', 'employee'];

    public function money(string $value): string
    {
        return $this->decimal($value, 2, '99999999.99');
    }

    public function quantity(string $value): string
    {
        return $this->decimal($value, 3, '999999999.999');
    }

    private function decimal(string $value, int $scale, string $maximum): string
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw new InvalidOrderStateException('قيمة رقمية غير صالحة');
        }
        // All persisted amounts are nonnegative. Round half up without floats.
        $rounded = bcadd($value, $scale === 2 ? '0.005' : '0.0005', $scale);
        if (bccomp($rounded, $maximum, $scale) > 0) {
            throw new InvalidOrderStateException('القيمة تتجاوز الحد المسموح به');
        }

        return $rounded;
    }

    public function validateProduct(Product $product): void
    {
        if (! in_array($product->item_type, ['PRODUCED_PRODUCT', 'PURCHASED_PRODUCT'], true)) {
            throw new InvalidOrderStateException('لا يمكن إضافة المواد الخام مباشرة إلى الطلب');
        }
        if (! $product->is_active || $product->price === null || bccomp($product->price, '0', 2) < 0) {
            throw new InvalidOrderStateException('المنتج غير نشط أو ليس له سعر صالح: '.$product->name);
        }
    }

    public function editable(Order $order): void
    {
        if ($order->payment_status === 'PAID') {
            throw new OrderAlreadyPaidException;
        }
        if (! in_array($order->order_status, ['PENDING_APPROVAL', 'PREPARING'], true)) {
            throw new InvalidOrderStateException('لا يمكن تعديل أو إلغاء الطلب في حالته الحالية');
        }
    }

    public function recalculate(Order $order): void
    {
        $subtotal = '0.00';
        foreach ($order->orderItems()->where('status', 'ACTIVE')->get() as $item) {
            $subtotal = bcadd($subtotal, $item->subtotal, 2);
        }
        $chairs = $this->money(bcmul((string) $order->chairs_count, $order->chair_price, 2));
        $order->update([
            'items_subtotal' => $this->money($subtotal),
            'chairs_total' => $chairs,
            'total_amount' => $this->money(bcadd($subtotal, $chairs, 2)),
        ]);
    }
}
