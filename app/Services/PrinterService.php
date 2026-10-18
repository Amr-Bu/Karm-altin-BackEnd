<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Printer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PrinterService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Printer::orderBy('id')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function create(array $input): Printer
    {
        return Printer::create($input + ['is_active' => true]);
    }

    public function update(Printer $printer, array $input): Printer
    {
        return DB::transaction(function () use ($printer, $input) {
            $printer = Printer::lockForUpdate()->findOrFail($printer->id);
            if (($input['printer_type'] ?? $printer->printer_type) !== Printer::TYPE_DEPARTMENT && $printer->departments()->exists()) {
                throw new InvalidOrderStateException('لا يمكن تحويل طابعة مرتبطة بقسم إلى طابعة إيصالات');
            }
            $printer->update($input);

            return $printer;
        }, 3);
    }
}
