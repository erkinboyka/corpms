<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TEST-06: случайный сетевой вызов в тесте — это ошибка теста, а не
        // «тихий» внешний запрос. Интеграции явно фейкаются через Http::fake().
        Http::preventStrayRequests();
    }
}
