<?php

/** Execute the Postman collection against a disposable MySQL database, then verify DB effects. */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\OrderFixtures;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached()) {
    throw new RuntimeException('Use uncached configuration so child HTTP servers use the isolated test database.');
}
$connection = DB::connection();
if ($connection->getDriverName() !== 'mysql') {
    throw new RuntimeException('This integration runner requires MySQL/MariaDB.');
}
$originalConfig = $connection->getConfig();
$database = 'order_api_test_'.bin2hex(random_bytes(8));
// Generated identifier only: never accept a database name from command-line input.
if (! preg_match('/^order_api_test_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Invalid temporary database name.');
}
$connection->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$servers = [];
$environmentFile = null;
$assertions = 0;
$check = function (bool $condition, string $message) use (&$assertions): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
try {
    config(['database.connections.order_test' => array_replace($originalConfig, ['name' => 'order_test', 'database' => $database, 'url' => null]), 'database.default' => 'order_test']);
    Schema::clearResolvedInstance('db.schema');
    Artisan::call('migrate', ['--database' => 'order_test', '--force' => true]);
    DB::setDefaultConnection('order_test');
    $f = OrderFixtures::create();
    $env = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        'DB_HOST' => $originalConfig['host'], 'DB_PORT' => (string) $originalConfig['port'], 'DB_DATABASE' => $database,
        'DB_USERNAME' => $originalConfig['username'], 'DB_PASSWORD' => $originalConfig['password'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr'];
    $ports = [];
    for ($i = 0; $i < 2; $i++) {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
        fclose($reservation);
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, dirname(__DIR__).'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], dirname(__DIR__).'/public', $env);
        $server->setTimeout(null);
        $server->start();
        $servers[] = $server;
        $ports[] = $port;
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $code, $error, 0.1);
            if ($socket) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        $check($ready, 'Test HTTP server did not start: '.$server->getErrorOutput());
    }
    $variables = ['base_url' => 'http://127.0.0.1:'.$ports[0].'/api', 'username' => $f['admin']->username, 'password' => 'test-password', 'table_id' => $f['table']->id];
    foreach (['meal', 'drink', 'snack', 'extra', 'raw'] as $key) {
        $variables[$key.'_id'] = $f[$key]->id;
    }
    $environmentFile = tempnam(sys_get_temp_dir(), 'order-postman-');
    file_put_contents($environmentFile, json_encode(['name' => 'Isolated order API fixtures', 'values' => array_map(
        fn ($key, $value) => ['key' => $key, 'value' => (string) $value, 'enabled' => true], array_keys($variables), array_values($variables),
    )], JSON_THROW_ON_ERROR));
    $newman = new Process([PHP_OS_FAMILY === 'Windows' ? 'npx.cmd' : 'npx', '--yes', 'newman', 'run',
        dirname(__DIR__).'/docs/postman/OrderAPI.postman_collection.json', '-e', $environmentFile, '--bail'], dirname(__DIR__), ['npm_config_offline' => 'true']);
    $newman->setTimeout(180);
    $newman->mustRun(fn ($type, $output) => print ($output));

    $orders = Order::orderBy('id')->get();
    $check($orders->count() === 4, 'Only the four successful creations should persist.');
    [$cancelled, $pendingCancelled, $shortageCancelled, $paid] = $orders->all();
    $check($cancelled->order_status === 'CANCELLED' && $cancelled->orderItems()->count() === 3, 'Cancelled history must remain.');
    $check(! $cancelled->orderItems()->where('product_id', $f['snack']->id)->exists(), 'Removed order item must be physically deleted.');
    $check($cancelled->total_amount === '81.85', 'Updated total must use snapshots.');
    $check($cancelled->printJobs()->where('job_type', 'NEW_ORDER')->count() === 1, 'Department without printer must be skipped.');
    foreach (['ADDITION', 'MODIFICATION'] as $type) {
        $check($cancelled->printJobs()->where('job_type', $type)->count() === 1, 'Missing delta print job '.$type);
    }
    foreach ($cancelled->stockTransactions()->get()->groupBy('product_id') as $movements) {
        $net = '0.000';
        foreach ($movements as $movement) {
            $net = $movement->direction === 'OUT' ? bcadd($net, $movement->quantity, 3) : bcsub($net, $movement->quantity, 3);
        }
        $check($net === '0.000', 'Cancellation must fully reverse all recorded stock.');
    }
    foreach ([$pendingCancelled, $shortageCancelled] as $order) {
        $check($order->stockTransactions()->count() === 0, 'Unapproved order must never move stock.');
    }
    $check($paid->payment_status === 'PAID' && $paid->order_status === 'CLOSED', 'Payment must close order.');
    $check($paid->amount_paid === null && $paid->change_amount === null, 'Payment amount fields must stay null.');
    $check($f['table']->fresh()->status === 'AVAILABLE', 'Cancellation/payment must free the table.');
    foreach (['raw' => '99.500', 'drink' => '97.000', 'snack' => '99.000', 'extra' => '100.000'] as $key => $expected) {
        $check(InventoryStock::where('product_id', $f[$key]->id)->first()->current_quantity === $expected, 'Only the paid order should consume '.$key);
    }

    // Two independent PHP servers exercise actual overlapping DB transactions.
    $token = $f['admin']->createToken('concurrency')->plainTextToken;
    $parallel = function (array $requests) use ($ports, $token): array {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => [$path, $body]) {
            $handle = curl_init('http://127.0.0.1:'.$ports[$i % 2].'/api'.$path);
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer '.$token]]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1);
            }
        } while ($running);
        $results = [];
        foreach ($handles as $handle) {
            $results[] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => json_decode(curl_multi_getcontent($handle), true)];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        return $results;
    };
    $body = ['table_id' => $f['table']->id, 'chairs_count' => 0, 'items' => [['product_id' => $f['drink']->id, 'quantity' => 80]]];
    $results = $parallel([['/orders', $body], ['/orders', $body]]);
    $codes = array_column($results, 'status');
    sort($codes);
    $check($codes === [201, 422], 'Concurrent same-table creations must yield one success and one conflict.');
    $first = collect($results)->firstWhere('status', 201)['body']['data']['id'];
    $otherTable = RestaurantTable::create(['table_number' => 999991, 'status' => 'AVAILABLE', 'is_active' => true]);
    $thirdTable = RestaurantTable::create(['table_number' => 999992, 'status' => 'AVAILABLE', 'is_active' => true]);
    $results = $parallel([['/orders', array_replace($body, ['table_id' => $otherTable->id])], ['/orders', array_replace($body, ['table_id' => $thirdTable->id])]]);
    $check(array_column($results, 'status') === [201, 201], 'Concurrent different-table creations must both succeed.');
    $check($results[0]['body']['data']['order_number'] !== $results[1]['body']['data']['order_number'], 'Concurrent daily numbers must be unique.');
    $second = $results[0]['body']['data']['id'];
    $results = $parallel([["/orders/$first/approve", []], ["/orders/$second/approve", []]]);
    $codes = array_column($results, 'status');
    sort($codes);
    $check($codes === [200, 422], 'Competing approvals must not oversell inventory.');
    $winner = collect($results)->firstWhere('status', 200)['body']['data']['id'];
    $results = $parallel([["/orders/$winner/pay", []], ["/orders/$winner/pay", []]]);
    $codes = array_column($results, 'status');
    sort($codes);
    $check($codes === [200, 409], 'Concurrent payments must process exactly once.');
    echo "\nDatabase and concurrency checks passed: $assertions assertions.\n";
} finally {
    foreach ($servers as $server) {
        $server->stop();
    }
    if ($environmentFile !== null) {
        unlink($environmentFile);
    }
    DB::purge('order_test');
    // This is only the random database created above; the application database is never dropped.
    $connection->statement('DROP DATABASE `'.$database.'`');
    echo "Disposable test database removed.\n";
}
