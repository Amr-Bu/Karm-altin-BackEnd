<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function list(array $filters): Collection
    {
        $query = Product::with(['category', 'department'])->orderBy('id');
        if (isset($filters['item_type'])) {
            $query->whereIn('item_type', $filters['item_type']);
        }
        foreach (['category_id', 'department_id', 'is_active'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('name', 'like', '%'.addcslashes($filters['search'], '\\%_').'%');
        }

        return $query->get();
    }

    public function catalog(): Collection
    {
        return Category::where('is_active', true)->orderBy('id')->with([
            'products' => fn ($query) => $query->where('is_active', true)
                ->whereIn('item_type', [Product::TYPE_PRODUCED_PRODUCT, Product::TYPE_PURCHASED_PRODUCT])
                ->with('department')->orderBy('id'),
        ])->get();
    }

    public function details(Product $product): Product
    {
        return $product->load(['category', 'department', 'ingredients.rawMaterial']);
    }

    public function create(array $input): Product
    {
        return DB::transaction(function () use ($input) {
            $product = Product::create($this->normalize($input, $input['item_type']) + ['is_stock_tracked' => true, 'is_active' => true]);
            if ($product->item_type !== Product::TYPE_PRODUCED_PRODUCT) {
                InventoryStock::create(['product_id' => $product->id, 'current_quantity' => '0.000']);
            }

            return $this->details($product);
        }, 3);
    }

    public function update(Product $product, array $input): Product
    {
        return DB::transaction(function () use ($product, $input) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $product->update($this->normalize($input, $product->item_type));
            // Tracking toggles never reset balances or remove inventory/history.
            if ($product->item_type !== Product::TYPE_PRODUCED_PRODUCT) {
                InventoryStock::firstOrCreate(['product_id' => $product->id], ['current_quantity' => '0.000']);
            }

            return $this->details($product);
        }, 3);
    }

    public function setStatus(Product $product, bool $active): Product
    {
        return DB::transaction(function () use ($product, $active) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $product->update(['is_active' => $active]);

            return $this->details($product);
        }, 3);
    }

    private function normalize(array $input, string $type): array
    {
        if ($type === Product::TYPE_RAW_MATERIAL) {
            $input['price'] = $input['category_id'] = $input['department_id'] = null;
        } elseif ($type === Product::TYPE_PRODUCED_PRODUCT) {
            $input['min_stock'] = null;
        }

        return $input;
    }
}
