<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderStateException;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockTransaction;

class InventoryService
{
    public function __construct(private OrderDataService $data) {}

    /** Per-item rounded requirement, so fractional edits telescope back to zero. */
    public function requirements(Product $product, string $quantity): array
    {
        if (! $product->is_stock_tracked) {
            return [];
        }
        if ($product->item_type === 'PURCHASED_PRODUCT') {
            return [$product->id => $this->data->quantity($quantity)];
        }
        $requirements = [];
        foreach ($product->ingredients as $ingredient) {
            if (! $ingredient->rawMaterial->is_stock_tracked) {
                continue;
            }
            $requirements[$ingredient->raw_material_id] = $this->data->quantity(
                bcmul($ingredient->quantity_required, $quantity, 5),
            );
        }

        return $requirements;
    }

    public function add(array &$totals, array $requirements): void
    {
        foreach ($requirements as $id => $quantity) {
            $totals[$id] = bcadd($totals[$id] ?? '0', $quantity, 3);
        }
    }

    /** Caller holds the order lock and owns the surrounding transaction. */
    public function apply(Order $order, array $out, array $in = []): void
    {
        $ids = array_unique(array_merge(array_keys($out), array_keys($in)));
        sort($ids, SORT_NUMERIC);
        $stocks = InventoryStock::whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');
        $shortages = [];
        foreach ($out as $id => $quantity) {
            $available = $stocks->get($id)?->current_quantity ?? '0.000';
            if (bccomp($quantity, $available, 3) > 0) {
                $shortages[] = ['product_id' => $id, 'name' => $products[$id]->name, 'required' => $quantity,
                    'available' => $available, 'shortfall' => bcsub($quantity, $available, 3)];
            }
        }
        if ($shortages !== []) {
            throw new InsufficientStockException($shortages);
        }
        foreach ($ids as $id) {
            $outgoing = $out[$id] ?? '0.000';
            $incoming = $in[$id] ?? '0.000';
            if (bccomp($outgoing, '0', 3) === 0 && bccomp($incoming, '0', 3) === 0) {
                continue;
            }
            $stock = $stocks->get($id);
            if (! $stock) {
                throw new InvalidOrderStateException('سجل المخزون غير موجود للمنتج: '.$products[$id]->name);
            }
            $next = bcadd(bcsub($stock->current_quantity, $outgoing, 3), $incoming, 3);
            $stock->update(['current_quantity' => $this->data->quantity($next)]);
            foreach (['OUT' => $outgoing, 'IN' => $incoming] as $direction => $quantity) {
                if (bccomp($quantity, '0', 3) > 0) {
                    StockTransaction::create([
                        'product_id' => $id, 'transaction_type' => $direction === 'OUT' ? 'ORDER_CONSUMPTION' : 'ORDER_RETURN',
                        'direction' => $direction, 'quantity' => $this->data->quantity($quantity),
                        'reference_order_id' => $order->id, 'created_by' => auth()->id(),
                        'note' => 'حركة مخزون للطلب '.$order->order_number,
                    ]);
                }
            }
        }
    }
}
