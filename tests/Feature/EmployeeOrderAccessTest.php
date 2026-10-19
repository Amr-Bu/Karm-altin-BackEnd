<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\User;
use App\Services\NetworkPrinterTransport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeOrderAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $employee;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (! preg_match('/^karm_employee_test_[a-f0-9]{16}$/D', $database) || $database !== getenv('EMPLOYEE_TEST_DATABASE')) {
            $this->markTestSkipped('Use tests/Support/run-employee-tests.php for an isolated MySQL schema.');
        }
        $this->mock(NetworkPrinterTransport::class)->shouldNotReceive('send');
        $this->employee = User::create(['name' => 'Employee', 'username' => 'employee', 'password_hash' => bcrypt('test'), 'role' => 'EMPLOYEE', 'is_active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password_hash' => bcrypt('test'), 'role' => 'ADMIN', 'is_active' => true]);
        Setting::create(['key' => 'chair_price', 'value' => '5000']);
    }

    private function login(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('isolated-test')->plainTextToken);
    }

    private function table(array $values = []): RestaurantTable
    {
        return RestaurantTable::create($values + ['table_number' => RestaurantTable::count() + 1, 'default_chairs' => 3, 'status' => 'AVAILABLE', 'is_active' => true]);
    }

    private function order(string $status, ?User $owner = null): Order
    {
        return Order::create(['order_number' => 'TEST-'.bin2hex(random_bytes(6)), 'employee_id' => ($owner ?? $this->employee)->id,
            'table_id' => $this->table()->id, 'chairs_count' => 0, 'chair_price' => '5000.00', 'items_subtotal' => '0.00',
            'chairs_total' => '0.00', 'total_amount' => '0.00', 'order_status' => $status, 'payment_status' => $status === 'CLOSED' ? 'PAID' : 'UNPAID']);
    }

    public function test_employee_table_reads_are_paginated_and_do_not_expose_order_details(): void
    {
        $order = $this->order('PREPARING', $this->admin);
        $order->table->update(['status' => 'OCCUPIED']);
        $available = $this->table();
        $this->table(['is_active' => false]);
        $this->login($this->employee);
        $response = $this->getJson('/api/tables?per_page=1')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.status', 'OCCUPIED');
        $this->assertSame(['id', 'table_number', 'default_chairs', 'status', 'is_active'], array_keys($response->json('data.0')));
        $this->getJson('/api/tables?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $available->id);
        $this->login($this->admin);
        $this->getJson('/api/tables?per_page=1')->assertOk()->assertJsonPath('data.0.active_order.id', $order->id);
    }

    public function test_employee_cannot_manage_tables_or_settings(): void
    {
        $table = $this->table();
        $this->login($this->employee);
        $this->postJson('/api/tables', ['table_number' => 99])->assertForbidden();
        foreach ([['table_number' => 99], ['is_active' => false], ['status' => 'OCCUPIED']] as $body) {
            $this->patchJson('/api/tables/'.$table->id, $body)->assertForbidden();
        }
        $this->getJson('/api/settings')->assertForbidden();
        $this->patchJson('/api/settings/chair_price', ['value' => '1'])->assertForbidden();
        $this->assertSame('5000', Setting::where('key', 'chair_price')->value('value'));
    }

    public function test_authentication_and_active_middleware_still_apply(): void
    {
        foreach (['tables', 'catalog', 'orders'] as $path) { $this->getJson('/api/'.$path)->assertUnauthorized(); }
        $this->employee->update(['is_active' => false]);
        $this->login($this->employee);
        foreach (['tables', 'catalog', 'orders'] as $path) { $this->getJson('/api/'.$path)->assertForbidden(); }
    }

    public function test_active_scope_is_applied_before_pagination_and_cannot_be_overridden(): void
    {
        $pending = $this->order('PENDING_APPROVAL');
        $preparing = $this->order('PREPARING');
        foreach (['CLOSED', 'CANCELLED', 'READY', 'APPROVED'] as $status) { $this->order($status); }
        $this->order('PENDING_APPROVAL', $this->admin);
        $this->login($this->employee);
        $this->getJson('/api/orders?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $preparing->id);
        $this->getJson('/api/orders?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $pending->id);
        foreach (['order_status=CLOSED', 'order_status=CANCELLED', 'status=CLOSED', 'status=CANCELLED', 'employee_id='.$this->admin->id] as $filter) {
            $this->getJson('/api/orders?'.$filter)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        }
        $this->login($this->admin);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('meta.total', 7);
        $this->getJson('/api/orders?order_status=CLOSED')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_direct_details_use_consistent_not_found_and_keep_admin_history(): void
    {
        $visible = $this->order('PREPARING');
        $hidden = [$this->order('CLOSED'), $this->order('CANCELLED'), $this->order('PENDING_APPROVAL', $this->admin)];
        $this->login($this->employee);
        $this->getJson('/api/orders/'.$visible->id)->assertOk();
        foreach ($hidden as $order) { $this->getJson('/api/orders/'.$order->id)->assertNotFound(); }
        $this->getJson('/api/orders/9999999')->assertNotFound();
        $this->login($this->admin);
        foreach ($hidden as $order) { $this->getJson('/api/orders/'.$order->id)->assertOk(); }
        $this->assertSame(4, Order::count());
    }

    public function test_catalog_exposes_only_authoritative_chair_pricing(): void
    {
        Setting::create(['key' => 'private_test_value', 'value' => 'do-not-expose']);
        $this->login($this->employee);
        $this->getJson('/api/catalog')->assertOk()->assertJsonPath('meta.pricing.chair_price', '5000.00')->assertDontSee('do-not-expose');
        Setting::where('key', 'chair_price')->update(['value' => '12.345']);
        $this->getJson('/api/catalog')->assertOk()->assertJsonPath('meta.pricing.chair_price', '12.35');
        Setting::where('key', 'chair_price')->delete();
        $this->getJson('/api/catalog')->assertOk()->assertJsonPath('meta.pricing.chair_price', null);
    }

    public function test_employee_creation_preserves_money_logic_and_rejects_table_races(): void
    {
        $table = $this->table();
        $a = Product::create(['name' => 'A', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => '25000.00', 'is_active' => true, 'is_stock_tracked' => false]);
        $b = Product::create(['name' => 'B', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => '10000.00', 'is_active' => true, 'is_stock_tracked' => false]);
        $body = ['table_id' => $table->id, 'chairs_count' => 3, 'items' => [['product_id' => $a->id, 'quantity' => '2'], ['product_id' => $b->id, 'quantity' => '2']]];
        $this->login($this->employee);
        $this->postJson('/api/orders', $body)->assertCreated()->assertJsonPath('data.employee_id', $this->employee->id)
            ->assertJsonPath('data.items_subtotal', '70000.00')->assertJsonPath('data.chairs_total', '15000.00')->assertJsonPath('data.total_amount', '85000.00');
        $this->postJson('/api/orders', $body + ['status' => 'AVAILABLE'])->assertUnprocessable();
        $this->assertSame(1, Order::count());
        $body['table_id'] = $this->table(['is_active' => false])->id;
        $this->postJson('/api/orders', $body)->assertUnprocessable();
        $body['table_id'] = $this->table()->id;
        $body['chairs_count'] = 0;
        $body['items'] = [['product_id' => $a->id, 'quantity' => '0.01']];
        $a->update(['price' => '0.50']);
        $this->postJson('/api/orders', $body)->assertCreated()->assertJsonPath('data.items_subtotal', '0.01')->assertJsonPath('data.total_amount', '0.01');
    }
}
