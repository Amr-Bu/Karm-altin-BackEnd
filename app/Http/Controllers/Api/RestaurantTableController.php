<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTableRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\UpdateTableRequest;
use App\Http\Resources\RestaurantTableResource;
use App\Models\RestaurantTable;
use App\Services\RestaurantTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantTableController extends Controller
{
    public function __construct(private RestaurantTableService $service) {}

    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return RestaurantTableResource::collection($this->service->list($request->validated()));
    }

    public function store(CreateTableRequest $request): JsonResponse
    {
        return (new RestaurantTableResource($this->service->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateTableRequest $request, RestaurantTable $table): RestaurantTableResource
    {
        return new RestaurantTableResource($this->service->update($table, $request->validated()));
    }
}
