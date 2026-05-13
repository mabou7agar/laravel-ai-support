<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Architecture;

use LaravelAIEngine\Tests\UnitTestCase;

class ControllerArchitectureTest extends UnitTestCase
{
    public function test_controllers_do_not_directly_access_package_models(): void
    {
        $controllers = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../src/Http/Controllers')
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $controllers[] = $file->getPathname();
            }
        }

        $violations = [];
        foreach ($controllers as $controller) {
            $contents = (string) file_get_contents($controller);

            if (str_contains($contents, 'use LaravelAIEngine\\Models\\')) {
                $violations[] = $controller . ' imports a package model.';
            }

            if (preg_match('/::(query|where|create|find|updateOrCreate|firstOrCreate)\s*\(/', $contents) === 1) {
                $violations[] = $controller . ' calls Eloquent statically.';
            }
        }

        $this->assertSame([], $violations);
    }
}
