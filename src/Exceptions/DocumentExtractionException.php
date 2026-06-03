<?php

declare(strict_types=1);

namespace LaravelAIEngine\Exceptions;

/**
 * Thrown when document text extraction cannot be performed because neither a
 * suitable PHP library nor the required CLI tool is available, or the
 * underlying extractor failed. This makes extraction failures observable
 * instead of silently returning an empty string.
 */
class DocumentExtractionException extends \RuntimeException
{
    public function __construct(
        string $message,
        protected ?string $extension = null,
        protected ?string $filePath = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function extension(): ?string
    {
        return $this->extension;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }
}
