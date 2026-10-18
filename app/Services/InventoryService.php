<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderStateException;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private OrderDataService $data) {}

    public function current(array $filters): LengthAwarePaginator
    {
        return InventoryStock::with('product')->whereHas('product', function ($query) use ($filters) {
            $query->whereIn('item_type', ['RAW_MATERIAL', 'PURCHASED_PRODUCT']);
            foreach (['item_type', 'is_active'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where($field, $filters[$field]);
                }
            }
            if (isset($filters['search']) && $filters['search'] !== '') {
                $query->where('name', 'like', '%'.addcslashes($filters['search'], '\\%_').'%');
            }
            if (! empty($filters['low_stock_only'])) {
                $query->whereNotNull('min_stock')->whereColumn('inventory_stock.current_quantity', '<=', 'products.min_stock');
            }
        })->orderBy('product_id')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function movements(array $filters): LengthAwarePaginator
    {
        $query = StockTransaction::with(['product', 'creator'])->orderByDesc('created_at')->orderByDesc('id');
        foreach (['product_id', 'transaction_type', 'direction'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (isset($filters[$field])) {
                $query->whereDate('created_at', $operator, $filters[$field]);
            }
        }

        return $query->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function createMovement(array $input, int $adminId): array
    {
        return DB::transaction(function () use ($input, $adminId) {
            $stock = InventoryStock::where('product_id', $input['product_id'])->lockForUpdate()->first();
            $product = Product::findOrFail($input['product_id']);
            if (! $stock || ! in_array($product->item_type, ['RAW_MATERIAL', 'PURCHASED_PRODUCT'], true)) {
                throw new InvalidOrderStateException('The product must have independently managed inventory.');
            }
            $quantity = bcadd((string) $input['quantity'], '0', 3);
            if ($input['direction'] === 'OUT' && bccomp($quantity, $stock->current_quantity, 3) > 0) {
                throw new InsufficientStockException([['product_id' => $product->id, 'name' => $product->name,
                    'required' => $quantity, 'available' => $stock->current_quantity,
                    'shortfall' => bcsub($quantity, $stock->current_quantity, 3)]]);
            }
            $next = $input['direction'] === 'IN'
                ? bcadd($stock->current_quantity, $quantity, 3)
                : bcsub($stock->current_quantity, $quantity, 3);
            if (bccomp($next, '999999999.999', 3) > 0 || bccomp($next, '0', 3) < 0) {
                throw new InvalidOrderStateException('The resulting stock quantity is outside the supported range.');
            }
            $stock->update(['current_quantity' => $next]);
            $movement = StockTransaction::create([
                'product_id' => $product->id, 'transaction_type' => $input['transaction_type'],
                'direction' => $input['direction'], 'quantity' => $quantity, 'note' => $input['note'] ?? null,
                'created_by' => $adminId, 'reference_order_id' => null,
            ]);

            return [$movement->load(['product', 'creator']), $next];
        }, 3);
    }

    /** Per-item rounded requirement, so fractional edits telescope back to zero. */
    public function requirements(Product $product, string $quantity): array
    {
        if (! $product->is_stock_tracked) {
            return [];
        }
        if ($product->item_type === 'PURCHASED_PRODUCT') {
            return [$product->id => $this->data->quantity($quantity)];
        }
        if ($product->item_type !== Product::TYPE_PRODUCED_PRODUCT || $product->ingredients->isEmpty()) {
            throw new InvalidOrderStateException('وصفة المنتج غير مكتملة: '.$product->name);
        }
        $requirements = [];
        foreach ($product->ingredients as $ingredient) {
            if (! in_array($ingredient->rawMaterial->item_type, [Product::TYPE_RAW_MATERIAL, Product::TYPE_PURCHASED_PRODUCT], true)
                || bccomp($ingredient->quantity_required, '0', 3) <= 0) {
                throw new InvalidOrderStateException('مكون غير صالح في وصفة المنتج: '.$product->name);
            }
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
