<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Product;
use App\Models\ProductIngredient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeService
{
    public function add(Product $product, array $input): ProductIngredient
    {
        try {
            return DB::transaction(function () use ($product, $input) {
                $product = $this->lockParent($product);

                return $product->ingredients()->create([
                    'raw_material_id' => $input['product_id'], 'quantity_required' => $input['quantity_required'],
                ])->load('rawMaterial');
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['product_id' => ['هذا المكون موجود بالفعل في الوصفة']]);
        }
    }

    public function update(Product $product, int $ingredient, array $input): ProductIngredient
    {
        return DB::transaction(function () use ($product, $ingredient, $input) {
            $product = $this->lockParent($product);
            $row = $product->ingredients()->lockForUpdate()->findOrFail($ingredient);
            $row->update(['quantity_required' => $input['quantity_required']]);

            return $row->load('rawMaterial');
        }, 3);
    }

    public function delete(Product $product, int $ingredient): void
    {
        DB::transaction(function () use ($product, $ingredient) {
            $product = $this->lockParent($product);
            $product->ingredients()->lockForUpdate()->findOrFail($ingredient)->delete();
        }, 3);
    }

    private function lockParent(Product $product): Product
    {
        $product = Product::lockForUpdate()->findOrFail($product->id);
        if ($product->item_type !== Product::TYPE_PRODUCED_PRODUCT) {
            throw new InvalidOrderStateException('يمكن إضافة وصفة للمنتجات المصنعة فقط');
        }

        return $product;
    }
}
