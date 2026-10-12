<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Expense;
use App\Models\InventoryStock;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class RemainingModulesTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run php vendor/bin/phpunit -c phpunit.mysql.xml.');
        }
        $this->f = OrderFixtures::create();
        $this->authenticate($this->f['admin']);
    }

    private function authenticate(User $user): string
    {
        $token = $user->createToken('remaining-modules')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token);

        return $token;
    }

    private function movement(array $overrides = []): array
    {
        return array_replace(['product_id' => (string) $this->f['drink']->id,
            'transaction_type' => 'PURCHASE', 'direction' => 'IN', 'quantity' => '2.125', 'note' => 'Manual test'], $overrides);
    }

    private function expense(): array
    {
        return ['name' => 'Test gas', 'amount' => '150000.00', 'note' => 'Kitchen gas', 'expense_date' => '2026-10-11'];
    }

    private function formPatch(string $url, array $body)
    {
        return $this->post($url, ['_method' => 'PATCH'] + $body, ['Accept' => 'application/json']);
    }

    public static function roles(): array
    {
        return [['admin'], ['employee']];
    }

    #[DataProvider('roles')]
    public function test_logout_revokes_only_current_token_and_leaves_account_unchanged(string $role): void
    {
        $user = $this->f[$role];
        $current = $this->authenticate($user);
        $other = $user->createToken('other-device');
        $before = $user->fresh()->getAttributes();
        $this->postJson('/api/logout')->assertOk()->assertExactJson(['message' => 'Logged out successfully']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => explode('|', $current)[0]]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/catalog')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/catalog')->assertOk();
    }

    public function test_inventory_current_values_types_inactive_filters_and_low_stock(): void
    {
        $this->f['drink']->update(['min_stock' => '100.000', 'is_active' => false, 'name' => 'Inventory needle']);
        $this->f['raw']->update(['min_stock' => '101.000']);
        $rows = collect($this->getJson('/api/inventory')->assertOk()->json('data'))->keyBy('product_id');
        $this->assertArrayNotHasKey($this->f['meal']->id, $rows->all());
        $this->assertSame('RAW_MATERIAL', $rows[$this->f['raw']->id]['item_type']);
        $this->assertSame('100.000', $rows[$this->f['drink']->id]['current_quantity']);
        $this->assertTrue($rows[$this->f['drink']->id]['is_low_stock']);
        $this->assertTrue($rows[$this->f['raw']->id]['is_low_stock']);
        $this->assertFalse($rows[$this->f['snack']->id]['is_low_stock']);
        $this->getJson('/api/inventory?item_type=PURCHASED_PRODUCT&is_active=0&search=Inventory%20needle&low_stock_only=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_id', $this->f['drink']->id);
        $this->f['drink']->update(['min_stock' => '99.999']);
        $this->getJson('/api/inventory?search=Inventory%20needle&low_stock_only=1')->assertOk()->assertJsonCount(0, 'data');
        foreach (['item_type=PRODUCED_PRODUCT', 'is_active=no', 'low_stock_only=no'] as $query) {
            $this->getJson('/api/inventory?'.$query)->assertUnprocessable();
        }
    }

    public static function manualDirections(): array
    {
        return [['PURCHASE', 'IN', '102.125'], ['WASTE', 'OUT', '97.875'],
            ['ADJUSTMENT', 'IN', '102.125'], ['ADJUSTMENT', 'OUT', '97.875']];
    }

    #[DataProvider('manualDirections')]
    public function test_manual_movement_updates_stock_and_exactly_one_history_record(string $type, string $direction, string $expected): void
    {
        $count = StockTransaction::count();
        $expenses = Expense::count();
        $response = $this->post('/api/inventory/movements', $this->movement(['transaction_type' => $type, 'direction' => $direction]),
            ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.current_quantity', $expected)
            ->assertJsonPath('data.quantity', '2.125')->assertJsonPath('data.created_by', $this->f['admin']->id)
            ->assertJsonPath('data.reference_order_id', null);
        $this->assertSame($count + 1, StockTransaction::count());
        $this->assertSame($expenses, Expense::count());
        $this->assertSame($expected, $this->f['drink']->inventoryStock()->first()->current_quantity);
        $this->assertDatabaseHas('stock_transactions', ['id' => $response->json('data.id'), 'transaction_type' => $type, 'direction' => $direction]);
        $this->assertArrayNotHasKey('balance_after', $response->json('data'));
        $this->assertArrayNotHasKey('current_quantity', StockTransaction::find($response->json('data.id'))->getAttributes());
    }

    public static function invalidMovements(): array
    {
        return array_map(fn ($body) => [$body], [
            ['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '0.0001'], ['quantity' => '1000000000'],
            ['quantity' => '1e2'], ['quantity' => []], ['transaction_type' => 'ORDER_CONSUMPTION'],
            ['transaction_type' => 'ORDER_RETURN'], ['transaction_type' => 'OTHER'], ['direction' => 'OTHER'],
            ['transaction_type' => 'PURCHASE', 'direction' => 'OUT'], ['transaction_type' => 'WASTE', 'direction' => 'IN'],
            ['product_id' => '999999999'], ['reference_order_id' => null], ['created_by' => 1],
            ['current_quantity' => '10'], ['actual_quantity' => '10'], ['balance_after' => '10'],
            ['note' => []], ['note' => str_repeat('ع', 32768)],
        ]);
    }

    #[DataProvider('invalidMovements')]
    public function test_invalid_manual_movements_leave_stock_and_history_unchanged(array $invalid): void
    {
        $before = StockTransaction::count();
        $this->postJson('/api/inventory/movements', $this->movement($invalid))->assertUnprocessable();
        $this->assertSame('100.000', $this->f['drink']->inventoryStock()->first()->current_quantity);
        $this->assertSame($before, StockTransaction::count());
    }

    public function test_stock_shortage_overflow_missing_record_and_produced_products_are_rejected(): void
    {
        $count = StockTransaction::count();
        $this->postJson('/api/inventory/movements', $this->movement(['transaction_type' => 'WASTE', 'direction' => 'OUT', 'quantity' => '100.001']))
            ->assertUnprocessable()->assertJsonPath('shortages.0.available', '100.000');
        $this->postJson('/api/inventory/movements', $this->movement(['product_id' => $this->f['meal']->id]))->assertUnprocessable();
        $this->f['raw']->inventoryStock()->delete();
        $this->postJson('/api/inventory/movements', $this->movement(['product_id' => $this->f['raw']->id]))->assertUnprocessable();
        $this->f['drink']->inventoryStock()->update(['current_quantity' => '999999999.999']);
        $this->postJson('/api/inventory/movements', $this->movement(['quantity' => '0.001']))->assertUnprocessable();
        $this->assertSame('999999999.999', $this->f['drink']->inventoryStock()->first()->current_quantity);
        $this->assertSame($count, StockTransaction::count());
    }

    public function test_movement_insert_failure_rolls_back_stock_update(): void
    {
        $count = StockTransaction::count();
        // A real FK violation after the stock write exercises transaction rollback.
        try {
            app(InventoryService::class)->createMovement($this->movement(), 999999999);
            $this->fail('Expected the creator FK to reject the history insertion.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
        $this->assertSame('100.000', $this->f['drink']->inventoryStock()->first()->current_quantity);
        $this->assertSame($count, StockTransaction::count());
    }

    public function test_inactive_raw_material_can_be_managed_and_out_can_reach_zero(): void
    {
        $this->f['raw']->update(['is_active' => false]);
        $this->postJson('/api/inventory/movements', $this->movement(['product_id' => $this->f['raw']->id,
            'transaction_type' => 'ADJUSTMENT', 'direction' => 'OUT', 'quantity' => '100']))
            ->assertCreated()->assertJsonPath('data.current_quantity', '0.000');
    }

    public function test_history_includes_order_movements_and_filters_dates_products_types_and_directions(): void
    {
        $order = $this->postJson('/api/orders', ['table_id' => $this->f['table']->id, 'chairs_count' => 0,
            'items' => [['product_id' => $this->f['drink']->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
        $this->postJson("/api/orders/$order/approve")->assertOk();
        $automatic = StockTransaction::where('reference_order_id', $order)->firstOrFail();
        $automatic->forceFill(['created_at' => '2026-10-01 12:00:00'])->save();
        $id = $this->postJson('/api/inventory/movements', $this->movement())->assertCreated()->json('data.id');
        StockTransaction::find($id)->forceFill(['created_at' => '2026-10-11 23:59:59'])->save();
        $base = '/api/inventory/movements?product_id='.$this->f['drink']->id;
        $this->getJson($base)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $id);
        foreach (['&from=2026-10-11', '&to=2026-10-11&from=2026-10-11', '&transaction_type=PURCHASE', '&direction=IN'] as $filter) {
            $this->getJson($base.$filter)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        }
        $this->getJson($base.'&to=2026-10-01')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_order_id', $order)->assertJsonPath('data.0.transaction_type', 'ORDER_CONSUMPTION');
        $response = $this->getJson('/api/inventory/movements')->assertOk();
        $this->assertContains($automatic->id, array_column($response->json('data'), 'id'));
        $row = collect($response->json('data'))->firstWhere('id', $id);
        $this->assertSame($this->f['drink']->id, $row['product']['id']);
        $this->assertSame($this->f['admin']->id, $row['creator']['id']);
        $this->assertArrayNotHasKey('balance_after', $row);
        $this->assertArrayNotHasKey('current_quantity', $row);
        foreach (['from=2026-10-12&to=2026-10-01', 'from=bad', 'to=2026-02-30', 'transaction_type=OTHER'] as $filter) {
            $this->getJson('/api/inventory/movements?'.$filter)->assertUnprocessable();
        }
    }

    public function test_employee_listing_filters_and_safe_response_fields(): void
    {
        $this->f['employee']->update(['is_active' => false, 'name' => 'Employee needle']);
        $rows = $this->getJson('/api/employees')->assertOk()->assertJsonCount(User::count(), 'data')->json('data');
        $this->assertContains('ADMIN', array_column($rows, 'role'));
        $this->assertContains('EMPLOYEE', array_column($rows, 'role'));
        foreach ($rows as $row) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'username', 'role', 'is_active'], array_keys($row));
        }
        $this->getJson('/api/employees?role=EMPLOYEE&is_active=0&search=Employee%20needle')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->f['employee']->id);
    }

    public function test_employee_partial_updates_password_policy_and_unique_username(): void
    {
        $employee = $this->f['employee'];
        $url = '/api/employees/'.$employee->id;
        $hash = $employee->password_hash;
        $this->formPatch($url, ['name' => 'Updated employee', 'username' => $employee->username, 'role' => 'ADMIN'])
            ->assertOk()->assertJsonPath('data.name', 'Updated employee')->assertJsonPath('data.role', 'ADMIN');
        $this->assertSame($hash, $employee->fresh()->password_hash);
        $this->formPatch($url, ['username' => 'updated_'.$employee->id, 'password' => 'new-strong-password'])
            ->assertOk()->assertJsonPath('data.username', 'updated_'.$employee->id);
        $this->assertTrue(Hash::check('new-strong-password', $employee->fresh()->password_hash));
        $this->assertNotSame('new-strong-password', $employee->fresh()->password_hash);
        $this->formPatch($url, ['username' => $this->f['admin']->username])->assertUnprocessable()->assertJsonValidationErrors('username');
        foreach (['short', str_repeat('ع', 37), "password\0bad", ''] as $password) {
            $this->formPatch($url, ['password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        foreach (['id', 'is_active', 'created_at', 'updated_at', 'password_hash', 'remember_token', 'token', 'tokens'] as $field) {
            $this->formPatch($url, [$field => '1'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_employee_status_blocks_existing_tokens_preserves_history_and_prevents_self_deactivation(): void
    {
        $employee = $this->f['employee'];
        $token = $employee->createToken('still-issued')->plainTextToken;
        $expense = Expense::create($this->expense() + ['created_by' => $employee->id, 'status' => 'ACTIVE']);
        $this->formPatch('/api/employees/'.$employee->id.'/status', ['is_active' => '0'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'created_by' => $employee->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/catalog')->assertForbidden();
        $this->authenticate($this->f['admin']);
        $this->formPatch('/api/employees/'.$employee->id.'/status', ['is_active' => '1'])
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->formPatch('/api/employees/'.$this->f['admin']->id.'/status', ['is_active' => '0'])->assertUnprocessable();
        $this->assertTrue($this->f['admin']->fresh()->is_active);
        $this->formPatch('/api/employees/'.$employee->id.'/status', ['is_active' => '1', 'role' => 'ADMIN'])->assertUnprocessable();
    }

    public function test_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        User::where('id', '!=', $this->f['admin']->id)->update(['role' => 'EMPLOYEE']);
        $this->formPatch('/api/employees/'.$this->f['admin']->id, ['role' => 'EMPLOYEE'])->assertUnprocessable();
        $this->assertSame('ADMIN', $this->f['admin']->fresh()->role);
        // Exercise the service guard independently of the self-deactivation guard.
        try {
            app(EmployeeService::class)->status($this->f['admin'], false, $this->f['employee']->id);
            $this->fail('Last admin must not be deactivated.');
        } catch (InvalidOrderStateException $exception) {
            $this->assertStringContainsString('active administrator', $exception->getMessage());
        }
        $this->assertTrue($this->f['admin']->fresh()->is_active);
        $this->f['employee']->update(['role' => 'ADMIN']);
        $this->formPatch('/api/employees/'.$this->f['admin']->id, ['role' => 'EMPLOYEE'])->assertOk();
    }

    public function test_expense_lifecycle_uses_form_updates_preserves_records_and_never_touches_stock(): void
    {
        $stocks = InventoryStock::orderBy('id')->get()->toArray();
        $movements = StockTransaction::count();
        $url = '/api/expenses';
        $id = $this->post($url, $this->expense(), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.amount', '150000.00')->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.created_by.id', $this->f['admin']->id)->json('data.id');
        $this->formPatch("$url/$id", ['amount' => '12.30', 'name' => 'Changed gas', 'note' => '', 'expense_date' => '2026-09-30'])
            ->assertOk()->assertJsonPath('data.amount', '12.30')->assertJsonPath('data.note', null)
            ->assertJsonPath('data.expense_date', '2026-09-30');
        $this->postJson("$url/$id/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertDatabaseHas('expenses', ['id' => $id, 'status' => 'CANCELLED', 'amount' => '12.30']);
        $this->formPatch("$url/$id", ['amount' => '20'])->assertUnprocessable()->assertJsonStructure(['message']);
        $this->postJson("$url/$id/cancel")->assertUnprocessable()->assertJsonStructure(['message']);
        $this->assertSame($stocks, InventoryStock::orderBy('id')->get()->toArray());
        $this->assertSame($movements, StockTransaction::count());
    }

    public static function invalidExpenses(): array
    {
        return array_map(fn ($body) => [$body], [['amount' => '0'], ['amount' => '-1'], ['amount' => '1.001'],
            ['amount' => '100000000'], ['amount' => '1e3'], ['amount' => []], ['expense_date' => '2026-02-30'],
            ['expense_date' => 'bad'], ['name' => ''], ['name' => str_repeat('x', 151)], ['note' => []],
            ['note' => str_repeat('ع', 32768)], ['created_by' => 1], ['status' => 'CANCELLED'], ['id' => 1]]);
    }

    #[DataProvider('invalidExpenses')]
    public function test_invalid_expense_create_and_update_fields_are_rejected(array $invalid): void
    {
        $count = Expense::count();
        $this->postJson('/api/expenses', array_replace($this->expense(), $invalid))->assertUnprocessable();
        $this->assertSame($count, Expense::count());
        $expense = Expense::create($this->expense() + ['created_by' => $this->f['admin']->id, 'status' => 'ACTIVE']);
        $before = $expense->fresh()->getAttributes();
        $this->formPatch('/api/expenses/'.$expense->id, $invalid)->assertUnprocessable();
        $this->assertSame($before, $expense->fresh()->getAttributes());
    }

    public function test_expense_filters_use_expense_date_not_creation_date(): void
    {
        $a = Expense::create($this->expense() + ['created_by' => $this->f['admin']->id, 'status' => 'ACTIVE']);
        $b = Expense::create(array_replace($this->expense(), ['expense_date' => '2026-09-01', 'name' => 'Other needle',
            'created_by' => $this->f['employee']->id, 'status' => 'CANCELLED']));
        $base = '/api/expenses?';
        $this->getJson('/api/expenses')->assertOk()->assertJsonCount(Expense::count(), 'data');
        foreach (['from=2026-10-11', 'from=2026-10-11&to=2026-10-11'] as $filter) {
            $ids = array_column($this->getJson($base.$filter)->assertOk()->json('data'), 'id');
            $this->assertContains($a->id, $ids);
            $this->assertNotContains($b->id, $ids);
        }
        $this->getJson($base.'to=2026-09-01&status=CANCELLED&search=Other%20needle&created_by='.$this->f['employee']->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        foreach (['from=2026-10-11&to=2026-09-01', 'to=bad', 'status=OTHER', 'created_by=999999999'] as $filter) {
            $this->getJson($base.$filter)->assertUnprocessable();
        }
    }

    public function test_listing_relations_are_eager_loaded(): void
    {
        $this->postJson('/api/inventory/movements', $this->movement())->assertCreated();
        $this->postJson('/api/expenses', $this->expense())->assertCreated();
        DB::enableQueryLog();
        foreach (['/api/inventory', '/api/inventory/movements', '/api/expenses'] as $url) {
            DB::flushQueryLog();
            $this->getJson($url)->assertOk();
            $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
        }
        DB::disableQueryLog();
    }

    public function test_all_new_routes_enforce_authentication_active_account_and_role(): void
    {
        $expense = Expense::create($this->expense() + ['created_by' => $this->f['admin']->id, 'status' => 'ACTIVE']);
        $employeeId = $this->f['employee']->id;
        $routes = [['GET', '/api/inventory'], ['GET', '/api/inventory/movements'], ['POST', '/api/inventory/movements'],
            ['GET', '/api/employees'], ['PATCH', "/api/employees/$employeeId"], ['PATCH', "/api/employees/$employeeId/status"],
            ['GET', '/api/expenses'], ['POST', '/api/expenses'], ['PATCH', '/api/expenses/'.$expense->id],
            ['POST', '/api/expenses/'.$expense->id.'/cancel']];
        $employeeToken = $this->f['employee']->createToken('forbidden')->plainTextToken;
        $this->f['admin']->update(['is_active' => false]);
        $inactiveToken = $this->f['admin']->createToken('inactive')->plainTextToken;
        foreach ([[$employeeToken, 403], [$inactiveToken, 403], ['', 401]] as [$token, $status]) {
            foreach ($routes as [$method, $url]) {
                $this->app['auth']->forgetGuards();
                $this->withHeader('Authorization', $token ? 'Bearer '.$token : '')->json($method, $url)->assertStatus($status);
            }
            if ($token !== $employeeToken) {
                $this->app['auth']->forgetGuards();
                $this->postJson('/api/logout')->assertStatus($status);
            }
        }
    }

    public function test_missing_resources_and_unrequested_routes(): void
    {
        foreach (['employees', 'expenses'] as $path) {
            $this->patchJson('/api/'.$path.'/999999999')->assertNotFound();
            $this->getJson('/api/'.$path.'/999999999')->assertStatus(405);
            $this->deleteJson('/api/'.$path.'/999999999')->assertStatus(405);
        }
    }
}
