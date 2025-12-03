<?php

namespace LaravelAIEngine\Services\Vector;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Vector Access Control Service
 * 
 * Handles multi-tenant access control for RAG/Vector searches
 * Supports different access levels: admin, tenant, user
 */
class VectorAccessControl
{
    /**
     * User model class to use for fetching users
     */
    protected string $userModel;

    /**
     * Cache TTL for user lookups (in seconds)
     */
    protected int $cacheTtl = 300; // 5 minutes

    public function __construct()
    {
        $this->userModel = config('auth.providers.users.model', 'App\\Models\\User');
    }

    /**
     * Get user by ID with caching
     * 
     * @param string|int|null $userId
     * @return mixed User object or null
     */
    public function getUserById($userId)
    {
        if (!$userId) {
            return null;
        }

        // Check if caching is enabled
        if (config('ai-engine.vector.cache_user_lookups', true)) {
            return Cache::remember(
                "ai_engine_user_{$userId}",
                $this->cacheTtl,
                fn() => $this->fetchUser($userId)
            );
        }

        return $this->fetchUser($userId);
    }

    /**
     * Fetch user from database
     */
    protected function fetchUser($userId)
    {
        try {
            $userClass = $this->userModel;
            return $userClass::find($userId);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch user for access control', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Determine if user can access all data (admin/super user)
     */
    public function canAccessAllData($user): bool
    {
        if (!$user) {
            return false;
        }

        // Check if user has admin role
        if (method_exists($user, 'hasRole')) {
            if ($user->hasRole(['super-admin', 'admin', 'support'])) {
                Log::debug('User has admin access to all vector data', [
                    'user_id' => $user->id,
                    'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : 'unknown'
                ]);
                return true;
            }
        }

        // Check for admin flag
        if (isset($user->is_admin) && $user->is_admin) {
            return true;
        }

        // Check for super_admin flag
        if (isset($user->is_super_admin) && $user->is_super_admin) {
            return true;
        }

        return false;
    }

    /**
     * Get tenant ID for user (if multi-tenant system)
     */
    public function getUserTenantId($user): ?string
    {
        if (!$user) {
            return null;
        }

        // Check common tenant field names
        $tenantFields = ['tenant_id', 'organization_id', 'company_id', 'team_id'];
        
        foreach ($tenantFields as $field) {
            if (isset($user->$field)) {
                return (string) $user->$field;
            }
        }

        return null;
    }

    /**
     * Build vector search filters based on user access level
     * 
     * @param string|int|null $userId User ID (will fetch user internally)
     * @param array $baseFilters Additional filters to merge
     * @return array Filters for vector search
     */
    public function buildSearchFilters($userId, array $baseFilters = []): array
    {
        // Fetch user by ID
        $user = $this->getUserById($userId);
        
        // No user = no access (unless explicitly allowed by config)
        if (!$user) {
            if (config('ai-engine.vector.allow_anonymous_search', false)) {
                Log::debug('Anonymous vector search allowed by config');
                return $baseFilters;
            }
            
            // Return impossible filter to ensure no results
            return array_merge($baseFilters, ['user_id' => '__anonymous_no_access__']);
        }

        $userId = method_exists($user, 'getAuthIdentifier') 
            ? $user->getAuthIdentifier() 
            : $user->id;

        // LEVEL 1: Admin/Super User - Access ALL data
        if ($this->canAccessAllData($user)) {
            Log::debug('Admin user - no data filtering applied', [
                'user_id' => $userId,
            ]);
            return $baseFilters; // No user_id filter
        }

        // LEVEL 2: Tenant-scoped - Access all data within tenant
        $tenantId = $this->getUserTenantId($user);
        if ($tenantId !== null && config('ai-engine.vector.enable_tenant_scope', false)) {
            Log::debug('Tenant-scoped search', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ]);
            
            return array_merge($baseFilters, [
                'tenant_id' => $tenantId,
            ]);
        }

        // LEVEL 3: User-scoped - Access only own data
        Log::debug('User-scoped search', [
            'user_id' => $userId,
        ]);
        
        return array_merge($baseFilters, [
            'user_id' => (string) $userId,
        ]);
    }

    /**
     * Get access level description for logging
     */
    public function getAccessLevel($user): string
    {
        if (!$user) {
            return 'anonymous';
        }

        if ($this->canAccessAllData($user)) {
            return 'admin';
        }

        if ($this->getUserTenantId($user) !== null) {
            return 'tenant';
        }

        return 'user';
    }

    /**
     * Check if model should be accessible by user
     * 
     * @param object $model The model to check
     * @param mixed $user The user requesting access
     * @return bool
     */
    public function canAccessModel(object $model, $user): bool
    {
        if (!$user) {
            return config('ai-engine.vector.allow_anonymous_search', false);
        }

        // Admin can access everything
        if ($this->canAccessAllData($user)) {
            return true;
        }

        $userId = method_exists($user, 'getAuthIdentifier') 
            ? $user->getAuthIdentifier() 
            : $user->id;

        // Check tenant access
        $tenantId = $this->getUserTenantId($user);
        if ($tenantId !== null) {
            $modelTenantId = $model->tenant_id ?? $model->organization_id ?? $model->company_id ?? null;
            if ($modelTenantId && $modelTenantId == $tenantId) {
                return true;
            }
        }

        // Check user ownership
        $modelUserId = $model->user_id ?? $model->owner_id ?? $model->created_by ?? null;
        if ($modelUserId && $modelUserId == $userId) {
            return true;
        }

        return false;
    }
}
