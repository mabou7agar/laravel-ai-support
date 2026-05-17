<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

/**
 * Backward-compatible alias for transcript/session history operations.
 *
 * Long-term extracted memories live in the ConversationMemory services.
 */
class ConversationService extends ConversationTranscriptService
{
}
