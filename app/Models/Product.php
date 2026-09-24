<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property int $id @property int|null $category_id @property int|null $department_id @property string $name @property string $item_type @property string $unit @property string|null $price @property string|null $min_stock @property bool $is_stock_tracked @property bool $is_active @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class Product extends Model
{
    public const TYPE_RAW_MATERIAL = 'RAW_MATERIAL';

    public const TYPE_PRODUCED_PRODUCT = 'PRODUCED_PRODUCT';

    public const TYPE_PURCHASED_PRODUCT = 'PURCHASED_PRODUCT';

    public const UNIT_KG = 'KG';

    public const UNIT_G = 'G';

    public const UNIT_LITER = 'LITER';

    public const UNIT_ML = 'ML';

    public const UNIT_PIECE = 'PIECE';

    protected $fillable = ['category_id', 'department_id', 'name', 'item_type', 'unit', 'price', 'min_stock', 'is_stock_tracked', 'is_active'];

    protected $casts = ['price' => 'decimal:2', 'min_stock' => 'decimal:3', 'is_stock_tracked' => 'boolean', 'is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductIngredient::class, 'product_id');
    }

    public function usedInRecipes(): HasMany
    {
        return $this->hasMany(ProductIngredient::class, 'raw_material_id');
    }

    public function inventoryStock(): HasOne
    {
        return $this->hasOne(InventoryStock::class);
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
