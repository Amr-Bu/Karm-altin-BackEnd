<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\RestaurantTable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestaurantTableService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        return RestaurantTable::with(['orders' => fn ($query) => $query->whereNotIn('order_status', ['CLOSED', 'CANCELLED'])
            ->select(['id', 'table_id', 'order_number', 'order_status', 'payment_status'])])->orderBy('table_number')->orderBy('id')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function create(array $input): RestaurantTable
    {
        try {
            return RestaurantTable::create($input + ['status' => RestaurantTable::STATUS_AVAILABLE, 'is_active' => true])->load('orders');
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['table_number' => [__('validation.unique', ['attribute' => 'table number'])]]);
        }
    }

    public function update(RestaurantTable $table, array $input): RestaurantTable
    {
        try {
            return DB::transaction(function () use ($table, $input) {
                // Order creation takes this same table lock before occupying it.
                $table = RestaurantTable::lockForUpdate()->findOrFail($table->id);
                $table->load(['orders' => fn ($query) => $query->whereNotIn('order_status', ['CLOSED', 'CANCELLED'])]);
                if (array_key_exists('is_active', $input) && ! $input['is_active']
                    && ($table->status === RestaurantTable::STATUS_OCCUPIED || $table->orders->isNotEmpty())) {
                    throw new InvalidOrderStateException('لا يمكن تعطيل طاولة مشغولة أو مرتبطة بطلب نشط');
                }
                $table->update($input);

                return $table;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['table_number' => [__('validation.unique', ['attribute' => 'table number'])]]);
        }
    }
}
