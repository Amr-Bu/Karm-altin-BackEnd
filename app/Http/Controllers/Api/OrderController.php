<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderApprovalService;
use App\Services\OrderCancellationService;
use App\Services\OrderCreationService;
use App\Services\OrderUpdateService;
use App\Services\PaymentService;
use App\Services\ReceiptPrintingService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(
        private OrderCreationService $creation,
        private OrderApprovalService $approval,
        private OrderUpdateService $updating,
        private OrderCancellationService $cancellation,
        private PaymentService $payment,
        private ReceiptPrintingService $receipts,
    ) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        return (new OrderResource($this->creation->create($request->validated())))->response()->setStatusCode(201);
    }

    public function approve(Order $order): OrderResource
    {
        return new OrderResource($this->approval->approve($order));
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        return new OrderResource($this->updating->update($order, $request->validated()));
    }

    public function cancel(Order $order): OrderResource
    {
        return new OrderResource($this->cancellation->cancel($order));
    }

    public function pay(Order $order): OrderResource
    {
        return new OrderResource($this->payment->pay($order));
    }

    public function printReceipt(Order $order): JsonResponse
    {
        $result = $this->receipts->printReceipt($order);

        return response()->json(['data' => new OrderResource($result['order']), 'print_job' => $result['print_job']]);
    }
}
