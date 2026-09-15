<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Concerns;

use Psr\Http\Message\StreamInterface;

/**
 * Shared helper for parsing Server-Sent Events (SSE) streams.
 *
 * Reads from a PSR-7 stream using a line buffer so that SSE events split
 * across read boundaries (or packed multiple-per-read) are reassembled into
 * complete lines. Events are dispatched on a blank line (per the SSE spec),
 * and a trailing event without a final newline is still delivered.
 */
trait ParsesSseStream
{
    /**
     * Iterate over complete SSE events.
     *
     * Each event is ['event' => ?string, 'data' => string]; multi-line data
     * fields are joined with "\n". Comment lines (":") are ignored.
     *
     * @return \Generator<int, array{event: ?string, data: string}>
     */
    protected function parseSseEvents(StreamInterface $stream, int $readBytes = 8192): \Generator
    {
        $buffer = '';
        $event = null;
        $data = [];

        $dispatch = static function () use (&$event, &$data): ?array {
            if ($data === []) {
                $event = null;

                return null;
            }

            $payload = ['event' => $event, 'data' => implode("\n", $data)];
            $event = null;
            $data = [];

            return $payload;
        };

        $consumeLine = static function (string $line) use (&$event, &$data, $dispatch): ?array {
            $line = rtrim($line, "\r");

            if ($line === '') {
                return $dispatch();
            }

            if (str_starts_with($line, ':')) {
                return null;
            }

            [$field, $value] = str_contains($line, ':')
                ? explode(':', $line, 2)
                : [$line, ''];
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }

            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            }

            return null;
        };

        while (!$stream->eof()) {
            $chunk = $stream->read($readBytes);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 1);

                if (($complete = $consumeLine($line)) !== null) {
                    yield $complete;
                }
            }
        }

        if ($buffer !== '' && ($complete = $consumeLine($buffer)) !== null) {
            yield $complete;
        }

        if (($complete = $dispatch()) !== null) {
            yield $complete;
        }
    }

    /**
     * Iterate over the OpenAI-style "data:" content deltas in an SSE stream.
     *
     * @return \Generator<int, string>
     */
    protected function parseSseContentStream(StreamInterface $stream): \Generator
    {
        foreach ($this->parseSseEvents($stream) as $event) {
            // OpenAI-compatible providers put one JSON document per data line;
            // some omit the blank separator line, so decode line by line.
            foreach (explode("\n", $event['data']) as $jsonData) {
                $jsonData = trim($jsonData);
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
