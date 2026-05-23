<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

use Symfony\Component\Process\Process;

class GetDesignCliRunner
{
    public function add(string $slug): string
    {
        if (!(bool) config('ai-engine.learning.adapters.getdesign.allow_cli', false)) {
            throw new \RuntimeException('getdesign CLI learning is disabled. Enable it explicitly and configure a pinned command before using getdesign slugs.');
        }

        $slug = trim($slug);
        if ($slug === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $slug)) {
            throw new \InvalidArgumentException('getdesign slug must contain only letters, numbers, dots, underscores, and dashes.');
        }

        $workdir = $this->makeTempDirectory();

        try {
            $command = array_values(array_filter(
                (array) config('ai-engine.learning.adapters.getdesign.command', []),
                static fn (mixed $part): bool => is_scalar($part) && trim((string) $part) !== ''
            ));
            if ($command === []) {
                throw new \RuntimeException('No getdesign CLI command is configured.');
            }

            $process = new Process([...$command, $slug], $workdir, null, null, (int) config('ai-engine.learning.adapters.getdesign.timeout', 120));
            $process->mustRun();

            $path = $workdir . DIRECTORY_SEPARATOR . (string) config('ai-engine.learning.adapters.getdesign.output_file', 'DESIGN.md');
            if (!is_file($path)) {
                throw new \RuntimeException("getdesign did not create DESIGN.md for slug [{$slug}].");
            }

            $content = file_get_contents($path);
            if (!is_string($content) || trim($content) === '') {
                throw new \RuntimeException("getdesign returned an empty DESIGN.md for slug [{$slug}].");
            }

            return $content;
        } finally {
            $this->deleteDirectory($workdir);
        }
    }

    protected function makeTempDirectory(): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ai-engine-getdesign-' . bin2hex(random_bytes(6));

        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('Unable to create temporary getdesign workspace.');
        }

        return $base;
    }

    protected function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
