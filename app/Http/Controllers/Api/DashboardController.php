<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardSummaryRequest;
use App\Http\Resources\DashboardSummaryResource;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard) {}

    public function summary(DashboardSummaryRequest $request): DashboardSummaryResource
    {
        return new DashboardSummaryResource($this->dashboard->summary($request->validated()));
    }
}
