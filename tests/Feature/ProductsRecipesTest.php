<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\StockTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class ProductsRecipesTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run php vendor/bin/phpunit -c phpunit.mysql.xml for MySQL-backed API tests.');
        }
        $this->f = OrderFixtures::create();
        $this->category = Category::create(['name' => 'Test Catalog', 'is_active' => true]);
        foreach (['meal', 'drink', 'raw', 'snack'] as $key) {
            $this->f[$key]->update(['category_id' => $this->category->id]);
        }
        $this->withToken($this->f['admin']->createToken('products-tests')->plainTextToken);
    }

    private function body(string $type = 'PRODUCED_PRODUCT'): array
    {
        return ['name' => 'Test Product', 'item_type' => $type, 'unit' => 'PIECE', 'price' => '50.00',
            'min_stock' => '5.000', 'category_id' => $this->category->id, 'department_id' => $this->f['department']->id];
    }

    private function recipeUrl(?Product $product = null): string
    {
        return '/api/products/'.($product ?? $this->f['meal'])->id.'/ingredients';
    }

    private function order(): int
    {
        return $this->postJson('/api/orders', ['table_id' => $this->f['table']->id, 'chairs_count' => 0,
            'items' => [['product_id' => $this->f['meal']->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');
    }

    public function test_admin_list_includes_all_types_inactive_products_and_relations_without_recipes(): void
    {
        $this->f['snack']->update(['is_active' => false]);
        $response = $this->getJson('/api/products')->assertOk()->assertJsonCount(Product::count(), 'data');
        $rows = collect($response->json('data'))->keyBy('id');
        foreach (['raw', 'meal', 'drink', 'snack'] as $key) {
            $this->assertArrayHasKey($this->f[$key]->id, $rows);
            $this->assertArrayNotHasKey('ingredients', $rows[$this->f[$key]->id]);
        }
        $this->assertFalse($rows[$this->f['snack']->id]['is_active']);
        $this->assertSame($this->category->id, $rows[$this->f['meal']->id]['category']['id']);
        $this->assertSame($this->f['department']->id, $rows[$this->f['meal']->id]['department']['id']);
    }

    public function test_single_multiple_and_other_filters(): void
    {
        $rows = $this->getJson('/api/products?item_type=RAW_MATERIAL')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame(['RAW_MATERIAL'], array_values(array_unique(array_column($rows, 'item_type'))));
        $rows = $this->getJson('/api/products?item_type[]=RAW_MATERIAL&item_type[]=PURCHASED_PRODUCT')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['RAW_MATERIAL', 'PURCHASED_PRODUCT'], array_unique(array_column($rows, 'item_type')));
        $this->f['drink']->update(['is_active' => false, 'name' => 'Unique 100% Cola']);
        $query = http_build_query(['category_id' => $this->category->id, 'department_id' => $this->f['department']->id, 'is_active' => 0, 'search' => '100%']);
        $this->getJson('/api/products?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->f['drink']->id);
        foreach (['item_type=INVALID', 'item_type[]=RAW_MATERIAL&item_type[]=INVALID', 'is_active=wrong', 'category_id=999999999', 'search[]=bad'] as $query) {
            $this->getJson('/api/products?'.$query)->assertUnprocessable();
        }
    }

    public function test_employee_catalog_excludes_raw_inactive_products_and_inactive_categories(): void
    {
        $this->f['snack']->update(['is_active' => false]);
        $hidden = Category::create(['name' => 'Hidden', 'is_active' => false]);
        $this->f['extra']->update(['category_id' => $hidden->id]);
        $this->withToken($this->f['employee']->createToken('catalog')->plainTextToken);
        $rows = collect($this->getJson('/api/catalog')->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($rows->has($hidden->id));
        $this->assertEqualsCanonicalizing([$this->f['meal']->id, $this->f['drink']->id], array_column($rows[$this->category->id]['products'], 'id'));
        $this->assertSame($this->f['department']->id, $rows[$this->category->id]['products'][0]['department']['id']);
    }

    public function test_lists_eager_load_relations_with_bounded_query_counts(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/products')->assertOk();
        $smallCount = count(DB::getQueryLog());
        for ($i = 0; $i < 8; $i++) {
            $category = Category::create(['name' => 'Extra '.$i, 'is_active' => true]);
            Product::create($this->body() + ['is_active' => true])->update(['category_id' => $category->id]);
        }
        DB::flushQueryLog();
        $this->getJson('/api/products')->assertOk();
        $this->assertLessThanOrEqual($smallCount, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $this->getJson('/api/catalog')->assertOk();
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public static function productTypes(): array
    {
        return [['RAW_MATERIAL'], ['PURCHASED_PRODUCT'], ['PRODUCED_PRODUCT']];
    }

    #[DataProvider('productTypes')]
    public function test_product_creation_and_inventory_normalization(string $type): void
    {
        $before = StockTransaction::count();
        $body = $this->body($type);
        if ($type === 'RAW_MATERIAL') {
            $body['price'] = 'irrelevant';
            $body['category_id'] = 99999999;
            $body['department_id'] = 'irrelevant';
        } elseif ($type === 'PRODUCED_PRODUCT') {
            $body['min_stock'] = 'irrelevant';
        }
        $response = $this->postJson('/api/products', $body)->assertCreated()->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_stock_tracked', true)->assertJsonPath('data.ingredients', []);
        $id = $response->json('data.id');
        $product = Product::findOrFail($id);
        if ($type === 'PRODUCED_PRODUCT') {
            $this->assertNull($product->inventoryStock);
            $this->assertNull($product->min_stock);
        } else {
            $this->assertSame('0.000', $product->inventoryStock->current_quantity);
        }
        if ($type === 'RAW_MATERIAL') {
            $this->assertNull($product->price);
            $this->assertNull($product->category_id);
            $this->assertNull($product->department_id);
        }
        $this->assertSame($before, StockTransaction::count());
    }

    public static function invalidProductFields(): array
    {
        return [['item_type', 'INVALID'], ['unit', 'BOX'], ['name', str_repeat('a', 151)],
            ['price', '-1'], ['price', '1.001'], ['price', null], ['price', '100000000'],
            ['category_id', 999999999], ['is_active', false], ['ingredients', []], ['current_quantity', 12]];
    }

    #[DataProvider('invalidProductFields')]
    public function test_product_validation(string $field, mixed $value): void
    {
        $count = Product::count();
        $this->postJson('/api/products', array_replace($this->body(), [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($count, Product::count());
    }

    public function test_updates_allow_unit_but_not_type_and_preserve_balances_and_history(): void
    {
        $id = $this->order();
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $history = StockTransaction::where('reference_order_id', $id)->get()->toArray();
        $orderItem = Order::find($id)->orderItems()->first()->toArray();
        $product = $this->f['meal'];
        $this->patchJson('/api/products/'.$product->id, ['name' => 'New Meal', 'price' => '90.25', 'unit' => 'KG', 'min_stock' => '10',
            'category_id' => null, 'department_id' => null, 'is_stock_tracked' => false])->assertOk()->assertJsonPath('data.unit', 'KG')
            ->assertJsonPath('data.min_stock', null)->assertJsonPath('data.category', null);
        $this->patchJson('/api/products/'.$product->id, ['item_type' => 'PURCHASED_PRODUCT'])->assertUnprocessable()->assertJsonValidationErrors('item_type');
        $this->assertSame($history, StockTransaction::where('reference_order_id', $id)->get()->toArray());
        $this->assertSame($orderItem, Order::find($id)->orderItems()->first()->toArray());
        $this->patchJson('/api/products/'.$this->f['drink']->id, ['is_stock_tracked' => false, 'min_stock' => '2.125'])->assertOk();
        $this->patchJson('/api/products/'.$this->f['drink']->id, ['is_stock_tracked' => true])->assertOk();
        $this->assertSame('100.000', $this->f['drink']->inventoryStock->current_quantity);
    }

    public function test_status_only_changes_activity_and_preserves_recipe_inventory_and_history(): void
    {
        $this->postJson($this->recipeUrl(), ['product_id' => $this->f['drink']->id, 'quantity_required' => '1'])->assertCreated();
        $id = $this->order();
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $counts = [InventoryStock::count(), StockTransaction::count(), ProductIngredient::count()];
        $this->patchJson('/api/products/'.$this->f['drink']->id.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $catalog = collect($this->getJson('/api/catalog')->assertOk()->json('data'))->firstWhere('id', $this->category->id);
        $this->assertNotContains($this->f['drink']->id, array_column($catalog['products'], 'id'));
        $this->getJson('/api/products/'.$this->f['drink']->id)->assertOk()->assertJsonPath('data.ingredients', []);
        $this->assertSame($counts, [InventoryStock::count(), StockTransaction::count(), ProductIngredient::count()]);
        $this->assertSame('98.000', $this->f['drink']->inventoryStock->current_quantity);
        $this->patchJson('/api/products/'.$this->f['drink']->id.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson('/api/products/'.$this->f['drink']->id.'/status', ['is_active' => 'invalid'])->assertUnprocessable();
    }

    public function test_recipe_details_quantities_units_duplicate_guard_and_hard_delete(): void
    {
        $url = $this->recipeUrl();
        $rawRow = $this->f['meal']->ingredients()->first();
        $this->deleteJson($url.'/'.$rawRow->id)->assertNoContent();
        $this->assertDatabaseMissing('product_ingredients', ['id' => $rawRow->id]);
        $this->postJson($url, ['product_id' => $this->f['raw']->id, 'quantity_required' => '0.250'])->assertCreated()->assertJsonPath('data.unit', 'KG');
        $response = $this->postJson($url, ['product_id' => (string) $this->f['drink']->id, 'quantity_required' => '1.000'])->assertCreated()
            ->assertJsonPath('data.product_id', $this->f['drink']->id)
            ->assertJsonPath('data.item_type', 'PURCHASED_PRODUCT')->assertJsonPath('data.unit', 'PIECE');
        $ingredientId = $response->json('data.id');
        $this->postJson($url, ['product_id' => $this->f['drink']->id, 'quantity_required' => '1'])->assertUnprocessable();
        $this->patchJson($url.'/'.$ingredientId, ['quantity_required' => '0.300'])->assertOk()->assertJsonPath('data.quantity_required', '0.300');
        $this->patchJson('/api/products/'.$this->f['drink']->id, ['unit' => 'ML'])->assertOk();
        $details = $this->getJson('/api/products/'.$this->f['meal']->id)->assertOk()->assertJsonCount(2, 'data.ingredients');
        $component = collect($details->json('data.ingredients'))->firstWhere('product_id', $this->f['drink']->id);
        $this->assertSame('ML', $component['unit']);
        $this->deleteJson($url.'/'.$ingredientId)->assertNoContent();
        $this->assertDatabaseMissing('product_ingredients', ['id' => $ingredientId]);
    }

    public static function invalidRecipeQuantities(): array
    {
        return [['0'], ['-0.1'], ['0.0001'], ['1000000000'], ['1e2'], [null]];
    }

    #[DataProvider('invalidRecipeQuantities')]
    public function test_recipe_quantity_validation(mixed $quantity): void
    {
        $this->postJson($this->recipeUrl(), ['product_id' => $this->f['drink']->id, 'quantity_required' => $quantity])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity_required');
    }

    public function test_recipe_parent_component_and_cross_parent_guards(): void
    {
        $this->postJson($this->recipeUrl($this->f['drink']), ['product_id' => $this->f['raw']->id, 'quantity_required' => 1])->assertUnprocessable();
        $this->postJson($this->recipeUrl(), ['product_id' => $this->f['meal']->id, 'quantity_required' => 1])->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->postJson($this->recipeUrl(), ['product_id' => $this->f['drink']->id, 'quantity_required' => 1, 'unit' => 'KG'])->assertUnprocessable()->assertJsonValidationErrors('unit');
        $otherId = $this->postJson('/api/products', $this->body())->assertCreated()->json('data.id');
        $row = $this->f['meal']->ingredients()->first();
        $url = '/api/products/'.$otherId.'/ingredients/'.$row->id;
        $this->patchJson($url, ['quantity_required' => 2])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->assertSame('0.250', $row->fresh()->quantity_required);
        $this->patchJson($this->recipeUrl().'/'.$row->id, ['quantity_required' => 2, 'product_id' => $this->f['drink']->id])->assertUnprocessable();
        $this->getJson('/api/products/999999999')->assertNotFound();
    }

    public function test_purchased_components_are_consumed_and_recipe_edits_do_not_rewrite_history(): void
    {
        $ingredient = $this->postJson($this->recipeUrl(), ['product_id' => $this->f['drink']->id, 'quantity_required' => '1'])->assertCreated()->json('data.id');
        $id = $this->order();
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $this->assertSame('99.500', $this->f['raw']->inventoryStock->current_quantity);
        $this->assertSame('98.000', $this->f['drink']->inventoryStock->current_quantity);
        $history = StockTransaction::where('reference_order_id', $id)->get()->toArray();
        $this->patchJson($this->recipeUrl().'/'.$ingredient, ['quantity_required' => '2'])->assertOk();
        $this->assertSame($history, StockTransaction::where('reference_order_id', $id)->get()->toArray());
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this->assertSame('100.000', $this->f['drink']->inventoryStock()->first()->current_quantity);
        $next = $this->order();
        $this->postJson("/api/orders/$next/approve")->assertOk();
        $this->assertSame('96.000', $this->f['drink']->inventoryStock()->first()->current_quantity);
        $history = StockTransaction::where('reference_order_id', $next)->get()->toArray();
        $this->deleteJson($this->recipeUrl().'/'.$ingredient)->assertNoContent();
        $this->assertSame($history, StockTransaction::where('reference_order_id', $next)->get()->toArray());
        $this->postJson("/api/orders/$next/cancel")->assertOk();
        $this->assertSame('100.000', $this->f['drink']->inventoryStock()->first()->current_quantity);
    }

    public function test_empty_tracked_recipe_cannot_silently_approve_an_order(): void
    {
        $id = $this->order();
        $row = $this->f['meal']->ingredients()->first();
        $this->deleteJson($this->recipeUrl().'/'.$row->id)->assertNoContent();
        $this->postJson("/api/orders/$id/approve")->assertUnprocessable();
        $this->assertSame('PENDING_APPROVAL', Order::find($id)->order_status);
        $this->assertSame(0, StockTransaction::where('reference_order_id', $id)->count());
    }

    public function test_database_triggers_enforce_parent_and_component_types_on_insert_and_update(): void
    {
        $row = $this->f['meal']->ingredients()->create(['raw_material_id' => $this->f['drink']->id, 'quantity_required' => 1]);
        $row->update(['quantity_required' => '2']);
        foreach ([['product_id' => $this->f['drink']->id], ['raw_material_id' => $this->f['meal']->id]] as $changes) {
            try {
                $row->update($changes);
                $this->fail('The database trigger should reject an invalid type.');
            } catch (QueryException $exception) {
                $this->assertSame('45000', $exception->errorInfo[0]);
                $row->refresh();
            }
        }
        try {
            $this->f['meal']->ingredients()->create(['raw_material_id' => $this->f['meal']->id, 'quantity_required' => 1]);
            $this->fail('Produced components must be rejected by the database.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
        }
    }

    public function test_authorization_for_all_management_endpoints_and_catalog(): void
    {
        $row = $this->f['meal']->ingredients()->first();
        $productUrl = '/api/products/'.$this->f['meal']->id;
        $requests = [['GET', '/api/products', []], ['GET', $productUrl, []], ['POST', '/api/products', $this->body()],
            ['PATCH', $productUrl, ['name' => 'Unauthorized']], ['PATCH', $productUrl.'/status', ['is_active' => false]],
            ['POST', $this->recipeUrl(), ['product_id' => $this->f['drink']->id, 'quantity_required' => 1]],
            ['PATCH', $this->recipeUrl().'/'.$row->id, ['quantity_required' => 2]], ['DELETE', $this->recipeUrl().'/'.$row->id, []]];
        $this->withToken($this->f['employee']->createToken('employee')->plainTextToken);
        foreach ($requests as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden();
        }
        $this->getJson('/api/catalog')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        foreach ([...$requests, ['GET', '/api/catalog', []]] as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertUnauthorized();
        }
        $this->f['admin']->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->f['admin']->createToken('inactive')->plainTextToken);
        $this->getJson('/api/catalog')->assertForbidden();
        $this->postJson('/api/products', $this->body())->assertForbidden();
    }
}
