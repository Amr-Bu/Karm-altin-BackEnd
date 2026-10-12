<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPrintJobsRequest;
use App\Http\Resources\PrintJobResource;
use App\Models\PrintJob;
use App\Services\PrintJobService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrintJobController extends Controller
{
    public function __construct(private PrintJobService $jobs) {}

    public function index(ListPrintJobsRequest $request): AnonymousResourceCollection
    {
        return PrintJobResource::collection($this->jobs->list($request->validated()));
    }

    public function retry(PrintJob $printJob): PrintJobResource
    {
        return new PrintJobResource($this->jobs->retry($printJob));
    }
}
