<?php

/**
 * Marca (F1-33): nome exibido e identificadores técnicos vêm do .env; os prefixos de
 * cache/Redis/sessão/Horizon derivam do APP_SLUG, nunca do APP_NAME (trocar o nome
 * exibido não pode mudar chaves nem invalidar sessões).
 */
function withEnv(array $vars, callable $fn): mixed
{
    $previous = [];
    foreach ($vars as $key => $value) {
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        $_SERVER[$key] = $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return $fn();
    } finally {
        foreach ($previous as $key => [$server, $env, $put]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            putenv($put === false ? $key : "{$key}={$put}");
        }
    }
}

/** @return array<string, string> */
function brandPrefixes(): array
{
    return [
        'cache' => (require config_path('cache.php'))['prefix'],
        'redis' => (require config_path('database.php'))['redis']['options']['prefix'],
        'session' => (require config_path('session.php'))['cookie'],
        'horizon' => (require config_path('horizon.php'))['prefix'],
    ];
}

it('expõe nome, nome completo e slug no config app', function () {
    expect(config('app.name'))->toBeString()->not->toBeEmpty();
    expect(config('app.full_name'))->toBeString()->not->toBeEmpty();
    expect(config('app.slug'))->toMatch('/^[a-z0-9][a-z0-9-]*$/');
});

it('prefixos de cache, Redis, sessão e Horizon derivam do slug, não do nome exibido', function () {
    $env = ['APP_SLUG' => 'frota-x'];

    $before = withEnv($env + ['APP_NAME' => 'Frota X'], fn () => brandPrefixes());
    $after = withEnv($env + ['APP_NAME' => 'Outro Nome'], fn () => brandPrefixes());

    expect($before)->toBe([
        'cache' => 'frota-x-cache-',
        'redis' => 'frota-x-database-',
        'session' => 'frota-x-session',
        'horizon' => 'frota-x_horizon:',
    ]);
    expect($after)->toBe($before);
});
