<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Concerns;

use Psr\Http\Message\StreamInterface;

/**
 * Shared helper for parsing Server-Sent Events (SSE) streams.
 *
 * Reads from a PSR-7 stream using a line buffer so that SSE events split
 * across read boundaries (or packed multiple-per-read) are reassembled into
 * complete "\n"-terminated lines before each "data:" event is decoded.
 */
trait ParsesSseStream
{
    /**
     * Iterate over the OpenAI-style "data:" content deltas in an SSE stream.
     *
     * @return \Generator<int, string>
     */
    protected function parseSseContentStream(StreamInterface $stream): \Generator
    {
        $buffer = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $jsonData = trim(substr($line, 5));
                if ($jsonData === '[DONE]') {
                    return;
                }

                $data = json_decode($jsonData, true);
                if (!is_array($data)) {
                    continue;
                }

                $content = $data['choices'][0]['delta']['content'] ?? null;
                if (is_string($content) && $content !== '') {
                    yield $content;
                }
            }
        }
    }
}
