<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\StockTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class ApiPaginationTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Use phpunit.mysql.xml with the authorized MySQL database.');
        }
        $this->f = OrderFixtures::create();
        $this->withToken($this->f['admin']->createToken('pagination')->plainTextToken);
        foreach (['drink', 'snack', 'extra'] as $i => $key) {
            Setting::create(['key' => 'pagination_'.$i, 'value' => 'text']);
            Expense::create(['name' => 'Pagination '.$i, 'amount' => '1.00', 'expense_date' => '2034-01-02', 'status' => 'ACTIVE', 'created_by' => $this->f['admin']->id]);
            StockTransaction::create(['product_id' => $this->f[$key]->id, 'transaction_type' => 'PURCHASE', 'direction' => 'IN', 'quantity' => '1.000', 'created_by' => $this->f['admin']->id]);
            $order = new Order;
            $order->forceFill(['order_number' => 'PAGINATION-'.bin2hex(random_bytes(8)), 'employee_id' => $this->f['employee']->id, 'table_id' => $this->f['table']->id,
                'chairs_count' => 0, 'chair_price' => '0.00', 'items_subtotal' => '3.00', 'chairs_total' => '0.00', 'total_amount' => '3.00',
                'order_status' => 'CLOSED', 'payment_status' => 'PAID', 'paid_at' => '2034-01-02 12:00:00', 'created_at' => '2034-01-01 12:00:00'])->save();
            $order->orderItems()->create(['product_id' => $this->f[$key]->id, 'quantity' => 3 - $i, 'unit_price' => '1.00', 'subtotal' => 3 - $i, 'status' => 'ACTIVE']);
            PrintJob::create(['order_id' => $order->id, 'printer_id' => $this->f['printer']->id, 'department_id' => $this->f['department']->id, 'job_type' => 'NEW_ORDER', 'status' => 'PENDING', 'attempts' => 0, 'payload' => 'Test only']);
        }
    }

    public static function endpoints(): array
    {
        return array_map(fn ($path) => [$path], ['products', 'inventory', 'inventory/movements', 'employees', 'expenses', 'orders', 'print-jobs', 'printers', 'tables', 'settings', 'reports/top-products?from=2034-01-02&to=2034-01-02']);
    }

    private function url(string $path, array $query = []): string
    {
        return '/api/'.$path.($query ? (str_contains($path, '?') ? '&' : '?').http_build_query($query) : '');
    }

    #[DataProvider('endpoints')]
    public function test_every_list_has_stable_database_pages_and_empty_pages(string $path): void
    {
        $default = $this->getJson($this->url($path))->assertOk()->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 20)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['total', 'last_page', 'from', 'to']]);
        $total = $default->json('meta.total');
        $this->assertGreaterThanOrEqual(3, $total);
        $all = $this->getJson($this->url($path, ['per_page' => 100]))->assertOk()->json('data');
        $this->assertCount($total, $all);
        $default->assertJsonCount(min(20, $total), 'data')->assertJsonPath('meta.last_page', (int) ceil($total / 20));
        $first = $this->getJson($this->url($path, ['per_page' => 1]))->assertOk()->assertJsonPath('meta.total', $total)->assertJsonPath('meta.last_page', $total)->json('data');
        $second = $this->getJson($this->url($path, ['page' => 2, 'per_page' => 1]))->assertOk()->assertJsonPath('meta.current_page', 2)->json('data');
        $this->assertSame([$all[0]], $first);
        $this->assertSame([$all[1]], $second);
        $this->getJson($this->url($path, ['page' => $total + 1, 'per_page' => 1]))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', $total);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->url($path, ['page' => 2, 'per_page' => 1]))->assertOk();
        $sql = strtolower(implode(' ', array_column(DB::getQueryLog(), 'query')));
        $this->assertStringContainsString('limit 1 offset 1', $sql);
        DB::disableQueryLog();
    }

    #[DataProvider('endpoints')]
    public function test_every_list_validates_pagination_and_preserves_security(string $path): void
    {
        foreach ([['per_page' => 0], ['per_page' => -10], ['per_page' => 101], ['per_page' => 999999], ['per_page' => 'bad'], ['per_page' => 1.5], ['page' => 0], ['page' => -1], ['page' => 'bad']] as $query) {
            $this->getJson($this->url($path, $query))->assertUnprocessable()->assertJsonValidationErrors(array_key_first($query));
        }
        $this->withHeader('Authorization', '');
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($path))->assertUnauthorized();
        $this->withToken($this->f['employee']->createToken('employee-pages')->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($path))->assertStatus(in_array($path, ['orders', 'tables'], true) ? 200 : 403);
        $this->f['employee']->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($path))->assertForbidden();
    }

    public function test_filters_and_links_survive_pagination(): void
    {
        $queries = [
            'products' => ['item_type' => ['RAW_MATERIAL', 'PURCHASED_PRODUCT'], 'is_active' => 1],
            'inventory' => ['item_type' => 'PURCHASED_PRODUCT', 'is_active' => 1],
            'inventory/movements' => ['transaction_type' => 'PURCHASE', 'direction' => 'IN'],
            'employees' => ['is_active' => 1],
            'expenses' => ['status' => 'ACTIVE', 'from' => '2034-01-02', 'to' => '2034-01-02'],
            'orders' => ['payment_status' => 'PAID', 'employee_id' => $this->f['employee']->id],
            'print-jobs' => ['status' => 'PENDING', 'printer_id' => $this->f['printer']->id],
            'reports/top-products' => ['from' => '2034-01-02', 'to' => '2034-01-02', 'limit' => 2],
        ];
        foreach ($queries as $path => $filters) {
            $response = $this->getJson($this->url($path, $filters + ['per_page' => 1]))->assertOk();
            $this->assertGreaterThan(1, $response->json('meta.total'));
            parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $next);
            foreach ($filters as $key => $value) {
                $this->assertEquals($value, $next[$key]);
            }
            $this->assertSame('1', $next['per_page']);
            $this->assertSame('2', $next['page']);
        }
        $this->getJson('/api/products?search=no-such-pagination-product&per_page=1')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        $this->getJson('/api/inventory?search=no-such-pagination-product&low_stock_only=1')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_rank_limit_is_applied_before_pagination_and_dashboard_keeps_top_five(): void
    {
        $base = 'reports/top-products?from=2034-01-02&to=2034-01-02';
        $this->getJson($this->url($base, ['limit' => 2, 'per_page' => 1]))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.product_id', $this->f['drink']->id)->assertJsonPath('data.0.quantity_sold', '3.00');
        $this->getJson($this->url($base, ['limit' => 2, 'page' => 2, 'per_page' => 1]))->assertOk()->assertJsonPath('data.0.product_id', $this->f['snack']->id);
        $this->getJson($this->url($base, ['limit' => 2, 'page' => 3, 'per_page' => 1]))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($base, ['per_page' => 1]))->assertOk()->assertJsonPath('meta.total', 3);
        $response = $this->getJson('/api/dashboard/summary?from=2034-01-02&to=2034-01-02&per_page=1')->assertOk();
        $this->assertArrayNotHasKey('meta', $response->json());
        $response->assertJsonCount(3, 'data.top_products');
    }

    public function test_exceptions_remain_unpaginated_and_grouped(): void
    {
        foreach (['categories', 'departments', 'catalog', 'products/'.$this->f['meal']->id, 'dashboard/summary', 'reports/financial?from=2034-01-02&to=2034-01-02'] as $path) {
            $response = $this->getJson($this->url($path, ['per_page' => 1]))->assertOk()->json();
            $this->assertArrayNotHasKey('meta', $response);
            $this->assertArrayNotHasKey('links', $response);
        }
    }
}
