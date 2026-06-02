<?php

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use LaravelAIEngine\Services\Vector\Drivers\QdrantDriver;
use LaravelAIEngine\Tests\TestCase;

class QdrantDriverTimeoutTest extends TestCase
{
    public function test_string_timeout_does_not_throw_type_error(): void
    {
        // env-sourced config arrives as a string; the int-typed property must not
        // cause a TypeError that would take down the whole vector driver (and RAG).
        $driver = new QdrantDriver([
            'host' => 'http://localhost:6333',
            'timeout' => '45',
        ]);

        $reflection = new \ReflectionProperty($driver, 'timeout');
        $reflection->setAccessible(true);

        $this->assertSame(45, $reflection->getValue($driver));
    }
}
