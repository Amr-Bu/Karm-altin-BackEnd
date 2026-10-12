<?php

// Included only by the disposable-database runner. This TCP listener consumes bytes;
// it has no connection to a physical printer.
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\StockTransaction;

$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
if ($listener === false) {
    throw new RuntimeException('Cannot start the test print receiver: '.$error);
}
try {
    $receiverPort = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    $testPrinter = Printer::create(['name' => 'Retry test receiver', 'printer_type' => 'RECEIPT',
        'connection_type' => 'NETWORK', 'connection_config' => json_encode(['ip' => '127.0.0.1', 'port' => $receiverPort]), 'is_active' => true]);
    $testJob = PrintJob::create(['order_id' => $paid->id, 'department_id' => null, 'printer_id' => $testPrinter->id,
        'job_type' => 'RECEIPT', 'status' => 'FAILED', 'attempts' => 2, 'payload' => "Saved retry snapshot\n"]);
    $ordersBefore = Order::orderBy('id')->get()->toArray();
    $stockBefore = InventoryStock::orderBy('id')->get()->toArray();
    $historyBefore = StockTransaction::count();
    $results = $parallel([
        ['/print-jobs/'.$testJob->id.'/retry', []],
        ['/print-jobs/'.$testJob->id.'/retry', []],
    ]);
    $codes = array_column($results, 'status');
    sort($codes);
    $check($codes === [200, 422], 'Concurrent retry must permit exactly one transmission.');
    $received = stream_socket_accept($listener, 2);
    $check($received !== false, 'The local test receiver must get a connection.');
    try {
        stream_set_timeout($received, 2);
        $check(stream_get_contents($received) === $testJob->payload, 'Retry must transmit exactly the saved payload.');
    } finally {
        fclose($received);
    }
    $duplicate = @stream_socket_accept($listener, 0);
    if ($duplicate !== false) {
        fclose($duplicate);
    }
    $check($duplicate === false, 'There must not be a second printer connection.');
    $testJob->refresh();
    $check($testJob->status === 'PRINTED' && $testJob->attempts === 3 && $testJob->printed_at !== null, 'Retry success must persist once.');
    $check(Order::orderBy('id')->get()->toArray() === $ordersBefore, 'Retry must not change orders or payments.');
    $check(InventoryStock::orderBy('id')->get()->toArray() === $stockBefore, 'Retry must not change stock.');
    $check(StockTransaction::count() === $historyBefore, 'Retry must not create stock transactions.');
} finally {
    fclose($listener);
}
