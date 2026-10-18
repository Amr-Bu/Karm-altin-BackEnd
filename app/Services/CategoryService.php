<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function list(array $filters): Collection
    {
        $query = Category::orderBy('id');
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('name', 'like', '%'.addcslashes($filters['search'], '\\%_').'%');
        }

        return $query->get();
    }

    public function details(Category $category): Category
    {
        return $category->load(['products' => fn ($query) => $query->with(['department', 'images'])->orderBy('id')]);
    }

    public function create(array $input): Category
    {
        try {
            return Category::create(['name' => $input['name'], 'is_active' => true]);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => 'name'])]]);
        }
    }

    public function update(Category $category, array $input): Category
    {
        try {
            return DB::transaction(function () use ($category, $input) {
                $category = Category::lockForUpdate()->findOrFail($category->id);
                $category->update($input);

                return $category;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => 'name'])]]);
        }
    }
}
