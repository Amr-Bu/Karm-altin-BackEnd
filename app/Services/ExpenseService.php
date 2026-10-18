<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Expense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Expense::with('creator')->orderByDesc('expense_date')->orderByDesc('id');
        foreach (['status', 'created_by'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (isset($filters[$field])) {
                $query->where('expense_date', $operator, $filters[$field]);
            }
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('name', 'like', '%'.addcslashes($filters['search'], '\\%_').'%');
        }

        return $query->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function create(array $input, int $adminId): Expense
    {
        return Expense::create([
            'name' => $input['name'], 'amount' => bcadd((string) $input['amount'], '0', 2),
            'note' => $input['note'] ?? null, 'expense_date' => $input['expense_date'],
            'created_by' => $adminId, 'status' => Expense::STATUS_ACTIVE,
        ])->load('creator');
    }

    public function update(Expense $expense, array $input): Expense
    {
        if (isset($input['amount'])) {
            $input['amount'] = bcadd((string) $input['amount'], '0', 2);
        }

        return $this->save($expense, $input);
    }

    public function cancel(Expense $expense): Expense
    {
        return $this->save($expense, ['status' => Expense::STATUS_CANCELLED]);
    }

    private function save(Expense $expense, array $input): Expense
    {
        return DB::transaction(function () use ($expense, $input) {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            if ($expense->status !== Expense::STATUS_ACTIVE) {
                throw new InvalidOrderStateException('Cancelled expenses cannot be edited or cancelled again.');
            }
            $expense->update($input);

            return $expense->load('creator');
        }, 3);
    }
}
