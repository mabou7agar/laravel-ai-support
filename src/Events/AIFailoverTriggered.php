<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Failover Triggered Event - Fired when failover to backup engine occurs
 */
class AIFailoverTriggered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public string $primaryEngine,
        public string $fallbackEngine,
        public string $reason,
        public array $metadata = []
    ) {}
}
