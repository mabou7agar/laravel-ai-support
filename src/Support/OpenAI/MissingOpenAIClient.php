<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\OpenAI;

use OpenAI\Contracts\ClientContract;

/**
 * Bound as the OpenAI ClientContract when no OPENAI_API_KEY is configured:
 * resolving services never fails, using any OpenAI resource throws a clear error.
 *
 * Every resource method is declared with a `never` return type, which is a
 * valid override of any contract return type. That keeps this class
 * compatible with every supported openai-php/client release (^0.8 ... ^0.20),
 * including resource methods that only exist in newer releases.
 */
class MissingOpenAIClient implements ClientContract
{
    public function responses(): never
    {
        $this->throwMissingKey();
    }

    public function conversations(): never
    {
        $this->throwMissingKey();
    }

    public function completions(): never
    {
        $this->throwMissingKey();
    }

    public function chat(): never
    {
        $this->throwMissingKey();
    }

    public function containers(): never
    {
        $this->throwMissingKey();
    }

    public function embeddings(): never
    {
        $this->throwMissingKey();
    }

    public function audio(): never
    {
        $this->throwMissingKey();
    }

    public function edits(): never
    {
        $this->throwMissingKey();
    }

    public function files(): never
    {
        $this->throwMissingKey();
    }

    public function models(): never
    {
        $this->throwMissingKey();
    }

    public function fineTuning(): never
    {
        $this->throwMissingKey();
    }

    public function fineTunes(): never
    {
        $this->throwMissingKey();
    }

    public function moderations(): never
    {
        $this->throwMissingKey();
    }

    public function images(): never
    {
        $this->throwMissingKey();
    }

    public function assistants(): never
    {
        $this->throwMissingKey();
    }

    public function realtime(): never
    {
        $this->throwMissingKey();
    }

    public function threads(): never
    {
        $this->throwMissingKey();
    }

    public function batches(): never
    {
        $this->throwMissingKey();
    }

    public function vectorStores(): never
    {
        $this->throwMissingKey();
    }

    private function throwMissingKey(): never
    {
        throw new \RuntimeException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file before using OpenAI-backed features.');
    }
}
