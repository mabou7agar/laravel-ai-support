<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Knowledge;

use LaravelAIEngine\Contracts\KnowledgeAccessPolicy;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\Enums\KnowledgeScope;

final class DefaultKnowledgeAccessPolicy implements KnowledgeAccessPolicy
{
    public function canAccess(ScopedKnowledgeDocument $document, array $context): bool
    {
        return match ($document->scope) {
            KnowledgeScope::GlobalShared,
            KnowledgeScope::TenantPublic => true,
            KnowledgeScope::TenantPrivate => $this->same($document->tenantId, $context['tenant_id'] ?? null),
            KnowledgeScope::UserPrivate => $this->same($document->userId, $context['user_id'] ?? null),
            KnowledgeScope::SubscriptionLimited => (bool) ($context['subscription_active'] ?? false)
                && $this->same($document->tenantId, $context['tenant_id'] ?? null),
        };
    }

    private function same(?string $expected, mixed $actual): bool
    {
        return $expected !== null && $expected !== '' && $expected === trim((string) ($actual ?? ''));
    }
}
