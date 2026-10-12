<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingRequest;
use App\Http\Resources\SettingResource;
use App\Services\SettingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SettingController extends Controller
{
    public function __construct(private SettingService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return SettingResource::collection($this->service->list());
    }

    public function update(UpdateSettingRequest $request, string $key): SettingResource
    {
        return new SettingResource($this->service->update($key, $request->validated('value')));
    }
}
