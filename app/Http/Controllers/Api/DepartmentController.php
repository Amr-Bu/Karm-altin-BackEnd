<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    public function __construct(private DepartmentService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection($this->service->list());
    }

    public function store(CreateDepartmentRequest $request): JsonResponse
    {
        return (new DepartmentResource($this->service->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        return new DepartmentResource($this->service->update($department, $request->validated()));
    }
}
