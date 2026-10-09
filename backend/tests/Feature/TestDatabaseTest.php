<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestDatabaseGuard;

uses(RefreshDatabase::class);

it('roda em PostgreSQL com banco terminado em _test e extensão vector', function () {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('pgsql');
    expect($connection->getDatabaseName())->toEndWith('_test');

    $vector = DB::selectOne("select extname from pg_extension where extname = 'vector'");
    expect($vector?->extname)->toBe('vector');

    fwrite(STDERR, PHP_EOL.'[teste] banco='.$connection->getDatabaseName().' driver='.$connection->getDriverName().PHP_EOL);
});

it('a guarda rejeita sqlite e bancos que não terminam em _test', function () {
    expect(fn () => TestDatabaseGuard::assertSafe('sqlite', ':memory:'))->toThrow(RuntimeException::class, 'SUÍTE ABORTADA');
    $testDb = (string) config('database.connections.pgsql.database');
    $devDb = Str::beforeLast($testDb, '_test');

    expect(fn () => TestDatabaseGuard::assertSafe('pgsql', $devDb))->toThrow(RuntimeException::class, 'SUÍTE ABORTADA');
    expect(fn () => TestDatabaseGuard::assertSafe(null, null))->toThrow(RuntimeException::class);
    TestDatabaseGuard::assertSafe('pgsql', $testDb);
    expect(true)->toBeTrue();
});
