<?php

namespace LaravelAIEngine\DTOs;

use Illuminate\Http\UploadedFile;

class UploadFileDTO
{
    public function __construct(
        public readonly UploadedFile $file,
        public readonly string $sessionId,
        public readonly ?string $type = null,
        public readonly ?string $userId = null
    ) {}

    public function toArray(): array
    {
        return [
            'file_name' => $this->file->getClientOriginalName(),
            'file_size' => $this->file->getSize(),
            'mime_type' => $this->file->getMimeType(),
            'session_id' => $this->sessionId,
            'type' => $this->type,
            'user_id' => $this->userId,
        ];
    }
}
