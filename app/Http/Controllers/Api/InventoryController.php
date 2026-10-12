<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStockMovementRequest;
use App\Http\Requests\ListInventoryRequest;
use App\Http\Requests\ListStockMovementsRequest;
use App\Http\Resources\InventoryResource;
use App\Http\Resources\StockMovementResource;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(ListInventoryRequest $request): AnonymousResourceCollection
    {
        return InventoryResource::collection($this->inventory->current($request->validated()));
    }

    public function movements(ListStockMovementsRequest $request): AnonymousResourceCollection
    {
        return StockMovementResource::collection($this->inventory->movements($request->validated()));
    }

    public function store(CreateStockMovementRequest $request): JsonResponse
    {
        [$movement, $quantity] = $this->inventory->createMovement($request->validated(), $request->user()->id);

        return (new StockMovementResource($movement))->withCurrentQuantity($quantity)->response()->setStatusCode(201);
    }
}
