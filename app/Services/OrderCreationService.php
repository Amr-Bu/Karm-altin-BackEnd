<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Exceptions\TableUnavailableException;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderCreationService
{
    public function __construct(private OrderDataService $data) {}

    public function create(array $input): Order
    {
        // MySQL/MariaDB connection-scoped mutex also covers the first order of a day.
        // Acquire before the transaction and release only after commit/rollback.
        $lock = 'order-number:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 40);
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->acquired !== 1) {
            throw new InvalidOrderStateException('تعذر إنشاء رقم الطلب الآن، يرجى إعادة المحاولة');
        }
        try {
            return DB::transaction(function () use ($input) {
                $table = RestaurantTable::lockForUpdate()->findOrFail($input['table_id']);
                if ($table->status !== 'AVAILABLE' || ! $table->is_active) {
                    throw new TableUnavailableException;
                }
                $chairPrice = Setting::where('key', 'chair_price')->value('value');
                if ($chairPrice === null) {
                    throw new InvalidOrderStateException('إعداد سعر الكرسي غير موجود');
                }
                $chairPrice = $this->data->money($chairPrice);
                $now = now();
                $prefix = 'ORD-'.$now->format('Ymd').'-';
                $last = Order::where('order_number', 'like', $prefix.'%')->orderByDesc('order_number')->value('order_number');
                $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;
                if ($sequence > 9999) {
                    throw new InvalidOrderStateException('تم بلوغ الحد اليومي لأرقام الطلبات');
                }
                $order = Order::create([
                    'order_number' => $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                    'employee_id' => auth()->id(), 'table_id' => $table->id,
                    'chairs_count' => $input['chairs_count'], 'chair_price' => $chairPrice,
                    'items_subtotal' => '0.00', 'chairs_total' => '0.00', 'total_amount' => '0.00',
                    'order_status' => 'PENDING_APPROVAL', 'payment_status' => 'UNPAID',
                ]);
                foreach ($input['items'] as $entry) {
                    $product = Product::findOrFail($entry['product_id']);
                    $this->data->validateProduct($product);
                    $order->orderItems()->create([
                        'product_id' => $product->id, 'quantity' => $entry['quantity'],
                        'unit_price' => $product->price,
                        'subtotal' => $this->data->money(bcmul((string) $entry['quantity'], $product->price, 4)),
                        'status' => 'ACTIVE', 'note' => $entry['note'] ?? null,
                    ]);
                }
                $this->data->recalculate($order);
                $table->update(['status' => 'OCCUPIED']);

                return $order->load(OrderDataService::RELATIONS);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062 && str_contains($exception->getMessage(), 'one_active_order_per_table')) {
                throw new TableUnavailableException;
            }
            throw $exception;
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }
}
