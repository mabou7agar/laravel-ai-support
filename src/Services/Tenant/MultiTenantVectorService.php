<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Tenant;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaravelAIEngine\Contracts\TenantResolverInterface;

/**
 * Service for handling multi-database tenant vector operations
 * 
 * In multi-database tenancy, each tenant has their own database.
 * This service ensures vectors are stored in tenant-specific collections
 * for complete data isolation at the vector database level.
 */
class MultiTenantVectorService
{
    protected ?TenantResolverInterface $resolver = null;

    public function __construct()
    {
        $this->initializeResolver();
    }

    /**
     * Initialize the tenant resolver based on config
     */
    protected function initializeResolver(): void
    {
        $resolverType = config('vector-access-control.tenant_resolver', 'session');

        if ($resolverType === 'custom') {
            $customClass = config('vector-access-control.custom_tenant_resolver');
            if ($customClass && class_exists($customClass)) {
                $this->resolver = app($customClass);
            }
        }
    }

    /**
     * Check if multi-database tenancy is enabled
     */
    public function isMultiDbTenancyEnabled(): bool
    {
        return config('vector-access-control.multi_db_tenancy', false);
    }

    /**
     * Get the current tenant identifier
     */
    public function getCurrentTenantId(): ?string
    {
        // Use custom resolver if available
        if ($this->resolver) {
            return $this->resolver->getCurrentTenantId();
        }

        $resolverType = config('vector-access-control.tenant_resolver', 'session');

        return match ($resolverType) {
            'config' => $this->resolveFromConfig(),
            'session' => $this->resolveFromSession(),
            'database' => $this->resolveFromDatabase(),
            default => null,
        };
    }

    /**
     * Get tenant slug for collection naming
     */
    public function getCurrentTenantSlug(): ?string
    {
        if ($this->resolver) {
            return $this->resolver->getCurrentTenantSlug();
        }

        $tenantId = $this->getCurrentTenantId();
        if (!$tenantId) {
            return null;
        }

        // Convert to URL-safe slug
        return Str::slug($tenantId);
    }

    /**
     * Get tenant-specific collection name
     * 
     * @param string $baseCollection The base collection name (e.g., 'vec_emails')
     * @return string Tenant-specific collection name
     */
    public function getTenantCollectionName(string $baseCollection): string
    {
        if (!$this->isMultiDbTenancyEnabled()) {
            return $baseCollection;
        }

        $tenantSlug = $this->getCurrentTenantSlug();
        if (!$tenantSlug) {
            Log::warning('Multi-DB tenancy enabled but no tenant context found', [
                'collection' => $baseCollection,
            ]);
            return $baseCollection;
        }

        $strategy = config('vector-access-control.multi_db_collection_strategy', 'prefix');

        return match ($strategy) {
            'prefix' => "{$tenantSlug}_{$baseCollection}",
            'suffix' => "{$baseCollection}_{$tenantSlug}",
            'separate' => "{$tenantSlug}/{$baseCollection}",
            default => "{$tenantSlug}_{$baseCollection}",
        };
    }

    /**
     * Get all collection names for a tenant
     * 
     * @param array $baseCollections Base collection names
     * @return array Tenant-specific collection names
     */
    public function getTenantCollections(array $baseCollections): array
    {
        return array_map(
            fn($collection) => $this->getTenantCollectionName($collection),
            $baseCollections
        );
    }

    /**
     * Check if we're in a tenant context
     */
    public function hasTenant(): bool
    {
        if ($this->resolver) {
            return $this->resolver->hasTenant();
        }

        return $this->getCurrentTenantId() !== null;
    }

    /**
     * Get tenant's database connection
     */
    public function getTenantConnection(): ?string
    {
        if ($this->resolver) {
            return $this->resolver->getTenantConnection();
        }

        // Try common multi-tenancy package patterns
        
        // Spatie Laravel Multitenancy
        if (class_exists('\Spatie\Multitenancy\Models\Tenant')) {
            $tenant = \Spatie\Multitenancy\Models\Tenant::current();
            if ($tenant && isset($tenant->database)) {
                return $tenant->database;
            }
        }

        // Stancl Tenancy
        if (function_exists('tenant') && tenant()) {
            return tenant()->database ?? null;
        }

        // Tenancy for Laravel
        if (class_exists('\Hyn\Tenancy\Environment')) {
            $env = app(\Hyn\Tenancy\Environment::class);
            if ($env->tenant()) {
                return 'tenant';
            }
        }

        return null;
    }

    /**
     * Resolve tenant from config (e.g., database connection name)
     */
    protected function resolveFromConfig(): ?string
    {
        $configKey = config('vector-access-control.tenant_config_key', 'database.default');
        $value = config($configKey);

        // If it's a database connection, extract tenant identifier
        if ($configKey === 'database.default' && $value !== 'mysql' && $value !== 'sqlite') {
            return $value;
        }

        return $value;
    }

    /**
     * Resolve tenant from session
     */
    protected function resolveFromSession(): ?string
    {
        $sessionKey = config('vector-access-control.tenant_session_key', 'tenant_id');

        if (function_exists('session') && session()->has($sessionKey)) {
            return (string) session($sessionKey);
        }

        return null;
    }

    /**
     * Resolve tenant from database connection
     */
    protected function resolveFromDatabase(): ?string
    {
        // Get current database connection name
        $connection = config('database.default');

        // Skip default connections
        if (in_array($connection, ['mysql', 'sqlite', 'pgsql', 'sqlsrv'])) {
            return null;
        }

        return $connection;
    }

    /**
     * Build metadata with tenant information for indexing
     */
    public function buildTenantMetadata(): array
    {
        $metadata = [];

        if ($this->isMultiDbTenancyEnabled()) {
            $tenantId = $this->getCurrentTenantId();
            $tenantSlug = $this->getCurrentTenantSlug();

            if ($tenantId) {
                $metadata['tenant_id'] = $tenantId;
            }
            if ($tenantSlug) {
                $metadata['tenant_slug'] = $tenantSlug;
            }

            $connection = $this->getTenantConnection();
            if ($connection) {
                $metadata['tenant_connection'] = $connection;
            }
        }

        return $metadata;
    }

    public function currentScope(array $metadata = []): array
    {
        $tenantId = $this->nullableString($metadata['tenant_id'] ?? $metadata['tenant'] ?? null)
            ?? $this->getCurrentTenantId();
        $workspaceId = $this->nullableString($metadata['workspace_id'] ?? $metadata['workspace'] ?? null)
            ?? $this->resolveCurrentWorkspaceId();

        return [
            'tenant_id' => $this->nullableString($tenantId),
            'workspace_id' => $this->nullableString($workspaceId),
        ];
    }

    public function scopeKey(array|string|null $scopeOrTenant = [], ?string $workspaceId = null): string
    {
        $scope = is_array($scopeOrTenant)
            ? $this->currentScope($scopeOrTenant)
            : [
                'tenant_id' => $this->nullableString($scopeOrTenant),
                'workspace_id' => $this->nullableString($workspaceId),
            ];

        return sha1(json_encode([
            'tenant_id' => $scope['tenant_id'] ?? null,
            'workspace_id' => $scope['workspace_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    public function applyScopeToMetadata(array $metadata): array
    {
        $scope = $this->currentScope($metadata);

        foreach ($scope as $key => $value) {
            if ($value !== null && !array_key_exists($key, $metadata)) {
                $metadata[$key] = $value;
            }
        }

        $metadata['scope_key'] ??= $this->scopeKey($scope);

        return $metadata;
    }

    /**
     * Get search filters for multi-db tenancy
     * 
     * In multi-db mode, we use separate collections so no tenant filter needed.
     * This returns empty array as isolation is at collection level.
     */
    public function getSearchFilters(): array
    {
        // In multi-db mode, isolation is at collection level
        // No additional filters needed
        return [];
    }

    protected function resolveCurrentWorkspaceId(): ?string
    {
        if (function_exists('session')) {
            $workspaceId = session('workspace_id') ?? session('current_workspace_id');
            if ($workspaceId !== null && $workspaceId !== '') {
                return (string) $workspaceId;
            }
        }

        $configKey = config('vector-access-control.workspace_config_key');
        if (is_string($configKey) && $configKey !== '') {
            return $this->nullableString(config($configKey));
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
