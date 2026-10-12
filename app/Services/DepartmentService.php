<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Department;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    public function list(): Collection
    {
        return Department::with('printer')->orderBy('id')->get();
    }

    public function create(array $input): Department
    {
        return $this->save(null, $input + ['is_active' => true]);
    }

    public function update(Department $department, array $input): Department
    {
        return $this->save($department, $input);
    }

    private function save(?Department $department, array $input): Department
    {
        try {
            return DB::transaction(function () use ($department, $input) {
                $department = $department ? Department::lockForUpdate()->findOrFail($department->id) : new Department;
                if (isset($input['printer_id'])) {
                    // Serialize assignment with printer type edits; recheck after acquiring the lock.
                    $printer = Printer::lockForUpdate()->findOrFail($input['printer_id']);
                    if ($printer->printer_type !== Printer::TYPE_DEPARTMENT) {
                        throw new InvalidOrderStateException('يجب ربط القسم بطابعة أقسام فقط');
                    }
                }
                $department->fill($input)->save();

                return $department->load('printer');
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => 'name'])]]);
        }
    }
}
