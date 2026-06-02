<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Services\AIEngineService;

/**
 * Streams provider chunks for an agent's final response, mirroring each token
 * into the agent event stream (FINAL_RESPONSE_TOKEN_STREAMED /
 * FINAL_RESPONSE_STREAM_COMPLETED).
 *
 * NOTE: This is a registered, public singleton service that is intentionally
 * available but NOT yet wired into the default runtime flow. Conversational
 * replies currently go through the synchronous AgentRuntimeContract::process()
 * path in RunAgentJob, which yields a single fully-formed AgentResponse with no
 * token-streaming seam. Threading a generator through process() ->
 * RunAgentJob::complete() would be invasive and is deferred. Until that seam
 * exists, callers may use this service directly to obtain streamed final
 * responses (it should be invoked behind a streaming flag once wired). It is
 * deliberately retained because removing a registered public service would be a
 * breaking change.
 */
class AgentFinalResponseStreamingService
{
    public function __construct(
        protected AIEngineService $aiEngine,
        protected AgentRunEventStreamService $events
    ) {}

    /**
     * Stream provider chunks while mirroring every final-response token into the agent event stream.
     */
    public function stream(
        AIRequest $request,
        AIAgentRun|int|string|null $run = null,
        AIAgentRunStep|int|string|null $step = null,
        array $metadata = [],
        ?callable $sink = null
    ): \Generator {
        $request = $request->withStreaming(true);
        $fullResponse = '';
        $index = 0;

        foreach ($this->aiEngine->stream($request) as $chunk) {
            $token = $this->normalizeChunk($chunk);

            if ($token === '') {
                continue;
            }

            $fullResponse .= $token;
            $index++;

            $this->events->emit(
                AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
                $run,
                $step,
                [
                    'token' => $token,
                    'index' => $index,
                    'length' => strlen($token),
                ],
                $metadata,
                $sink
            );

            yield $token;
        }

        $this->events->emit(
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED,
            $run,
            $step,
            [
                'content' => $fullResponse,
                'token_count' => $index,
                'length' => strlen($fullResponse),
            ],
            $metadata,
            $sink
        );
    }

    protected function normalizeChunk(mixed $chunk): string
    {
        if ($chunk instanceof AIResponse) {
            return $chunk->getContent();
        }

        if (is_array($chunk)) {
            return (string) ($chunk['content'] ?? $chunk['text'] ?? $chunk['delta'] ?? '');
        }

        if (is_scalar($chunk) || $chunk instanceof \Stringable) {
            return (string) $chunk;
        }

        return '';
    }
}
