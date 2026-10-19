<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Expense;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\StockTransaction;
use App\Services\NetworkPrinterTransport;
use App\Services\PrintJobService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class OrdersReportsTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Use phpunit.mysql.xml.');
        }
        $this->f = OrderFixtures::create();
        $this->loginAs('admin');
    }

    private function loginAs(string $key): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->f[$key]->createToken('reports-test')->plainTextToken);
    }

    private function order(array $attributes = [], bool $item = true): Order
    {
        $table = RestaurantTable::create(['table_number' => RestaurantTable::max('table_number') + 1,
            'default_chairs' => 0, 'status' => 'AVAILABLE', 'is_active' => true]);
        $order = new Order;
        $order->forceFill(array_replace([
            'order_number' => 'REPORT-'.bin2hex(random_bytes(8)), 'employee_id' => $this->f['employee']->id, 'table_id' => $table->id,
            'chairs_count' => 0, 'chair_price' => '15.00', 'items_subtotal' => '3.00', 'chairs_total' => '0.00',
            'total_amount' => '3.00', 'order_status' => 'CLOSED', 'payment_status' => 'PAID',
            'created_at' => '2036-04-01 12:00:00', 'paid_at' => '2036-04-02 12:00:00',
        ], $attributes))->save();
        if ($item) {
            $order->orderItems()->create(['product_id' => $this->f['drink']->id, 'quantity' => '1.25',
                'unit_price' => '2.40', 'subtotal' => '3.00', 'note' => 'Saved note', 'status' => 'ACTIVE']);
        }

        return $order;
    }

    private function job(array $attributes = []): PrintJob
    {
        $job = new PrintJob;
        $job->forceFill(array_replace(['order_id' => $this->order()->id, 'printer_id' => $this->f['receipt']->id,
            'department_id' => null, 'job_type' => 'RECEIPT', 'status' => 'FAILED', 'attempts' => 2,
            'payload' => "Saved snapshot\nDo not regenerate\n", 'created_at' => '2036-04-02 12:00:00'], $attributes))->save();

        return $job;
    }

    private function expense(string $date, string $amount, string $status = 'ACTIVE'): Expense
    {
        return Expense::create(['created_by' => $this->f['admin']->id, 'name' => 'Report expense',
            'amount' => $amount, 'expense_date' => $date, 'status' => $status]);
    }

    public function test_order_ownership_admin_access_and_paginated_summaries(): void
    {
        $mine = $this->order(['order_status' => 'PENDING_APPROVAL', 'payment_status' => 'UNPAID']);
        $other = $this->order(['employee_id' => $this->f['admin']->id, 'created_at' => '2036-04-03 12:00:00']);
        $response = $this->getJson('/api/orders?from=2036-04-01&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.id', $other->id);
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
        $this->assertArrayNotHasKey('password_hash', $response->json('data.0.employee'));
        $this->getJson('/api/orders/'.$mine->id)->assertOk();
        $this->getJson('/api/orders/'.$other->id)->assertOk();
        $this->loginAs('employee');
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/orders?employee_id='.$this->f['admin']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/orders/'.$other->id)->assertNotFound();
        $this->getJson('/api/orders/'.$mine->id)->assertOk();
    }

    public function test_order_filters_individually_combined_and_creation_date_semantics(): void
    {
        $target = $this->order(['order_status' => 'PREPARING', 'payment_status' => 'UNPAID', 'paid_at' => null,
            'order_number' => 'REPORT-NEEDLE', 'created_at' => '2036-04-02 23:59:59']);
        $this->order(['employee_id' => $this->f['admin']->id, 'created_at' => '2036-04-01 23:59:59']);
        foreach (['order_status=PREPARING', 'payment_status=UNPAID', 'employee_id='.$this->f['employee']->id,
            'table_id='.$target->table_id, 'from=2036-04-02', 'to=2036-04-02&from=2036-04-02', 'search=NEEDLE',
            'order_status=PREPARING&payment_status=UNPAID&search=NEEDLE&from=2036-04-02&to=2036-04-02&table_id='.$target->table_id] as $filter) {
            $rows = $this->getJson('/api/orders?from=2036-04-01&'.$filter)->assertOk()->json('data');
            $this->assertSame([$target->id], array_column($rows, 'id'));
        }
        $this->getJson('/api/orders?from=2036-04-03')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/orders?to=2036-04-01&employee_id='.$this->f['employee']->id)->assertOk()->assertJsonCount(0, 'data');
        foreach (['order_status=WRONG', 'payment_status=WRONG', 'employee_id=999999999', 'table_id=999999999',
            'from=bad', 'from=2036-04-02&to=2036-04-01', 'per_page=101', 'page=0'] as $filter) {
            $this->getJson('/api/orders?'.$filter)->assertUnprocessable();
        }
    }

    public function test_order_details_preserve_historical_prices_and_include_cancelled_items(): void
    {
        $order = $this->order(['approved_by' => $this->f['admin']->id]);
        $order->orderItems()->create(['product_id' => $this->f['snack']->id, 'quantity' => '2.00',
            'unit_price' => '7.25', 'subtotal' => '14.50', 'status' => 'CANCELLED', 'note' => 'Cancelled history']);
        $this->f['drink']->update(['price' => '900.00', 'is_active' => false]);
        $response = $this->getJson('/api/orders/'.$order->id)->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.total_amount', '3.00')->assertJsonPath('data.approved_by', $this->f['admin']->id)
            ->assertJsonStructure(['data' => ['employee', 'table', 'chairs_count', 'chair_price', 'items_subtotal',
                'chairs_total', 'amount_paid', 'change_amount', 'approved_at', 'paid_at', 'closed_at', 'cancelled_at']]);
        $rows = collect($response->json('data.items'))->keyBy('product_id');
        $this->assertSame('2.40', $rows[$this->f['drink']->id]['unit_price']);
        $this->assertSame('3.00', $rows[$this->f['drink']->id]['subtotal']);
        $this->assertSame('CANCELLED', $rows[$this->f['snack']->id]['status']);
        $this->assertSame($this->f['drink']->id, $rows[$this->f['drink']->id]['product']['id']);
        $this->assertSame('Saved note', $rows[$this->f['drink']->id]['note']);
        $this->getJson('/api/orders/999999999')->assertNotFound();
    }

    public function test_print_job_filters_pagination_nullable_department_and_read_only_listing(): void
    {
        $a = $this->job(['department_id' => $this->f['department']->id, 'printer_id' => $this->f['printer']->id, 'job_type' => 'NEW_ORDER']);
        $b = $this->job(['status' => 'PRINTED', 'created_at' => '2036-04-03 00:00:00']);
        $before = PrintJob::orderBy('id')->get()->toArray();
        $this->getJson('/api/print-jobs?per_page=1&from=2036-04-01')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.0.department', null);
        foreach (['status=FAILED', 'job_type=NEW_ORDER', 'printer_id='.$a->printer_id, 'department_id='.$a->department_id,
            'order_id='.$a->order_id, 'to=2036-04-02&from=2036-04-02',
            'status=FAILED&job_type=NEW_ORDER&printer_id='.$a->printer_id.'&order_id='.$a->order_id] as $filter) {
            $this->getJson('/api/print-jobs?from=2036-04-01&'.$filter)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.0.payload', $a->payload);
        }
        $this->assertSame($before, PrintJob::orderBy('id')->get()->toArray());
        foreach (['status=WRONG', 'job_type=WRONG', 'printer_id=999999999', 'department_id=999999999',
            'order_id=999999999', 'from=bad', 'from=2036-04-03&to=2036-04-02', 'per_page=0'] as $filter) {
            $this->getJson('/api/print-jobs?'.$filter)->assertUnprocessable();
        }
    }

    public function test_retry_claim_blocks_reentry_and_sends_snapshot_outside_its_transaction(): void
    {
        $job = $this->job();
        $orders = Order::orderBy('id')->get()->toArray();
        $stocks = InventoryStock::orderBy('id')->get()->toArray();
        $movements = StockTransaction::count();
        $baselineLevel = DB::transactionLevel();
        $this->partialMock(NetworkPrinterTransport::class, function ($mock) use ($job, $baselineLevel) {
            $mock->shouldReceive('send')->once()->withArgs(fn ($printer, $payload) => $printer->id === $job->printer_id && $payload === $job->payload)
                ->andReturnUsing(function () use ($job, $baselineLevel) {
                    $this->assertSame($baselineLevel, DB::transactionLevel());
                    $this->assertSame('PENDING', $job->fresh()->status);
                    $this->assertSame(3, $job->fresh()->attempts);
                    try {
                        app(PrintJobService::class)->retry($job);
                        $this->fail('A claimed job cannot be processed concurrently.');
                    } catch (InvalidOrderStateException $exception) {
                        $this->assertStringContainsString('Only failed', $exception->getMessage());
                    }
                });
        });
        $this->postJson('/api/print-jobs/'.$job->id.'/retry')->assertOk()->assertJsonPath('data.status', 'PRINTED')
            ->assertJsonPath('data.attempts', 3)->assertJsonPath('data.payload', $job->payload);
        $this->assertNotNull($job->fresh()->printed_at);
        $this->assertSame($orders, Order::orderBy('id')->get()->toArray());
        $this->assertSame($stocks, InventoryStock::orderBy('id')->get()->toArray());
        $this->assertSame($movements, StockTransaction::count());
        $this->assertSame(1, PrintJob::where('order_id', $job->order_id)->count());
    }

    public function test_network_retry_failure_keeps_failed_and_increments_attempts(): void
    {
        $job = $this->job();
        $this->partialMock(NetworkPrinterTransport::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Fake connection failure')));
        $this->postJson('/api/print-jobs/'.$job->id.'/retry')->assertOk()->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.attempts', 3)->assertJsonPath('data.printed_at', null)->assertJsonPath('data.payload', $job->payload);
    }

    public static function invalidRetryStates(): array
    {
        return [['PENDING'], ['PRINTED']];
    }

    #[DataProvider('invalidRetryStates')]
    public function test_only_failed_jobs_can_be_retried(string $status): void
    {
        $job = $this->job(['status' => $status]);
        $this->mock(NetworkPrinterTransport::class, fn ($mock) => $mock->shouldNotReceive('send'));
        $this->postJson('/api/print-jobs/'.$job->id.'/retry')->assertUnprocessable();
        $this->assertSame(2, $job->fresh()->attempts);
        $this->assertSame($status, $job->fresh()->status);
    }

    public function test_printer_validation_and_non_network_requeue_never_claims_printed(): void
    {
        $job = $this->job();
        $this->f['receipt']->update(['connection_config' => 'not-an-ip']);
        $this->postJson('/api/print-jobs/'.$job->id.'/retry')->assertUnprocessable();
        $this->assertSame(2, $job->fresh()->attempts);
        $this->f['receipt']->update(['is_active' => false]);
        $this->postJson('/api/print-jobs/'.$job->id.'/retry')->assertUnprocessable();
        $this->f['receipt']->update(['is_active' => true, 'connection_type' => 'UNKNOWN']);
        $this->postJson('/api/print-jobs/'.$job->id.'/retry')->assertUnprocessable();
        foreach (['LOCAL_AGENT', 'USB'] as $type) {
            $this->f['receipt']->update(['connection_type' => $type]);
            $job->refresh()->update(['status' => 'FAILED']);
            $this->postJson('/api/print-jobs/'.$job->id.'/retry')->assertOk()->assertJsonPath('data.status', 'PENDING')
                ->assertJsonPath('data.attempts', 2)->assertJsonPath('data.printed_at', null);
        }
        $this->postJson('/api/print-jobs/999999999/retry')->assertNotFound();
    }

    private function financialFixtures(): void
    {
        $this->order(['total_amount' => '100.10', 'created_at' => '2036-03-31 23:59:59', 'paid_at' => '2036-04-02 00:00:00']);
        $this->order(['total_amount' => '20.20', 'created_at' => '2036-04-02 12:00:00', 'paid_at' => '2036-04-02 23:59:59']);
        $this->order(['total_amount' => '7.70', 'paid_at' => '2036-04-04 00:00:00']);
        $this->order(['total_amount' => '999.00', 'payment_status' => 'UNPAID', 'paid_at' => null, 'order_status' => 'PREPARING']);
        $this->order(['total_amount' => '888.00', 'order_status' => 'CANCELLED']);
        $this->expense('2036-04-02', '30.05');
        $this->expense('2036-04-04', '1.15');
        $this->expense('2036-04-02', '900.00', 'CANCELLED');
    }

    public function test_financial_daily_breakdown_zero_days_and_totals_use_correct_dates(): void
    {
        $this->financialFixtures();
        $this->getJson('/api/reports/financial?from=2036-04-02&to=2036-04-04')->assertOk()->assertExactJson(['data' => [
            'period' => ['from' => '2036-04-02', 'to' => '2036-04-04'], 'sales' => '128.00',
            'expenses' => '31.20', 'net_cash_flow' => '96.80', 'breakdown' => [
                ['date' => '2036-04-02', 'sales' => '120.30', 'expenses' => '30.05', 'net_cash_flow' => '90.25'],
                ['date' => '2036-04-03', 'sales' => '0.00', 'expenses' => '0.00', 'net_cash_flow' => '0.00'],
                ['date' => '2036-04-04', 'sales' => '7.70', 'expenses' => '1.15', 'net_cash_flow' => '6.55'],
            ],
        ]]);
        $this->getJson('/api/reports/financial?from=2036-05-01&to=2036-05-02')->assertOk()
            ->assertJsonPath('data.sales', '0.00')->assertJsonPath('data.expenses', '0.00')->assertJsonCount(2, 'data.breakdown');
    }

    public function test_dashboard_today_uses_app_timezone_and_keeps_live_sections_outside_period(): void
    {
        config(['app.timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2036-04-01 12:30:00', 'UTC')); // Already April 2 in Auckland.
        $this->financialFixtures();
        $old = $this->order(['order_status' => 'READY', 'payment_status' => 'UNPAID', 'paid_at' => null, 'created_at' => '2035-01-01 00:00:00']);
        RestaurantTable::create(['table_number' => RestaurantTable::max('table_number') + 1, 'status' => 'OCCUPIED', 'is_active' => false]);
        $expectedAvailable = RestaurantTable::where('is_active', true)->where('status', 'AVAILABLE')->count();
        $expectedOccupied = RestaurantTable::where('is_active', true)->where('status', 'OCCUPIED')->count();
        $response = $this->getJson('/api/dashboard/summary')->assertOk()
            ->assertJsonPath('data.period', ['from' => '2036-04-02', 'to' => '2036-04-02'])
            ->assertJsonPath('data.sales', '120.30')->assertJsonPath('data.expenses', '30.05')->assertJsonPath('data.net_cash_flow', '90.25')
            ->assertJsonPath('data.orders.total', 1)->assertJsonPath('data.orders.closed', 1)->assertJsonPath('data.orders.approved', 0)
            ->assertJsonPath('data.tables.available', $expectedAvailable)->assertJsonPath('data.tables.occupied', $expectedOccupied);
        $this->assertContains($old->id, array_column($response->json('data.recent_active_orders'), 'id'));
        $this->getJson('/api/dashboard/summary?from=2036-04-04&to=2036-04-04')->assertOk()->assertJsonPath('data.sales', '7.70')
            ->assertJsonPath('data.tables.available', $expectedAvailable);
        $this->getJson('/api/dashboard/summary?from=2036-04-04')->assertOk()
            ->assertJsonPath('data.period', ['from' => '2036-04-04', 'to' => null])->assertJsonPath('data.sales', '7.70');
        $this->getJson('/api/dashboard/summary?to=2030-01-01')->assertOk()->assertJsonPath('data.period.from', null);
        $this->travelBack();
    }

    public function test_dashboard_counts_every_schema_status_and_negative_cash_flow(): void
    {
        foreach (['PENDING_APPROVAL', 'APPROVED', 'PREPARING', 'READY', 'CLOSED', 'CANCELLED'] as $status) {
            $this->order(['order_status' => $status, 'created_at' => '2037-01-01 00:00:00', 'payment_status' => 'UNPAID', 'paid_at' => null]);
        }
        $this->expense('2037-01-01', '0.10');
        $response = $this->getJson('/api/dashboard/summary?from=2037-01-01&to=2037-01-01')->assertOk()
            ->assertJsonPath('data.orders.total', 6)->assertJsonPath('data.net_cash_flow', '-0.10');
        foreach (['pending_approval', 'approved', 'preparing', 'ready', 'closed', 'cancelled'] as $status) {
            $response->assertJsonPath('data.orders.'.$status, 1);
        }
    }

    public function test_financial_aggregates_can_exceed_an_individual_money_column(): void
    {
        $this->order(['total_amount' => '99999999.99']);
        $this->order(['total_amount' => '99999999.99']);
        $this->expense('2036-04-02', '0.01');
        foreach (['/api/reports/financial', '/api/dashboard/summary'] as $path) {
            $this->getJson($path.'?from=2036-04-02&to=2036-04-02')->assertOk()
                ->assertJsonPath('data.sales', '199999999.98')->assertJsonPath('data.expenses', '0.01')
                ->assertJsonPath('data.net_cash_flow', '199999999.97');
        }
    }

    public function test_top_products_historical_subtotals_fractional_quantities_ties_and_limits(): void
    {
        $order = $this->order([], false);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $product = Product::create(['name' => 'Historical '.$i, 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE',
                'price' => '999.99', 'is_active' => false, 'is_stock_tracked' => false]);
            $ids[] = $product->id;
            $order->orderItems()->create(['product_id' => $product->id, 'quantity' => $i < 2 ? '5.25' : '1.00',
                'unit_price' => '0.10', 'subtotal' => $i < 2 ? '0.53' : '0.10', 'status' => 'ACTIVE']);
        }
        $second = $this->order([], false);
        $second->orderItems()->create(['product_id' => $ids[0], 'quantity' => '0.25', 'unit_price' => '0.12', 'subtotal' => '0.03', 'status' => 'ACTIVE']);
        $second->orderItems()->create(['product_id' => $this->f['drink']->id, 'quantity' => '100', 'unit_price' => '1', 'subtotal' => '100', 'status' => 'CANCELLED']);
        $this->order(['payment_status' => 'UNPAID', 'paid_at' => null]);
        $this->order(['paid_at' => '2036-04-03 00:00:00']);
        $base = '/api/reports/top-products?from=2036-04-02&to=2036-04-02';
        $response = $this->getJson($base)->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.product_id', $ids[0])->assertJsonPath('data.0.quantity_sold', '5.50')
            ->assertJsonPath('data.0.sales_amount', '0.56')->assertJsonPath('data.1.product_id', $ids[1]);
        $this->assertNotContains($this->f['drink']->id, array_column($response->json('data'), 'product_id'));
        $this->getJson($base.'&limit=1')->assertOk()->assertJsonCount(1, 'data');
        $all = $this->getJson($base.'&limit=100')->assertOk()->assertJsonCount(12, 'data')->json('data');
        $this->assertSame($ids, array_column($all, 'product_id'));
        $dashboard = $this->getJson('/api/dashboard/summary?from=2036-04-02&to=2036-04-02')->assertOk()
            ->assertJsonCount(5, 'data.top_products')->json('data.top_products');
        $this->assertSame(array_slice($all, 0, 5), $dashboard);
    }

    public function test_report_validation_requires_dates_and_limits_and_checks_range(): void
    {
        foreach (['/api/reports/financial', '/api/reports/top-products'] as $path) {
            foreach (['', '?from=2036-04-01', '?to=2036-04-01', '?from=bad&to=2036-04-01',
                '?from=2036-04-02&to=2036-04-01', '?from=2036-02-30&to=2036-04-01'] as $query) {
                $this->getJson($path.$query)->assertUnprocessable();
            }
        }
        foreach (['0', '-1', '101', '1.5', 'bad'] as $limit) {
            $this->getJson('/api/reports/top-products?from=2036-04-01&to=2036-04-02&limit='.$limit)->assertUnprocessable();
        }
        $this->getJson('/api/dashboard/summary?from=2036-04-02&to=2036-04-01')->assertUnprocessable();
    }

    public function test_new_routes_require_auth_active_and_admin_where_appropriate(): void
    {
        $job = $this->job();
        $adminRoutes = [['GET', '/api/print-jobs'], ['POST', '/api/print-jobs/'.$job->id.'/retry'],
            ['GET', '/api/dashboard/summary'], ['GET', '/api/reports/financial?from=2036-04-01&to=2036-04-02'],
            ['GET', '/api/reports/top-products?from=2036-04-01&to=2036-04-02']];
        $this->loginAs('employee');
        foreach ($adminRoutes as [$method, $url]) {
            $this->json($method, $url)->assertForbidden();
        }
        $all = [...$adminRoutes, ['GET', '/api/orders'], ['GET', '/api/orders/'.$job->order_id]];
        $this->f['employee']->update(['is_active' => false]);
        $this->loginAs('employee');
        foreach ($all as [$method, $url]) {
            $this->json($method, $url)->assertForbidden();
        }
        foreach ($all as [$method, $url]) {
            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', '')->json($method, $url)->assertUnauthorized();
        }
    }

    public function test_read_queries_are_bounded_and_do_not_lazy_load_order_items(): void
    {
        $job = $this->job();
        DB::enableQueryLog();
        foreach (['/api/orders', '/api/print-jobs', '/api/dashboard/summary?from=2036-04-01&to=2036-04-02',
            '/api/reports/financial?from=2036-04-01&to=2036-04-30', '/api/reports/top-products?from=2036-04-01&to=2036-04-02'] as $url) {
            DB::flushQueryLog();
            $this->getJson($url)->assertOk();
            $queries = DB::getQueryLog();
            $this->assertLessThanOrEqual(12, count($queries));
            if ($url === '/api/orders') {
                $this->assertFalse(collect($queries)->contains(fn ($q) => str_contains($q['query'], 'order_items')));
            }
        }
        DB::disableQueryLog();
    }
}
