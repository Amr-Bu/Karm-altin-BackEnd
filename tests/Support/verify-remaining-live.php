<?php

// Runs only inside run-postman.php's disposable database, with two live PHP servers.

use App\Models\Expense;
use App\Models\InventoryStock;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$manualStock = InventoryStock::where('product_id', $manualProduct->id)->firstOrFail();
$check($manualStock->current_quantity === '34.000', 'Multipart purchase must add exactly 24 to the initial 10.');
$check(StockTransaction::where('product_id', $manualProduct->id)->count() === 1, 'Purchase must create one movement.');
$expense = Expense::firstOrFail();
$check($expense->status === 'CANCELLED' && $expense->amount === '145000.00', 'Multipart expense update and cancellation must persist.');
$employee = User::where('username', 'ahmad_updated')->firstOrFail();
$check(! $employee->is_active && $employee->name === 'Ahmad Updated', 'Multipart employee updates and string zero must persist.');
$check(! $f['admin']->tokens()->where('name', 'postman')->exists(), 'Collection logout must revoke its login token.');
$check($f['admin']->tokens()->where('name', 'concurrency')->exists(), 'Logout must preserve other tokens.');

// Competing multipart OUT requests cannot both spend the same stock.
$body = ['product_id' => (string) $manualProduct->id, 'transaction_type' => 'WASTE', 'direction' => 'OUT', 'quantity' => '20.000'];
$results = $parallel([['/inventory/movements', $body, $token, true], ['/inventory/movements', $body, $token, true]]);
$codes = array_column($results, 'status');
sort($codes);
$check($codes === [201, 422], 'Concurrent manual OUT must yield one success and one shortage.');
$check($manualStock->fresh()->current_quantity === '14.000', 'Concurrent manual OUT must never overspend.');
$check(StockTransaction::where('product_id', $manualProduct->id)->count() === 2, 'Rejected manual OUT must not create history.');
$body = array_replace($body, ['transaction_type' => 'ADJUSTMENT', 'direction' => 'IN', 'quantity' => '0.125']);
$results = $parallel([['/inventory/movements', $body, $token, true], ['/inventory/movements', $body, $token, true]]);
$check(array_column($results, 'status') === [201, 201], 'Concurrent manual IN must both succeed.');
$check($manualStock->fresh()->current_quantity === '14.250', 'Concurrent increments must not lose an update.');
$check(StockTransaction::where('product_id', $manualProduct->id)->count() === 4, 'Every successful manual movement must have one history row.');

$activeExpense = Expense::create(['created_by' => $f['admin']->id, 'name' => 'Concurrent cancellation',
    'amount' => '1.00', 'expense_date' => '2026-10-11', 'status' => 'ACTIVE']);
$results = $parallel([['/expenses/'.$activeExpense->id.'/cancel', []], ['/expenses/'.$activeExpense->id.'/cancel', []]]);
$codes = array_column($results, 'status');
sort($codes);
$check($codes === [200, 422], 'Concurrent expense cancellation must succeed only once.');

// Leave exactly two active admins, then race demotions through separate tokens.
// Authorization may reject a request after the competing demotion commits (403);
// requests already authorized must be rejected by the locked last-admin guard (422).
User::where('id', '!=', $f['admin']->id)->update(['role' => 'EMPLOYEE']);
$secondAdmin = User::create(['name' => 'Second admin', 'username' => 'concurrent_admin',
    'password_hash' => Hash::make('test-password'), 'role' => 'ADMIN', 'is_active' => true]);
$secondToken = $secondAdmin->createToken('concurrency')->plainTextToken;
$body = ['_method' => 'PATCH', 'role' => 'EMPLOYEE'];
$results = $parallel([
    ['/employees/'.$secondAdmin->id, $body, $token, true],
    ['/employees/'.$f['admin']->id, $body, $secondToken, true],
]);
$codes = array_column($results, 'status');
sort($codes);
$check($codes === [200, 422] || $codes === [200, 403], 'Concurrent admin demotions must not both succeed.');
$check(User::where('role', 'ADMIN')->where('is_active', true)->count() === 1, 'One active admin must survive concurrent demotions.');

// Repeat with deactivation, which uses the same serialized membership guard.
$f['admin']->refresh()->update(['role' => 'ADMIN', 'is_active' => true]);
$secondAdmin->refresh()->update(['role' => 'ADMIN', 'is_active' => true]);
$body = ['_method' => 'PATCH', 'is_active' => '0'];
$results = $parallel([
    ['/employees/'.$secondAdmin->id.'/status', $body, $token, true],
    ['/employees/'.$f['admin']->id.'/status', $body, $secondToken, true],
]);
$codes = array_column($results, 'status');
sort($codes);
$check($codes === [200, 422] || $codes === [200, 403], 'Concurrent admin deactivations must not both succeed.');
$check(User::where('role', 'ADMIN')->where('is_active', true)->count() === 1, 'One active admin must survive concurrent deactivations.');
