<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Antes de qualquer trait (RefreshDatabase etc.) tocar no banco.
        TestDatabaseGuard::assertSafe(
            config('database.default'),
            config('database.connections.'.config('database.default').'.database'),
        );

        return $app;
    }
}
