<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PrinterService
{
    public function list(): Collection
    {
        return Printer::orderBy('id')->get();
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
