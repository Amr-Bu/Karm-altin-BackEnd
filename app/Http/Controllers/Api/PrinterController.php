<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePrinterRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\UpdatePrinterRequest;
use App\Http\Resources\PrinterResource;
use App\Models\Printer;
use App\Services\PrinterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrinterController extends Controller
{
    public function __construct(private PrinterService $service) {}

    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return PrinterResource::collection($this->service->list($request->validated()));
    }

    public function store(CreatePrinterRequest $request): JsonResponse
    {
        return (new PrinterResource($this->service->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdatePrinterRequest $request, Printer $printer): PrinterResource
    {
        return new PrinterResource($this->service->update($printer, $request->validated()));
    }
}
