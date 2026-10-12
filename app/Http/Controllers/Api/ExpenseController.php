<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExpenseRequest;
use App\Http\Requests\ListExpensesRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    public function index(ListExpensesRequest $request): AnonymousResourceCollection
    {
        return ExpenseResource::collection($this->expenses->list($request->validated()));
    }

    public function store(CreateExpenseRequest $request): JsonResponse
    {
        return (new ExpenseResource($this->expenses->create($request->validated(), $request->user()->id)))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): ExpenseResource
    {
        return new ExpenseResource($this->expenses->update($expense, $request->validated()));
    }

    public function cancel(Expense $expense): ExpenseResource
    {
        return new ExpenseResource($this->expenses->cancel($expense));
    }
}
