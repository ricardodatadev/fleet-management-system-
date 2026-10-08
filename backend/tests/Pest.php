<?php

use Tests\TestCase;

// A guarda do banco de testes está em Tests\TestCase::createApplication (roda após o PHPUnit aplicar o
// phpunit.xml e antes de qualquer trait de banco).
pest()->extend(TestCase::class)->in('Feature');
