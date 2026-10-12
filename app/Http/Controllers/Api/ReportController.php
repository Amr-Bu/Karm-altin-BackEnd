<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialReportRequest;
use App\Http\Requests\TopProductsReportRequest;
use App\Http\Resources\FinancialReportResource;
use App\Http\Resources\TopProductResource;
use App\Services\FinancialReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    public function __construct(private FinancialReportService $reports) {}

    public function financial(FinancialReportRequest $request): FinancialReportResource
    {
        return new FinancialReportResource($this->reports->financial($request->validated()));
    }

    public function topProducts(TopProductsReportRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return TopProductResource::collection($this->reports->topProducts($this->reports->period($filters), $filters['limit'] ?? 10));
    }
}
