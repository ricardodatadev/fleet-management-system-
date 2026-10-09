<?php

use App\Support\Api\OpenApi\OpenApiBuilder;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

const OPENAPI_HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

function openApiSpec(): array
{
    $path = OpenApiBuilder::versionedPath();
    expect(file_exists($path))->toBeTrue("docs/api/openapi.json não existe em {$path} (rode `composer openapi`).");

    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<string> "METHOD /path" das rotas api/v1 registradas (sem HEAD/OPTIONS). */
function registeredApiRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') && $uri !== 'api/v1') {
            continue;
        }
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }
            $routes[] = $method.' /'.substr($uri, strlen('api/v1/'));
        }
    }
    sort($routes);

    return $routes;
}

/** @return list<string> */
function documentedOperations(array $spec): array
{
    $ops = [];
    foreach ($spec['paths'] as $path => $item) {
        foreach (OPENAPI_HTTP_METHODS as $method) {
            if (isset($item[$method])) {
                $ops[] = strtoupper($method).' '.$path;
            }
        }
    }
    sort($ops);

    return $ops;
}

it('é OpenAPI 3.0.x com info, servidor /api/v1 e bearerAuth', function () {
    $spec = openApiSpec();
    expect($spec['openapi'])->toMatch('/^3\.0\.\d+$/');
    expect($spec['info']['title'])->not->toBeEmpty();
    expect($spec['servers'][0]['url'])->toBe('/api/v1');
    expect($spec['components']['securitySchemes']['bearerAuth'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer']);
    expect($spec['components']['schemas'])->toHaveKeys(['Envelope', 'Pagination', 'ErrorEnvelope', 'ValidationErrorEnvelope']);
});

it('todas as rotas api/v1 registradas estão documentadas no openapi.json', function () {
    $missing = array_values(array_diff(registeredApiRoutes(), documentedOperations(openApiSpec())));

    expect($missing)->toBe([], 'Rotas SEM documentação OpenAPI: '.implode(', ', $missing));
});

it('o openapi.json não documenta rotas inexistentes', function () {
    $stale = array_values(array_diff(documentedOperations(openApiSpec()), registeredApiRoutes()));

    expect($stale)->toBe([], 'Documentadas mas não registradas: '.implode(', ', $stale));
});

it('o openapi.json versionado é igual ao gerado (spec atualizada)', function () {
    $generated = app(OpenApiBuilder::class)->build();
    $versioned = file_get_contents(OpenApiBuilder::versionedPath());

    expect($versioned)->toBe($generated, 'docs/api/openapi.json está desatualizado: rode `composer openapi` (make openapi).');
});

it('título e descrição da OpenAPI vêm da marca do config, sem marcador cru', function () {
    $info = openApiSpec()['info'];
    expect($info['title'])->toBe(config('app.name').' API');
    expect($info['description'])->toContain(config('app.name'))->toContain(config('app.full_name'));
    expect($info['title'].$info['description'])->not->toContain('{app_');

    config(['app.name' => 'Frota X', 'app.full_name' => 'Frota X Operações']);
    $rebranded = json_decode(app(OpenApiBuilder::class)->build(), true, 512, JSON_THROW_ON_ERROR)['info'];
    expect($rebranded['title'])->toBe('Frota X API');
    expect($rebranded['description'])->toStartWith('API REST do Frota X (Frota X Operações).');
});

it('Swagger UI não existe com L5_SWAGGER_ENABLED=false (404 no envelope)', function () {
    $this->getJson('/api/documentation')->assertNotFound()->assertJsonPath('status', 'error');
    $this->getJson('/api/documentation/spec')->assertNotFound();
});
