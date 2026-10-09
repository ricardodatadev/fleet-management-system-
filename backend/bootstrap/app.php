<?php

use App\Exceptions\DomainConflictException;
use App\Http\Middleware\AuthorizePermission;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequestId;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Globais (rodam também em 404/405): RequestId primeiro, depois força JSON em /api/*.
        $middleware->prepend([RequestId::class, ForceJsonResponse::class]);
        $middleware->throttleApi('api');
        // can: = permissão checada ANTES do binding (403 tem precedência sobre o 404 do escopo de filial).
        $middleware->alias(['can' => AuthorizePermission::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuthorizePermission::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::validationError($e->errors(), __('api.422')) : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error(__('api.401'), 401) : null;
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error(__('api.403'), 403) : null;
        });
        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error(__('api.404'), 404) : null;
        });
        $exceptions->render(function (DomainConflictException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiResponse::error($e->getMessage(), 409, $e->errors) : null;
        });
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $response = ApiResponse::error(__('api.429'), 429);
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set($name, (string) $value);
            }

            return $response;
        });
        // Demais exceções HTTP (inclui 404 de rota, 405, 403 de abort(), ThrottleRequests → 429).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $code = $e->getStatusCode();
            $message = Lang::has("api.$code") ? __("api.$code") : __('api.generic');
            $response = ApiResponse::error($message, $code);
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set($name, (string) $value);
            }

            return $response;
        });
        // 500: nunca vaza trace/SQL/paths com APP_DEBUG=false.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $response = ApiResponse::error(__('api.500'), 500);
            if (config('app.debug')) {
                $payload = $response->getData(true);
                $payload['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
                $response->setData($payload);
            }

            return $response;
        });
    })->create();
