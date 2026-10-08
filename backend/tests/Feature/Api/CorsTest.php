<?php

it('preflight de origem não permitida não recebe Access-Control-Allow-Origin', function () {
    $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);
    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('requisição com Origin não permitida não recebe ACAO (nunca *)', function () {
    $response = $this->getJson('/api/v1/health', ['Origin' => 'https://evil.example']);
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('origem configurada em CORS_ALLOWED_ORIGINS é aceita, sem credentials', function () {
    config(['cors.allowed_origins' => ['https://app.exemplo.com']]);
    $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
        'HTTP_ORIGIN' => 'https://app.exemplo.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.exemplo.com');
    expect($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});
