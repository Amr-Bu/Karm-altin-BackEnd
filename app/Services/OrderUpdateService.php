<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderUpdateService
{
    public function __construct(private OrderDataService $data, private InventoryService $inventory, private DepartmentPrintingService $printing) {}

    public function update(Order $order, array $input): Order
    {
        return DB::transaction(function () use ($order, $input) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->data->editable($order);
            $out = $in = $changes = [];
            if (array_key_exists('items', $input)) {
                $existing = $order->orderItems()->where('status', 'ACTIVE')->with('product.ingredients.rawMaterial', 'product.department.printer')->get()->keyBy('product_id');
                $desired = collect($input['items'])->keyBy('product_id');
                $ids = $existing->keys()->merge($desired->keys())->unique();
                foreach ($ids as $id) {
                    $item = $existing->get($id);
                    $entry = $desired->get($id);
                    $old = $item?->quantity ?? '0.00';
                    $new = bcadd((string) ($entry['quantity'] ?? '0'), '0', 2);
                    $comparison = bccomp($new, $old, 2);
                    if ($comparison === 0) {
                        if ($item && array_key_exists('note', $entry ?? [])) {
                            $item->update(['note' => $entry['note']]);
                        }

                        continue;
                    }
                    $product = $item?->product ?? Product::with('ingredients.rawMaterial', 'department.printer')->findOrFail($id);
                    if ($comparison > 0) {
                        $this->data->validateProduct($product);
                    }
                    $note = array_key_exists('note', $entry ?? []) ? $entry['note'] : $item?->note;
                    $type = $comparison > 0 ? 'ADDITION' : (bccomp($new, '0', 2) === 0 ? 'CANCELLATION' : 'MODIFICATION');
                    $delta = $comparison > 0 ? bcsub($new, $old, 2) : bcsub($old, $new, 2);
                    $changes[] = ['product' => $product, 'type' => $type, 'quantity' => $delta, 'old' => $old, 'new' => $new, 'note' => $note];
                    if ($order->order_status === 'PREPARING') {
                        $before = $this->inventory->requirements($product, $old);
                        $after = $this->inventory->requirements($product, $new);
                        foreach ($after as $stockId => $amount) {
                            $difference = bcsub($amount, $before[$stockId] ?? '0', 3);
                            if (bccomp($difference, '0', 3) > 0) {
                                $this->inventory->add($out, [$stockId => $difference]);
                            } elseif (bccomp($difference, '0', 3) < 0) {
                                $this->inventory->add($in, [$stockId => bcsub('0', $difference, 3)]);
                            }
                        }
                    }
                    if ($type === 'CANCELLATION') {
                        $item->delete();
                    } else {
                        $price = $item?->unit_price ?? $product->price;
                        $values = ['quantity' => $new, 'subtotal' => $this->data->money(bcmul($new, $price, 4)), 'note' => $note];
                        if ($item) {
                            $item->update($values);
                        } else {
                            $order->orderItems()->create($values + ['product_id' => $id, 'unit_price' => $price, 'status' => 'ACTIVE']);
                        }
                    }
                }
            }
            if ($order->order_status === 'PREPARING') {
                $this->inventory->apply($order, $out, $in);
                $this->printing->enqueue($order, $changes);
            }
            if (array_key_exists('chairs_count', $input)) {
                $order->chairs_count = $input['chairs_count'];
            }
            $this->data->recalculate($order);

            return $order->refresh()->load(OrderDataService::RELATIONS);
        }, 3);
    }
}
