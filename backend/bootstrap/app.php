<?php

use App\Exceptions\AppException;
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
        $middleware->statefulApi();
    })
    ->withProviders([
        App\Modules\Auth\Providers\AuthServiceProvider::class,
        App\Modules\Organizations\Providers\OrganizationsServiceProvider::class,
        App\Modules\Documents\Providers\DocumentsServiceProvider::class,
        App\Modules\Compliance\Providers\ComplianceServiceProvider::class,
        App\Modules\AI\Providers\AIServiceProvider::class,
        App\Modules\Superadmin\Providers\SuperadminServiceProvider::class,
        App\Modules\Workflow\Providers\WorkflowServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AppException $e, Request $request) {
            return response()->json($e->toArray(), $e->statusCode);
        });
    })->create();
