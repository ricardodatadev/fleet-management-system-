<?php

use App\Http\Middleware\AuthorizePermission;
use App\Support\Rbac\Rbac;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/** @return list<LaravelRoute> */
function apiV1Routes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/')));
}

function routeLabel(LaravelRoute $route): string
{
    return implode('|', $route->methods()).' '.$route->uri();
}

it('toda rota api/v1 fora da allowlist declara auth:sanctum e can:<permissão conhecida>', function () {
    $problems = [];
    foreach (apiV1Routes() as $route) {
        if (in_array($route->uri(), RBAC_PUBLIC_ROUTES, true)) {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        $can = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:')));

        if (! in_array('auth:sanctum', $middleware, true)) {
            $problems[] = routeLabel($route).' sem auth:sanctum';
        }
        if ($can === []) {
            $problems[] = routeLabel($route).' sem can:<permissão>';
        }
        foreach ($can as $m) {
            $ability = explode(',', substr($m, 4))[0];
            if (! in_array($ability, Rbac::abilities(), true)) {
                $problems[] = routeLabel($route)." usa {$m}, que não é permissão da matriz E";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('a allowlist existe e é pública (sem auth nem can)', function () {
    $uris = array_map(fn (LaravelRoute $r) => $r->uri(), apiV1Routes());
    foreach (RBAC_PUBLIC_ROUTES as $uri) {
        expect($uris)->toContain($uri);
        $route = collect(apiV1Routes())->first(fn (LaravelRoute $r) => $r->uri() === $uri);
        expect(collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'can:'))))->toBeEmpty();
    }
});

it('a varredura falha se surgir rota sem permissão declarada', function () {
    Route::middleware(['api', 'auth:sanctum'])->get('api/v1/_t/sem-permissao', fn () => 'x');

    $offending = collect(apiV1Routes())->filter(fn (LaravelRoute $r) => $r->uri() === 'api/v1/_t/sem-permissao')
        ->filter(fn (LaravelRoute $r) => collect($r->gatherMiddleware())->every(fn ($m) => ! is_string($m) || ! str_starts_with($m, 'can:')));

    expect($offending)->toHaveCount(1);
});

it('can: é o AuthorizePermission e roda depois da autenticação e antes do SubstituteBindings', function () {
    $router = app('router');
    expect($router->getMiddleware()['can'])->toBe(AuthorizePermission::class);

    $route = $router->getRoutes()->getByName('api.auth.me');
    $sorted = array_map(fn ($m) => is_string($m) ? explode(':', $m)[0] : $m, $router->gatherRouteMiddleware($route));
    $auth = array_search(Authenticate::class, $sorted, true);
    $can = array_search(AuthorizePermission::class, $sorted, true);
    $bindings = array_search(SubstituteBindings::class, $sorted, true);

    expect($auth)->toBeInt()->and($can)->toBeInt()->and($bindings)->toBeInt();
    expect($auth < $can && $can < $bindings)->toBeTrue(implode(' > ', $sorted));
});
