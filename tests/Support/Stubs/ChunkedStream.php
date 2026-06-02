<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Support\Stubs;

use Psr\Http\Message\StreamInterface;

/**
 * A test PSR-7 stream that returns a predefined list of byte chunks, one per
 * read() call, ignoring the requested length. This reproduces real-world SSE
 * streaming where event lines are split across read boundaries (or packed
 * multiple-per-read), letting tests prove the reader buffers lines correctly.
 */
final class ChunkedStream implements StreamInterface
{
    /** @var list<string> */
    private array $chunks;

    private int $index = 0;

    /**
     * @param  list<string>  $chunks
     */
    public function __construct(array $chunks)
    {
        $this->chunks = array_values($chunks);
    }

    public function read(int $length): string
    {
        if ($this->index >= count($this->chunks)) {
            return '';
        }

        return $this->chunks[$this->index++];
    }

    public function eof(): bool
    {
        return $this->index >= count($this->chunks);
    }

    public function __toString(): string
    {
        return implode('', array_slice($this->chunks, $this->index));
    }

    public function close(): void
    {
        $this->index = count($this->chunks);
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return array_sum(array_map('strlen', $this->chunks));
    }

    public function tell(): int
    {
        return $this->index;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('ChunkedStream is not seekable.');
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('ChunkedStream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        $contents = $this->__toString();
        $this->index = count($this->chunks);

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
