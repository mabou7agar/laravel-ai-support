<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

enum KnowledgeScope: string
{
    case GlobalShared = 'global_shared';
    case TenantPublic = 'tenant_public';
    case TenantPrivate = 'tenant_private';
    case WorkspacePrivate = 'workspace_private';
    case UserPrivate = 'user_private';
    case SubscriptionLimited = 'subscription_limited';
}
