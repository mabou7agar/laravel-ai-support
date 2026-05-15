<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\OpenAI;

use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\AssistantsContract;
use OpenAI\Contracts\Resources\AudioContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Contracts\Resources\CompletionsContract;
use OpenAI\Contracts\Resources\EditsContract;
use OpenAI\Contracts\Resources\EmbeddingsContract;
use OpenAI\Contracts\Resources\FilesContract;
use OpenAI\Contracts\Resources\FineTunesContract;
use OpenAI\Contracts\Resources\FineTuningContract;
use OpenAI\Contracts\Resources\ImagesContract;
use OpenAI\Contracts\Resources\ModelsContract;
use OpenAI\Contracts\Resources\ModerationsContract;
use OpenAI\Contracts\Resources\ThreadsContract;

class MissingOpenAIClient implements ClientContract
{
    public function completions(): CompletionsContract
    {
        $this->throwMissingKey();
    }

    public function chat(): ChatContract
    {
        $this->throwMissingKey();
    }

    public function embeddings(): EmbeddingsContract
    {
        $this->throwMissingKey();
    }

    public function audio(): AudioContract
    {
        $this->throwMissingKey();
    }

    public function edits(): EditsContract
    {
        $this->throwMissingKey();
    }

    public function files(): FilesContract
    {
        $this->throwMissingKey();
    }

    public function models(): ModelsContract
    {
        $this->throwMissingKey();
    }

    public function fineTuning(): FineTuningContract
    {
        $this->throwMissingKey();
    }

    public function fineTunes(): FineTunesContract
    {
        $this->throwMissingKey();
    }

    public function moderations(): ModerationsContract
    {
        $this->throwMissingKey();
    }

    public function images(): ImagesContract
    {
        $this->throwMissingKey();
    }

    public function assistants(): AssistantsContract
    {
        $this->throwMissingKey();
    }

    public function threads(): ThreadsContract
    {
        $this->throwMissingKey();
    }

    private function throwMissingKey(): never
    {
        throw new \RuntimeException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file before using OpenAI-backed features.');
    }
}
