<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderQueryService
{
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $query = Order::with(['table', 'employee'])->orderByDesc('created_at')->orderByDesc('id');
        if ($user->role !== User::ROLE_ADMIN) {
            $query->where('employee_id', $user->id);
        }
        foreach (['order_status', 'payment_status', 'employee_id', 'table_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (isset($filters[$field])) {
                $query->whereDate('created_at', $operator, $filters[$field]);
            }
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('order_number', 'like', '%'.addcslashes($filters['search'], '\\%_').'%');
        }

        return $query->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function details(Order $order, User $user): Order
    {
        abort_unless($user->role === User::ROLE_ADMIN || $order->employee_id === $user->id, 403);

        return $order->load(OrderDataService::RELATIONS);
    }
}
