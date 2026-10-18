<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProductService
{
    public function __construct(private ProductImageService $images) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $query = Product::with(['category', 'department', 'images'])->orderBy('id');
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

        return $query->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function catalog(): Collection
    {
        return Category::where('is_active', true)->orderBy('id')->with([
            'products' => fn ($query) => $query->where('is_active', true)
                ->whereIn('item_type', [Product::TYPE_PRODUCED_PRODUCT, Product::TYPE_PURCHASED_PRODUCT])
                ->with(['department', 'images'])->orderBy('id'),
        ])->get();
    }

    public function details(Product $product): Product
    {
        return $product->load(['category', 'department', 'images', 'ingredients.rawMaterial']);
    }

    public function create(array $input): Product
    {
        return $this->saveWithImages($input, function (array $attributes) {
            $product = Product::create($this->normalize($attributes, $attributes['item_type']) + ['is_stock_tracked' => true, 'is_active' => true]);
            if ($product->item_type !== Product::TYPE_PRODUCED_PRODUCT) {
                InventoryStock::create(['product_id' => $product->id, 'current_quantity' => '0.000']);
            }

            return $product;
        });
    }

    public function update(Product $product, array $input): Product
    {
        return $this->saveWithImages($input, function (array $attributes) use ($product) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $product->update($this->normalize($attributes, $product->item_type));
            // Tracking toggles never reset balances or remove inventory/history.
            if ($product->item_type !== Product::TYPE_PRODUCED_PRODUCT) {
                InventoryStock::firstOrCreate(['product_id' => $product->id], ['current_quantity' => '0.000']);
            }

            return $product;
        });
    }

    private function saveWithImages(array $input, callable $save): Product
    {
        $uploads = $input['images'] ?? [];
        unset($input['images']);
        $paths = [];
        try {
            // File I/O must not be replayed by an automatic transaction retry.
            return DB::transaction(function () use ($input, $uploads, $save, &$paths) {
                $product = $save($input);
                $this->images->append($product, $uploads, $paths);

                return $this->details($product);
            }, $uploads === [] ? 3 : 1);
        } catch (Throwable $exception) {
            $this->images->cleanup($paths);
            throw $exception;
        }
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
