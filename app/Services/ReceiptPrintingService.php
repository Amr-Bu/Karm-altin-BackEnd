<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReceiptPrintingService
{
    public function printReceipt(Order $order): array
    {
        [$order, $job, $printer] = DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id)->load(OrderDataService::RELATIONS);
            $printers = Printer::where('printer_type', 'RECEIPT')->where('is_active', true)->get();
            if ($printers->count() !== 1) {
                throw new InvalidOrderStateException('يجب تحديد طابعة إيصالات نشطة واحدة فقط');
            }
            $printer = $printers->first();
            $lines = ['إيصال الطلب: '.$order->order_number, 'الطاولة: '.$order->table->table_number,
                'الموظف: '.$order->employee->name, 'تاريخ الطلب: '.$order->created_at->format('Y-m-d H:i:s'),
                'وقت الطباعة: '.now()->format('Y-m-d H:i:s'), 'حالة الدفع: '.($order->payment_status === 'PAID' ? 'مدفوع' : 'غير مدفوع')];
            foreach ($order->orderItems->where('status', 'ACTIVE') as $item) {
                $lines[] = $item->product->name.' | '.$item->quantity.' × '.$item->unit_price.' = '.$item->subtotal;
            }
            $lines[] = 'الكراسي: '.$order->chairs_count.' × '.$order->chair_price.' = '.$order->chairs_total;
            $lines[] = 'الإجمالي: '.$order->total_amount;
            $job = PrintJob::create(['order_id' => $order->id, 'department_id' => null, 'printer_id' => $printer->id,
                'job_type' => 'RECEIPT', 'status' => 'PENDING', 'attempts' => 0, 'payload' => implode("\n", $lines)."\n"]);

            return [$order, $job, $printer];
        }, 3);

        // External I/O is deliberately outside the DB transaction: never retry a physical print on a deadlock.
        if ($printer->connection_type !== 'NETWORK') {
            Log::info('Receipt awaiting a local print agent.', ['print_job_id' => $job->id, 'connection_type' => $printer->connection_type]);
        } else {
            try {
                $this->send($printer, $job->payload);
                $job->update(['attempts' => $job->attempts + 1, 'status' => 'PRINTED', 'printed_at' => now()]);
            } catch (Throwable $exception) {
                $job->update(['attempts' => $job->attempts + 1, 'status' => 'FAILED']);
                Log::warning('Receipt printer connection failed.', ['print_job_id' => $job->id, 'error' => $exception->getMessage()]);
            }
        }

        return ['order' => $order, 'print_job' => $job->fresh()];
    }

    private function send(Printer $printer, string $payload): void
    {
        $config = json_decode($printer->connection_config ?? '', true);
        if (is_array($config)) {
            $ip = $config['ip'] ?? '';
            $port = $config['port'] ?? 9100;
        } else {
            [$ip, $port] = array_pad(explode(':', $printer->connection_config ?? '', 2), 2, 9100);
        }
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP) || ! filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
            throw new RuntimeException('Invalid printer IP/port configuration.');
        }
        $host = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $code, $error, 2);
        if ($socket === false) {
            throw new RuntimeException('Printer connection failed: '.$error);
        }
        try {
            stream_set_timeout($socket, 2);
            $offset = 0;
            $deadline = microtime(true) + 3;
            while ($offset < strlen($payload)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Printer write deadline exceeded.');
                }
                $written = @fwrite($socket, substr($payload, $offset));
                if ($written === false || $written === 0 || stream_get_meta_data($socket)['timed_out']) {
                    throw new RuntimeException('Printer write failed or timed out.');
                }
                $offset += $written;
            }
        } finally {
            fclose($socket);
        }
    }
}
