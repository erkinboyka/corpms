<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class TestingIsolationTest extends TestCase
{
    public function test_stray_http_requests_are_blocked_in_all_tests(): void
    {
        $this->expectException(RuntimeException::class);

        Http::get('https://unregistered.example.test/must-not-be-called');
    }
}
