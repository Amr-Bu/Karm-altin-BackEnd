<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\ListProductsRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProductStatusRequest;
use App\Http\Resources\CategoryCatalogResource;
use App\Http\Resources\ProductDetailsResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(ListProductsRequest $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->products->list($request->validated()));
    }

    public function catalog(\App\Services\OrderDataService $orderData): AnonymousResourceCollection
    {
        $chairPrice = \App\Models\Setting::where('key', 'chair_price')->value('value');

        return CategoryCatalogResource::collection($this->products->catalog())->additional([
            'meta' => ['pricing' => ['chair_price' => $chairPrice === null ? null : $orderData->money($chairPrice)]],
        ]);
    }

    public function show(Product $product): ProductDetailsResource
    {
        return new ProductDetailsResource($this->products->details($product));
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        return (new ProductDetailsResource($this->products->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductDetailsResource
    {
        return new ProductDetailsResource($this->products->update($product, $request->validated()));
    }

    public function status(UpdateProductStatusRequest $request, Product $product): ProductDetailsResource
    {
        return new ProductDetailsResource($this->products->setStatus($product, (bool) $request->validated('is_active')));
    }
}
