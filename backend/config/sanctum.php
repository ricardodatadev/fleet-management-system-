<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
 * Fase 1: somente Bearer personal access tokens (D2). Sem autenticação stateful (cookie/sessão):
 * `guard` vazio e `stateful` vazio. Ver ADR-0001 (F1-09).
 */
return [

    'stateful' => [],

    'guard' => [],

    // Minutos de validade do token (12 h por padrão). Também gravado em personal_access_tokens.expires_at.
    'expiration' => (int) env('SANCTUM_EXPIRATION', 720),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
