<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

class CommandPrefixPolicyTest extends TestCase
{
    public function test_package_console_commands_use_ai_prefix(): void
    {
        $paths = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../../src/Console/Commands', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($paths as $pathInfo) {
            if (! $pathInfo->isFile() || $pathInfo->getExtension() !== 'php') {
                continue;
            }

            $path = $pathInfo->getPathname();
            $contents = file_get_contents($path) ?: '';

            if (! preg_match('/protected \$signature = \'([^ \'\n]+)/', $contents, $matches)) {
                continue;
            }

            $this->assertStringStartsWith('ai:', $matches[1], basename($path).' should use the ai: command prefix.');
            $oldPrefix = 'ai-engine'.':';

            $this->assertFalse(str_starts_with($matches[1], $oldPrefix), basename($path).' should not use the old package command prefix.');
            $this->assertFalse(str_starts_with($matches[1], 'vector:'), basename($path).' should not use the vector: command prefix.');
        }
    }
}
