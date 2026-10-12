<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\PrintJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrintJobService
{
    public function __construct(private NetworkPrinterTransport $transport) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $query = PrintJob::with(['order', 'department', 'printer'])->orderByDesc('created_at')->orderByDesc('id');
        foreach (['status', 'job_type', 'printer_id', 'department_id', 'order_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (isset($filters[$field])) {
                $query->whereDate('created_at', $operator, $filters[$field]);
            }
        }

        return $query->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function retry(PrintJob $printJob): PrintJob
    {
        $job = DB::transaction(function () use ($printJob) {
            $job = PrintJob::lockForUpdate()->findOrFail($printJob->id)->load('printer');
            if ($job->status !== PrintJob::STATUS_FAILED) {
                throw new InvalidOrderStateException('Only failed print jobs can be retried.');
            }
            if (! $job->printer->is_active) {
                throw new InvalidOrderStateException('The assigned printer is inactive.');
            }
            if (! in_array($job->printer->connection_type, ['NETWORK', 'LOCAL_AGENT', 'USB'], true)) {
                throw new InvalidOrderStateException('The printer connection type is unsupported.');
            }
            if ($job->printer->connection_type === 'NETWORK') {
                try {
                    $this->transport->validate($job->printer);
                } catch (Throwable $exception) {
                    throw new InvalidOrderStateException('Invalid printer IP/port configuration.');
                }
            }
            // Persist the claim before I/O. A concurrent retry observes PENDING and cannot send.
            $job->update(['status' => PrintJob::STATUS_PENDING, 'printed_at' => null,
                'attempts' => $job->attempts + ($job->printer->connection_type === 'NETWORK' ? 1 : 0)]);

            return $job;
        }, 3);

        if ($job->printer->connection_type !== 'NETWORK') {
            Log::info('Retried print job awaiting an external print agent.', ['print_job_id' => $job->id]);

            return $job->load(['order', 'department']);
        }

        // Do not wrap this call in a transaction or retry it on a database error.
        try {
            $this->transport->send($job->printer, $job->payload);
            $result = ['status' => PrintJob::STATUS_PRINTED, 'printed_at' => now()];
        } catch (Throwable $exception) {
            $result = ['status' => PrintJob::STATUS_FAILED, 'printed_at' => null];
            Log::warning('Print retry transmission failed.', ['print_job_id' => $job->id, 'error' => $exception->getMessage()]);
        }
        // A persistence failure after transmission leaves PENDING rather than permitting an
        // automatic duplicate print. The original payload and attempts are never regenerated.
        PrintJob::whereKey($job->id)->where('status', PrintJob::STATUS_PENDING)->where('attempts', $job->attempts)->update($result);

        return $job->fresh(['order', 'department', 'printer']);
    }
}
