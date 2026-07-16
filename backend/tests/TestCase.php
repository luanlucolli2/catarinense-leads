<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'testing') {
            throw new \RuntimeException('Testes bloqueados fora do banco testing.');
        }

        parent::setUp();
    }
}
