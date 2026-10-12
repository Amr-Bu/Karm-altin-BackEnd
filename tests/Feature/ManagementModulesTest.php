<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class ManagementModulesTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run php vendor/bin/phpunit -c phpunit.mysql.xml.');
        }
        $this->f = OrderFixtures::create();
        $this->category = Category::create(['name' => 'Management Test', 'is_active' => true]);
        $this->f['meal']->update(['category_id' => $this->category->id]);
        $this->f['drink']->update(['category_id' => $this->category->id, 'is_active' => false]);
        $this->withToken($this->f['admin']->createToken('management')->plainTextToken);
    }

    private function order(): int
    {
        return $this->postJson('/api/orders', ['table_id' => $this->f['table']->id, 'chairs_count' => 1,
            'items' => [['product_id' => $this->f['meal']->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
    }

    public function test_category_list_details_filters_and_status_preserve_products(): void
    {
        $inactive = Category::create(['name' => 'Inactive Management', 'is_active' => false]);
        $this->getJson('/api/categories')->assertOk()->assertJsonCount(Category::count(), 'data');
        $this->getJson('/api/categories?is_active=0&search=Inactive%20Management')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);
        $url = '/api/categories/'.$this->category->id;
        $response = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.products');
        $this->assertEqualsCanonicalizing([$this->f['meal']->id, $this->f['drink']->id], array_column($response->json('data.products'), 'id'));
        $this->assertFalse(collect($response->json('data.products'))->firstWhere('id', $this->f['drink']->id)['is_active']);
        $this->patchJson($url, ['name' => 'Renamed Management', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertTrue($this->f['meal']->fresh()->is_active);
        $this->assertSame(2, Product::where('category_id', $this->category->id)->count());
        $this->patchJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson($url, ['name' => 'Renamed Management'])->assertOk();
        $this->patchJson($url, ['name' => $inactive->name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson('/api/categories?is_active=invalid')->assertUnprocessable();
    }

    public function test_category_creation_unique_name_and_defaults(): void
    {
        $response = $this->postJson('/api/categories', ['name' => 'New Desserts'])->assertCreated()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id'), 'name' => 'New Desserts']);
        foreach ([['name' => 'New Desserts'], [], ['name' => str_repeat('x', 101)], ['name' => ['bad']], ['name' => 'Other', 'is_active' => false]] as $body) {
            $this->postJson('/api/categories', $body)->assertUnprocessable();
        }
    }

    public function test_department_assignment_detachment_status_and_history(): void
    {
        $id = $this->postJson('/api/departments', ['name' => 'No printer'])->assertCreated()->assertJsonPath('data.printer', null)->json('data.id');
        $this->postJson('/api/departments', ['name' => 'Receipt invalid', 'printer_id' => $this->f['receipt']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('printer_id');
        $assigned = $this->postJson('/api/departments', ['name' => 'Assigned', 'printer_id' => (string) $this->f['printer']->id])
            ->assertCreated()->assertJsonPath('data.printer.id', $this->f['printer']->id)->json('data.id');
        $this->postJson('/api/departments', ['name' => 'Assigned'])->assertUnprocessable();
        $this->postJson('/api/departments', ['name' => 'Missing printer', 'printer_id' => 999999999])->assertUnprocessable();
        $this->patchJson('/api/departments/'.$assigned, ['printer_id' => $this->f['receipt']->id])->assertUnprocessable();
        $other = Printer::create(['name' => 'Other', 'printer_type' => 'DEPARTMENT', 'connection_type' => 'USB', 'is_active' => false]);
        $this->patchJson('/api/departments/'.$id, ['name' => 'Changed', 'printer_id' => $other->id])->assertOk()->assertJsonPath('data.printer.id', $other->id);
        $this->patchJson('/api/departments/'.$id, ['printer_id' => null])->assertOk()->assertJsonPath('data.printer', null);
        $order = $this->order();
        $this->postJson("/api/orders/$order/approve")->assertOk();
        $jobs = PrintJob::where('order_id', $order)->get()->toArray();
        $url = '/api/departments/'.$this->f['department']->id;
        $this->patchJson($url, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertTrue($this->f['meal']->fresh()->is_active);
        $this->assertNotNull($this->f['printer']->fresh());
        $this->assertSame($jobs, PrintJob::where('order_id', $order)->get()->toArray());
        $this->getJson('/api/departments')->assertOk()->assertJsonCount(Department::count(), 'data');
        $this->patchJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    }

    public static function connections(): array
    {
        return [['NETWORK'], ['USB'], ['LOCAL_AGENT']];
    }

    #[DataProvider('connections')]
    public function test_printer_creation_with_existing_connection_types(string $type): void
    {
        $config = '{"ip":"192.168.1.20","port":9100}';
        $response = $this->postJson('/api/printers', ['name' => 'Test Printer', 'printer_type' => 'DEPARTMENT',
            'connection_type' => $type, 'connection_config' => $config])->assertCreated()->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.connection_config', $config);
        $this->assertDatabaseHas('printers', ['id' => $response->json('data.id'), 'connection_type' => $type]);
        $this->patchJson('/api/printers/'.$response->json('data.id'), ['name' => 'Renamed', 'printer_type' => 'RECEIPT',
            'connection_type' => 'USB', 'connection_config' => null])->assertOk()->assertJsonPath('data.printer_type', 'RECEIPT')
            ->assertJsonPath('data.connection_config', null);
    }

    public function test_printer_validation_linked_type_guard_and_deactivation_preserve_history(): void
    {
        $valid = ['name' => 'New', 'printer_type' => 'DEPARTMENT', 'connection_type' => 'NETWORK'];
        foreach ([['printer_type' => 'OTHER'], ['connection_type' => 'BLUETOOTH'], ['name' => str_repeat('x', 101)],
            ['connection_config' => []], ['connection_config' => str_repeat('ع', 32768)]] as $invalid) {
            $this->postJson('/api/printers', array_replace($valid, $invalid))->assertUnprocessable();
        }
        $order = $this->order();
        $this->postJson("/api/orders/$order/approve")->assertOk();
        $history = PrintJob::where('order_id', $order)->get()->toArray();
        $url = '/api/printers/'.$this->f['printer']->id;
        $this->patchJson($url, ['printer_type' => 'RECEIPT'])->assertUnprocessable()->assertJsonStructure(['message']);
        $this->patchJson($url, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame($this->f['printer']->id, $this->f['department']->fresh()->printer_id);
        $this->assertSame($history, PrintJob::where('order_id', $order)->get()->toArray());
        $this->getJson('/api/printers')->assertOk()->assertJsonCount(Printer::count(), 'data');
        $this->patchJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson('/api/printers/'.$this->f['receipt']->id, ['is_active' => false])->assertOk();
        $this->postJson("/api/orders/$order/print-receipt")->assertUnprocessable();
    }

    public function test_tables_create_update_and_status_protection(): void
    {
        $number = (int) RestaurantTable::max('table_number') + 1;
        $body = ['table_number' => (string) $number, 'default_chairs' => 6];
        $id = $this->postJson('/api/tables', $body)->assertCreated()->assertJsonPath('data.status', 'AVAILABLE')
            ->assertJsonPath('data.active_order', null)->assertJsonPath('data.table_number', $number)->json('data.id');
        $this->postJson('/api/tables', $body)->assertUnprocessable()->assertJsonValidationErrors('table_number');
        $url = '/api/tables/'.$id;
        $this->patchJson($url, ['table_number' => $number + 1, 'default_chairs' => 8, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.default_chairs', 8)->assertJsonPath('data.is_active', false);
        $this->patchJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson($url, ['table_number' => $this->f['table']->table_number])->assertUnprocessable();
        foreach (['AVAILABLE', 'OCCUPIED', null] as $status) {
            $this->postJson('/api/tables', $body + ['status' => $status])->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->patchJson($url, ['status' => $status])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        foreach ([['table_number' => '10-A'], ['default_chairs' => -1], ['default_chairs' => null], ['default_chairs' => 2.5]] as $invalid) {
            $this->patchJson($url, $invalid)->assertUnprocessable();
        }
        $this->postJson('/api/tables', ['table_number' => $number + 2])->assertUnprocessable()->assertJsonValidationErrors('default_chairs');
    }

    public function test_occupied_table_exposes_active_order_and_cannot_be_deactivated(): void
    {
        $id = $this->order();
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $tableId = $this->f['table']->id;
        $rows = collect($this->getJson('/api/tables')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['id' => $id, 'order_number' => Order::find($id)->order_number,
            'order_status' => 'PREPARING', 'payment_status' => 'UNPAID'], $rows[$tableId]['active_order']);
        $before = Order::find($id)->toArray();
        $this->patchJson('/api/tables/'.$tableId, ['is_active' => false, 'default_chairs' => 999])->assertUnprocessable();
        $this->assertSame($before, Order::find($id)->toArray());
        $this->assertSame(4, $this->f['table']->fresh()->default_chairs);
        $this->assertTrue($this->f['table']->fresh()->is_active);
        // Even a stale AVAILABLE flag must not bypass the active-order guard.
        $this->f['table']->update(['status' => 'AVAILABLE']);
        $this->patchJson('/api/tables/'.$tableId, ['is_active' => false])->assertUnprocessable();
        $this->postJson("/api/orders/$id/pay")->assertOk();
        $rows = collect($this->getJson('/api/tables')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$tableId]['active_order']);
        $this->patchJson('/api/tables/'.$tableId, ['is_active' => false])->assertOk();
    }

    public function test_cancelled_order_is_not_returned_as_active(): void
    {
        $id = $this->order();
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $rows = collect($this->getJson('/api/tables')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$this->f['table']->id]['active_order']);
    }

    public static function settingValues(): array
    {
        return [['15.00'], ['Karm Altin Rest House'], ['+963999999999'], [''], ['  keep spaces  '], [str_repeat('ع', 255)]];
    }

    #[DataProvider('settingValues')]
    public function test_generic_setting_values_are_preserved(string $value): void
    {
        // Deliberately use chair_price for every string: no per-key numeric validation.
        $this->patchJson('/api/settings/chair_price', ['value' => $value])->assertOk()
            ->assertExactJson(['data' => ['key' => 'chair_price', 'value' => $value]]);
        $setting = Setting::where('key', 'chair_price')->firstOrFail();
        $this->assertSame($value, $setting->value);
        $this->assertNotNull($setting->updated_at);
        $this->assertArrayNotHasKey('created_at', $setting->getAttributes());
    }

    public function test_settings_listing_unknown_key_and_schema_validation(): void
    {
        Setting::create(['key' => 'management_test_name', 'value' => 'Karm Altin']);
        Setting::create(['key' => 'management_test_phone', 'value' => '+963123']);
        $this->getJson('/api/settings')->assertOk()->assertJsonCount(Setting::count(), 'data')
            ->assertJsonFragment(['key' => 'management_test_phone', 'value' => '+963123']);
        $count = Setting::count();
        $this->patchJson('/api/settings/unknown_management_key', ['value' => 'text'])->assertNotFound();
        $this->assertSame($count, Setting::count());
        foreach ([[], ['value' => null], ['value' => []], ['value' => 15], ['value' => str_repeat('x', 256)]] as $body) {
            $this->patchJson('/api/settings/chair_price', $body)->assertUnprocessable()->assertJsonValidationErrors('value');
        }
        $this->patchJson('/api/settings/chair_price', ['value' => '15', 'key' => 'changed'])->assertUnprocessable();
    }

    public function test_management_relations_are_eager_loaded(): void
    {
        DB::enableQueryLog();
        foreach (['/api/categories/'.$this->category->id, '/api/departments', '/api/tables'] as $url) {
            DB::flushQueryLog();
            $this->getJson($url)->assertOk();
            $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
        }
        DB::disableQueryLog();
    }

    public function test_all_management_routes_enforce_auth_active_and_admin(): void
    {
        $requests = [
            ['GET', '/api/categories', []], ['GET', '/api/categories/'.$this->category->id, []],
            ['POST', '/api/categories', ['name' => 'Unauthorized']], ['PATCH', '/api/categories/'.$this->category->id, ['name' => 'Unauthorized']],
            ['GET', '/api/departments', []], ['POST', '/api/departments', ['name' => 'Unauthorized']],
            ['PATCH', '/api/departments/'.$this->f['department']->id, ['is_active' => false]],
            ['GET', '/api/printers', []], ['POST', '/api/printers', ['name' => 'Unauthorized', 'printer_type' => 'DEPARTMENT', 'connection_type' => 'USB']],
            ['PATCH', '/api/printers/'.$this->f['printer']->id, ['is_active' => false]],
            ['GET', '/api/tables', []], ['POST', '/api/tables', ['table_number' => 12345, 'default_chairs' => 4]],
            ['PATCH', '/api/tables/'.$this->f['table']->id, ['is_active' => false]],
            ['GET', '/api/settings', []], ['PATCH', '/api/settings/chair_price', ['value' => '42']],
        ];
        $employeeToken = $this->f['employee']->createToken('employee')->plainTextToken;
        $this->f['admin']->update(['is_active' => false]);
        $inactiveToken = $this->f['admin']->createToken('inactive')->plainTextToken;
        foreach ([[$employeeToken, 403], [$inactiveToken, 403], ['', 401]] as [$token, $status]) {
            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', $token ? 'Bearer '.$token : '');
            foreach ($requests as [$method, $url, $body]) {
                $this->json($method, $url, $body)->assertStatus($status);
            }
        }
    }

    public function test_missing_entities_return_not_found_and_no_delete_routes_exist(): void
    {
        foreach (['categories', 'departments', 'printers', 'tables'] as $path) {
            $this->patchJson('/api/'.$path.'/999999999', [])->assertNotFound();
            $this->deleteJson('/api/'.$path.'/999999999')->assertStatus(405);
        }
        $this->getJson('/api/categories/999999999')->assertNotFound();
    }
}
