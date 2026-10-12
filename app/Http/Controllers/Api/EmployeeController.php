<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeRequest;
use App\Http\Requests\ListEmployeesRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeStatusRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use App\Services\EmployeeCreationService;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeCreationService $creation, private EmployeeService $employees) {}

    public function index(ListEmployeesRequest $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->employees->list($request->validated()));
    }

    public function update(UpdateEmployeeRequest $request, User $employee): EmployeeResource
    {
        return new EmployeeResource($this->employees->update($employee, $request->validated()));
    }

    public function status(UpdateEmployeeStatusRequest $request, User $employee): EmployeeResource
    {
        return new EmployeeResource($this->employees->status($employee, $request->boolean('is_active'), $request->user()->id));
    }

    public function store(CreateEmployeeRequest $request): JsonResponse
    {
        return (new EmployeeResource($this->creation->create($request->validated())))->response()->setStatusCode(201);
    }
}
