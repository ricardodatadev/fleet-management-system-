<?php

use App\Support\Api\HealthChecker;

it('200 com db e redis up', function () {
    $body = $this->getJson('/api/v1/health')->assertOk()->json();
    expect($body['status'])->toBe('success');
    expect($body['data'])->toMatchArray(['app' => 'up', 'db' => 'up', 'redis' => 'up']);
    expect($body['data']['version'])->toBe('0.1.0');
});

it('503 com db down', function () {
    $this->mock(HealthChecker::class, function ($mock) {
        $mock->shouldReceive('database')->andReturn(false);
        $mock->shouldReceive('redis')->andReturn(true);
    });
    $body = $this->getJson('/api/v1/health')->assertStatus(503)->json();
    expect($body['status'])->toBe('error');
    expect($body['data'])->toMatchArray(['db' => 'down', 'redis' => 'up']);
    expect($body)->toHaveKeys(['status', 'message', 'errors', 'data']);
});

it('503 com redis down', function () {
    $this->mock(HealthChecker::class, function ($mock) {
        $mock->shouldReceive('database')->andReturn(true);
        $mock->shouldReceive('redis')->andReturn(false);
    });
    $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('data.redis', 'down');
});

it('meta/enums exige autenticação (401 no envelope); o 200 autenticado está em tests/Feature/Rbac', function () {
    $this->getJson('/api/v1/meta/enums')->assertUnauthorized()->assertJsonPath('status', 'error');
});

it('health não passa pelo throttle (precisa responder 503 com o Redis fora)', function () {
    $route = app('router')->getRoutes()->getByName('api.health');
    expect(app('router')->gatherRouteMiddleware($route))->not->toContain('throttle:api');
});
