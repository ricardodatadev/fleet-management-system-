<?php

use Illuminate\Support\Str;

it('gera X-Request-Id uuid quando ausente', function () {
    $id = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
    expect(Str::isUuid($id))->toBeTrue();
});

it('propaga X-Request-Id válido', function () {
    $given = (string) Str::uuid();
    $this->getJson('/api/v1/health', ['X-Request-Id' => $given])->assertHeader('X-Request-Id', $given);
});

it('substitui X-Request-Id inválido', function () {
    $response = $this->getJson('/api/v1/health', ['X-Request-Id' => 'abc; DROP TABLE']);
    $id = $response->headers->get('X-Request-Id');
    expect($id)->not->toBe('abc; DROP TABLE')->and(Str::isUuid($id))->toBeTrue();
});

it('presente também em 404 e 405', function () {
    expect($this->getJson('/api/v1/xyz')->headers->has('X-Request-Id'))->toBeTrue();
    expect($this->postJson('/api/v1/health')->headers->has('X-Request-Id'))->toBeTrue();
});
