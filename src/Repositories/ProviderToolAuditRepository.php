<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use LaravelAIEngine\Models\AIProviderToolAuditEvent;

class ProviderToolAuditRepository
{
    public function create(array $attributes): AIProviderToolAuditEvent
    {
        return AIProviderToolAuditEvent::create($attributes);
    }
}
