<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequireStatefulRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ForceJsonResponse::class);
        $middleware->statefulApi();
        $middleware->alias(['admin' => EnsureAdmin::class, 'stateful' => RequireStatefulRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e, Request $request) => (new ApiExceptionRenderer)->render($e, $request));
    })->create();
