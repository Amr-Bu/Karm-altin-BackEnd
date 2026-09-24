<?php

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderStateException;
use App\Exceptions\OrderAlreadyPaidException;
use App\Exceptions\TableUnavailableException;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'active' => EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            InsufficientStockException::class,
            OrderAlreadyPaidException::class,
            InvalidOrderStateException::class,
            TableUnavailableException::class,
        ]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (InsufficientStockException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'shortages' => $exception->shortages], 422);
        });
        $exceptions->render(function (OrderAlreadyPaidException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        });
        $exceptions->render(function (InvalidOrderStateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        });
        $exceptions->render(function (TableUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        });
    })->create();
