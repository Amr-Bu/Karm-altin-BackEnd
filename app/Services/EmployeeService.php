<?php

namespace App\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function list(array $filters): Collection
    {
        $query = User::orderBy('id');
        foreach (['role', 'is_active'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.addcslashes($filters['search'], '\\%_').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhere('username', 'like', $search));
        }

        return $query->get();
    }

    public function update(User $employee, array $input): User
    {
        if (array_key_exists('password', $input)) {
            $input['password_hash'] = Hash::make($input['password']);
            unset($input['password']);
        }

        return $this->save($employee, $input);
    }

    public function status(User $employee, bool $active, int $adminId): User
    {
        if (! $active && $employee->id === $adminId) {
            throw new InvalidOrderStateException('You cannot deactivate your own account.');
        }

        return $this->save($employee, ['is_active' => $active]);
    }

    private function save(User $employee, array $input): User
    {
        try {
            return DB::transaction(function () use ($employee, $input) {
                // Lock in primary-key order before counting: concurrent demotions/deactivations
                // must observe the preceding committed change, including under REPEATABLE READ.
                $users = User::orderBy('id')->lockForUpdate()->get();
                $employee = $users->firstWhere('id', $employee->id);
                abort_unless($employee, 404);
                $employee->fill($input);
                if (! $users->contains(fn (User $user) => $user->is_active && $user->role === User::ROLE_ADMIN)) {
                    throw new InvalidOrderStateException('At least one active administrator must remain.');
                }
                $employee->save();

                return $employee;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['username' => [__('validation.unique', ['attribute' => 'username'])]]);
        }
    }
}
