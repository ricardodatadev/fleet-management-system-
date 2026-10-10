<?php

namespace Tests\Support;

use RuntimeException;

/**
 * RefreshDatabase nunca pode tocar o banco de dev: a suíte só roda em PostgreSQL cujo nome termina em "_test".
 */
final class TestDatabaseGuard
{
    public static function assertSafe(?string $driver, ?string $database): void
    {
        if ($driver !== 'pgsql' || ! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'SUÍTE ABORTADA: os testes exigem PostgreSQL com banco terminado em "_test" (driver=%s, banco=%s). '.
                'Verifique DB_CONNECTION/DB_DATABASE em phpunit.xml; nunca rode a suíte contra o banco de dev.',
                $driver ?? 'null',
                $database ?? 'null',
            ));
        }
    }
}
