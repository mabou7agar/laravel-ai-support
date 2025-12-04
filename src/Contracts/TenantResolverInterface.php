<?php

namespace LaravelAIEngine\Contracts;

/**
 * Interface for resolving current tenant in multi-database tenancy setups
 */
interface TenantResolverInterface
{
    /**
     * Get the current tenant identifier
     * 
     * @return string|null Tenant slug/id or null if no tenant
     */
    public function getCurrentTenantId(): ?string;

    /**
     * Get the current tenant slug (for collection naming)
     * 
     * @return string|null URL-safe tenant slug
     */
    public function getCurrentTenantSlug(): ?string;

    /**
     * Get the current tenant's database connection name
     * 
     * @return string|null Database connection name
     */
    public function getTenantConnection(): ?string;

    /**
     * Check if we're currently in a tenant context
     * 
     * @return bool
     */
    public function hasTenant(): bool;
}
