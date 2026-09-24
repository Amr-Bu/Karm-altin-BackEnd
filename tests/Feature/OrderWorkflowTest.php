<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\StockTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run php vendor/bin/phpunit -c phpunit.orders.xml for real MySQL constraints and locks.');
        }
        $this->f = OrderFixtures::create();
        $this->withToken($this->f['admin']->createToken('test')->plainTextToken);
    }

    private function body(): array
    {
        return ['table_id' => $this->f['table']->id, 'chairs_count' => 2, 'items' => [
            ['product_id' => $this->f['meal']->id, 'quantity' => '2', 'note' => 'بدون ملح'],
            ['product_id' => $this->f['drink']->id, 'quantity' => '3'],
            ['product_id' => $this->f['snack']->id, 'quantity' => '1'],
        ]];
    }

    private function createOrder(): int
    {
        return $this->postJson('/api/orders', $this->body())->assertCreated()->json('data.id');
    }

    private function stock(string $key): string
    {
        return InventoryStock::where('product_id', $this->f[$key]->id)->first()->current_quantity;
    }

    private function counts(): array
    {
        return [Order::count(), OrderItem::count(), StockTransaction::count(), PrintJob::count()];
    }

    public function test_creation_snapshots_totals_and_rejects_occupied_table(): void
    {
        $this->withToken($this->f['employee']->createToken('employee')->plainTextToken);
        $response = $this->postJson('/api/orders', $this->body())->assertCreated()
            ->assertJsonPath('data.items_subtotal', '31.80')->assertJsonPath('data.chairs_total', '30.00')
            ->assertJsonPath('data.total_amount', '61.80')->assertJsonPath('data.employee_id', $this->f['employee']->id)
            ->assertJsonPath('data.amount_paid', null)->assertJsonPath('data.approved_by', null);
        $this->assertMatchesRegularExpression('/^ORD-'.now()->format('Ymd').'-\d{4}$/', $response->json('data.order_number'));
        $this->assertSame('OCCUPIED', $this->f['table']->fresh()->status);
        $this->assertSame('100.000', $this->stock('raw'));
        $counts = $this->counts();
        $this->postJson('/api/orders', $this->body())->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertSame($counts, $this->counts());
    }

    public function test_raw_inactive_and_client_priced_products_are_rejected_atomically(): void
    {
        $before = $this->counts();
        $body = $this->body();
        $body['items'][1]['product_id'] = $this->f['raw']->id;
        $this->postJson('/api/orders', $body)->assertStatus(422)->assertJsonPath('message', 'لا يمكن إضافة المواد الخام مباشرة إلى الطلب');
        $this->f['meal']->update(['is_active' => false]);
        $this->postJson('/api/orders', $this->body())->assertStatus(422);
        $body = $this->body();
        $body['items'][0]['unit_price'] = 1;
        $this->postJson('/api/orders', $body)->assertUnprocessable();
        $this->assertSame($before, $this->counts());
        $this->assertSame('AVAILABLE', $this->f['table']->fresh()->status);
    }

    public function test_approval_deducts_recipes_groups_printing_and_rejects_repeat(): void
    {
        $id = $this->createOrder();
        $this->postJson("/api/orders/$id/approve")->assertOk()->assertJsonPath('data.order_status', 'PREPARING')
            ->assertJsonPath('data.approved_by', $this->f['admin']->id);
        $this->assertNotNull(Order::find($id)->approved_at);
        $this->assertSame('99.500', $this->stock('raw'));
        $this->assertSame('97.000', $this->stock('drink'));
        $this->assertSame('99.000', $this->stock('snack'));
        $this->assertSame(3, StockTransaction::where('reference_order_id', $id)->count());
        $this->assertSame(1, PrintJob::where('order_id', $id)->count());
        $job = PrintJob::where('order_id', $id)->first();
        $this->assertSame('NEW_ORDER', $job->job_type);
        $this->assertStringContainsString('بدون ملح', $job->payload);
        $before = $this->counts();
        $this->postJson("/api/orders/$id/approve")->assertUnprocessable();
        $this->assertSame($before, $this->counts());
    }

    public function test_insufficient_stock_rolls_back_entire_approval_and_update(): void
    {
        $id = $this->createOrder();
        InventoryStock::where('product_id', $this->f['snack']->id)->update(['current_quantity' => '0']);
        $before = $this->counts();
        $this->postJson("/api/orders/$id/approve")->assertUnprocessable()->assertJsonPath('shortages.0.shortfall', '1.000');
        $this->assertSame($before, $this->counts());
        $this->assertSame('100.000', $this->stock('raw'));
        $this->assertSame('100.000', $this->stock('drink'));
        $this->assertSame('PENDING_APPROVAL', Order::find($id)->order_status);
        InventoryStock::where('product_id', $this->f['snack']->id)->update(['current_quantity' => '100']);
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $before = $this->counts();
        $this->patchJson("/api/orders/$id", ['items' => [['product_id' => $this->f['drink']->id, 'quantity' => 1000]]])->assertUnprocessable();
        $this->assertSame($before, $this->counts());
        $this->assertSame('61.80', Order::find($id)->total_amount);
        $this->assertSame('99.500', $this->stock('raw'));
    }

    public function test_update_deltas_and_cancellation_restore_stock_and_preserve_history(): void
    {
        $id = $this->createOrder();
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $removed = OrderItem::where('order_id', $id)->where('product_id', $this->f['snack']->id)->value('id');
        // Give the removed product a printer so all three delta job types are exercised.
        $this->f['snack']->update(['department_id' => $this->f['department']->id]);
        $this->f['meal']->update(['price' => '99']);
        Setting::where('key', 'chair_price')->update(['value' => '99']);
        $this->patchJson("/api/orders/$id", ['chairs_count' => 3, 'items' => [
            ['product_id' => $this->f['meal']->id, 'quantity' => 3],
            ['product_id' => $this->f['drink']->id, 'quantity' => 1],
            ['product_id' => $this->f['extra']->id, 'quantity' => 2],
        ]])->assertOk()->assertJsonPath('data.total_amount', '81.85')->assertJsonPath('data.chair_price', '15.00');
        $this->assertNull(OrderItem::find($removed));
        $this->assertSame('99.250', $this->stock('raw'));
        $this->assertSame('99.000', $this->stock('drink'));
        $this->assertSame('100.000', $this->stock('snack'));
        $this->assertSame('98.000', $this->stock('extra'));
        $this->assertSame(['ADDITION', 'CANCELLATION', 'MODIFICATION', 'NEW_ORDER'], PrintJob::where('order_id', $id)->orderBy('job_type')->pluck('job_type')->all());
        $history = [OrderItem::where('order_id', $id)->count(), StockTransaction::where('reference_order_id', $id)->count(), PrintJob::where('order_id', $id)->count()];
        $this->postJson("/api/orders/$id/cancel")->assertOk()->assertJsonPath('data.order_status', 'CANCELLED');
        foreach (['raw', 'drink', 'snack', 'extra'] as $key) {
            $this->assertSame('100.000', $this->stock($key));
        }
        $this->assertSame('AVAILABLE', $this->f['table']->fresh()->status);
        $this->assertSame($history[0], OrderItem::where('order_id', $id)->count());
        $this->assertGreaterThan($history[1], StockTransaction::where('reference_order_id', $id)->count());
        $this->assertGreaterThan($history[2], PrintJob::where('order_id', $id)->count());
        $this->assertNotNull(Order::find($id)->cancelled_at);
        $this->postJson("/api/orders/$id/cancel")->assertUnprocessable();
    }

    public function test_pending_update_and_cancellation_never_move_stock_or_print(): void
    {
        $id = $this->createOrder();
        $this->patchJson("/api/orders/$id", ['chairs_count' => 1])->assertOk()->assertJsonCount(3, 'data.items')->assertJsonPath('data.total_amount', '46.80');
        $this->patchJson("/api/orders/$id", ['items' => []])->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.total_amount', '15.00');
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this->assertSame(0, StockTransaction::where('reference_order_id', $id)->count());
        $this->assertSame(0, PrintJob::where('order_id', $id)->count());
        $this->assertSame('AVAILABLE', $this->f['table']->fresh()->status);
    }

    public function test_payment_closes_frees_table_and_paid_orders_are_immutable(): void
    {
        $id = $this->createOrder();
        $this->postJson("/api/orders/$id/pay")->assertUnprocessable();
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $this->postJson("/api/orders/$id/pay")->assertOk()->assertJsonPath('data.order_status', 'CLOSED')->assertJsonPath('data.payment_status', 'PAID')
            ->assertJsonPath('data.amount_paid', null)->assertJsonPath('data.change_amount', null);
        $this->assertSame('AVAILABLE', $this->f['table']->fresh()->status);
        $this->assertNotNull(Order::find($id)->paid_at);
        $this->assertNotNull(Order::find($id)->closed_at);
        $before = $this->counts();
        $this->postJson("/api/orders/$id/pay")->assertConflict();
        $this->patchJson("/api/orders/$id", ['chairs_count' => 8])->assertConflict();
        $this->postJson("/api/orders/$id/cancel")->assertConflict();
        $this->assertSame($before, $this->counts());
    }

    public function test_receipt_network_failure_is_successful_response_before_and_after_payment(): void
    {
        $id = $this->createOrder();
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $response = $this->postJson("/api/orders/$id/print-receipt")->assertOk()->assertJsonPath('print_job.status', 'FAILED')
            ->assertJsonPath('print_job.attempts', 1)->assertJsonPath('print_job.job_type', 'RECEIPT')->assertJsonPath('print_job.department_id', null);
        $this->assertStringContainsString('61.80', $response->json('print_job.payload'));
        $this->assertStringContainsString('موظف', $response->json('print_job.payload'));
        $this->postJson("/api/orders/$id/pay")->assertOk();
        $this->postJson("/api/orders/$id/print-receipt")->assertOk()->assertJsonPath('data.payment_status', 'PAID');
    }

    public function test_receipt_local_agent_stays_pending_and_missing_or_ambiguous_printer_is_rejected(): void
    {
        $id = $this->createOrder();
        $this->f['receipt']->update(['connection_type' => 'USB']);
        $this->postJson("/api/orders/$id/print-receipt")->assertOk()->assertJsonPath('print_job.status', 'PENDING')->assertJsonPath('print_job.attempts', 0);
        $this->f['receipt']->update(['is_active' => false]);
        $this->postJson("/api/orders/$id/print-receipt")->assertUnprocessable();
        $this->f['receipt']->update(['is_active' => true]);
        Printer::create(['name' => 'ثانية', 'printer_type' => 'RECEIPT', 'connection_type' => 'USB', 'is_active' => true]);
        $this->postJson("/api/orders/$id/print-receipt")->assertUnprocessable();
        $this->assertSame(1, PrintJob::where('order_id', $id)->count());
    }

    public function test_authentication_roles_and_not_found_responses(): void
    {
        $this->withToken('invalid')->postJson('/api/orders', $this->body())->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/login', ['username' => $this->f['employee']->username, 'password' => 'test-password'])->assertOk();
        $this->withToken($login->json('token'));
        $id = $this->createOrder();
        foreach (['approve', 'cancel', 'pay'] as $action) {
            $this->postJson("/api/orders/$id/$action")->assertForbidden();
        }
        $this->patchJson('/api/orders/999999999', ['chairs_count' => 1])->assertNotFound();
    }

    public function test_fractional_math_nontracked_items_and_repeated_edits(): void
    {
        $this->f['meal']->ingredients()->update(['quantity_required' => '0.333']);
        $this->f['drink']->update(['is_stock_tracked' => false]);
        $body = $this->body();
        $body['items'][0]['quantity'] = '0.01';
        $id = $this->postJson('/api/orders', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/orders/$id/approve")->assertOk();
        $this->assertSame('99.997', $this->stock('raw'));
        $this->assertSame('100.000', $this->stock('drink'));
        foreach (['0.02', '0.01', '0'] as $quantity) {
            $this->patchJson("/api/orders/$id", ['items' => [['product_id' => $this->f['meal']->id, 'quantity' => $quantity]]])->assertOk();
        }
        $this->assertSame('100.000', $this->stock('raw'));
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this->assertSame('100.000', $this->stock('raw'));
    }

    public function test_daily_sequence_increments_and_resets(): void
    {
        $this->travelTo(now()->setDate(2035, 1, 1));
        $id = $this->createOrder();
        $this->assertSame('ORD-20350101-0001', Order::find($id)->order_number);
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $id = $this->createOrder();
        $this->assertSame('ORD-20350101-0002', Order::find($id)->order_number);
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this->travel(1)->days();
        $id = $this->createOrder();
        $this->assertSame('ORD-20350102-0001', Order::find($id)->order_number);
        $this->travelBack();
    }

    public function test_receipt_success_sends_complete_payload_to_socket(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($listener, false);
        $this->f['receipt']->update(['connection_config' => $address]);
        try {
            $id = $this->createOrder();
            $response = $this->postJson("/api/orders/$id/print-receipt")->assertOk()
                ->assertJsonPath('print_job.status', 'PRINTED')->assertJsonPath('print_job.attempts', 1);
            $client = stream_socket_accept($listener, 2);
            $this->assertNotFalse($client);
            $this->assertSame($response->json('print_job.payload'), stream_get_contents($client));
            fclose($client);
            $this->assertNotNull($response->json('print_job.printed_at'));
        } finally {
            fclose($listener);
        }
    }

    public function test_shared_material_requirements_are_aggregated_before_deduction(): void
    {
        $secondMeal = $this->f['meal']->replicate();
        $secondMeal->name = 'وجبة ثانية';
        $secondMeal->save();
        $secondMeal->ingredients()->create(['raw_material_id' => $this->f['raw']->id, 'quantity_required' => '0.250']);
        InventoryStock::where('product_id', $this->f['raw']->id)->update(['current_quantity' => '0.750']);
        $body = $this->body();
        $body['items'] = [['product_id' => $this->f['meal']->id, 'quantity' => 2], ['product_id' => $secondMeal->id, 'quantity' => 2]];
        $id = $this->postJson('/api/orders', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/orders/$id/approve")->assertUnprocessable()->assertJsonPath('shortages.0.required', '1.000')
            ->assertJsonPath('shortages.0.shortfall', '0.250');
        $this->assertSame('0.750', $this->stock('raw'));
        $this->assertSame(0, StockTransaction::where('reference_order_id', $id)->count());
    }

    public function test_unique_active_table_guard_and_decimal_validation(): void
    {
        $id = $this->createOrder();
        // Simulate stale table status while the database-level active-order guard remains valid.
        $this->f['table']->update(['status' => 'AVAILABLE']);
        $this->postJson('/api/orders', $this->body())->assertUnprocessable()
            ->assertJsonPath('message', 'هذه الطاولة أصبحت مشغولة، الرجاء اختيار طاولة أخرى');
        foreach (['1.001', '-1', '1e3'] as $quantity) {
            $this->patchJson("/api/orders/$id", ['items' => [['product_id' => $this->f['meal']->id, 'quantity' => $quantity]]])->assertUnprocessable();
        }
        $this->patchJson("/api/orders/$id", ['chairs_count' => 2147483647])->assertUnprocessable();
        $this->assertSame('61.80', Order::find($id)->total_amount);
        $this->assertSame(2, Order::find($id)->chairs_count);
    }
}
