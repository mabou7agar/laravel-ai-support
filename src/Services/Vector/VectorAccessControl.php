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
     * Get workspace ID for user (if workspace-based system)
     * 
     * Checks for:
     * 1. Direct workspace_id on user
     * 2. Current workspace from session/context
     * 3. Workspace relationship on user
     */
    public function getUserWorkspaceId($user): mixed
    {
        if (!$user) {
            return null;
        }

        // Check direct workspace_id field
        if (isset($user->workspace_id) && $user->workspace_id) {
            return $user->workspace_id;
        }

        // Check for current_workspace_id (common pattern for multi-workspace users)
        if (isset($user->current_workspace_id) && $user->current_workspace_id) {
            return $user->current_workspace_id;
        }

        // Check for workspace relationship
        if (method_exists($user, 'workspace') && $user->workspace) {
            return $user->workspace->id ?? null;
        }

        // Check for currentWorkspace relationship
        if (method_exists($user, 'currentWorkspace') && $user->currentWorkspace) {
            return $user->currentWorkspace->id ?? null;
        }

        // Check session for current workspace (if available)
        if (function_exists('session') && session()->has('current_workspace_id')) {
            return session('current_workspace_id');
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
        // Check if model has custom filter logic
        if (isset($baseFilters['model_class'])) {
            $modelClass = $baseFilters['model_class'];
            unset($baseFilters['model_class']); // Remove from filters
            
            // Check for public property first (simplest approach)
            $skipUserFilter = false;
            if (property_exists($modelClass, 'skipUserFilter')) {
                $reflection = new \ReflectionClass($modelClass);
                $property = $reflection->getProperty('skipUserFilter');
                if ($property->isPublic() && $property->isStatic()) {
                    $skipUserFilter = $modelClass::$skipUserFilter;
                    
                    Log::debug('Using model public property for skip_user_filter', [
                        'model' => $modelClass,
                        'skip_user_filter' => $skipUserFilter,
                    ]);
                    
                    if ($skipUserFilter) {
                        return $baseFilters;
                    }
                }
            }
            
            // Fallback to method-based approach
            if (method_exists($modelClass, 'getVectorSearchFilters')) {
                Log::debug('Using model-specific search filters', [
                    'model' => $modelClass,
                    'user_id' => $userId,
                ]);
                
                // Let model define its own filters
                $customFilters = $modelClass::getVectorSearchFilters($userId, $baseFilters);
                
                // Check if model wants to skip user filtering (from filters or config)
                $skipUserFilter = $customFilters['skip_user_filter'] ?? false;
                if (method_exists($modelClass, 'getVectorSearchConfig')) {
                    $config = $modelClass::getVectorSearchConfig();
                    $skipUserFilter = $skipUserFilter || ($config['skip_user_filter'] ?? false);
                }
                
                // Remove the meta-flag before returning
                unset($customFilters['skip_user_filter']);
                
                if ($skipUserFilter) {
                    Log::debug('Model requested skip_user_filter', [
                        'model' => $modelClass,
                        'returning_filters' => $customFilters,
                    ]);
                }
                
                return $customFilters;
            }
        }
        
        // Check if user filtering should be skipped (for workspace/org-level searches)
        if (isset($baseFilters['skip_user_filter']) && $baseFilters['skip_user_filter']) {
            unset($baseFilters['skip_user_filter']);
            Log::debug('Skipping user_id filter as requested', [
                'custom_filters' => $baseFilters,
            ]);
            return $baseFilters;
        }
        
        // If no userId provided, try to use demo user as fallback
        if (!$userId) {
            $demoUserId = config('ai-engine.demo_user_id', '1');
            Log::debug('No userId provided, using demo user', ['demo_user_id' => $demoUserId]);
            $userId = $demoUserId;
        }
        
        // Fetch user by ID
        $user = $this->getUserById($userId);
        
        // No user = no access (unless explicitly allowed by config)
        if (!$user) {
            if (config('ai-engine.vector.allow_anonymous_search', false)) {
                Log::debug('Anonymous vector search allowed by config');
                return $baseFilters;
            }
            
            // Last resort: try demo user ID directly in filter
            $demoUserId = config('ai-engine.demo_user_id', '1');
            Log::warning('User not found, using demo user ID in filter', [
                'requested_user_id' => $userId,
                'demo_user_id' => $demoUserId,
            ]);
            // Cast to int if numeric to match Qdrant integer index type
            $filterUserId = is_numeric($demoUserId) ? (int) $demoUserId : $demoUserId;
            return array_merge($baseFilters, ['user_id' => $filterUserId]);
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
        if ($tenantId !== null && config('vector-access-control.enable_tenant_scope', true)) {
            Log::debug('Tenant-scoped search', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ]);
            
            $filterTenantId = is_numeric($tenantId) ? (int) $tenantId : (string) $tenantId;
            
            return array_merge($baseFilters, [
                'tenant_id' => $filterTenantId,
            ]);
        }

        // LEVEL 2.5: Workspace-scoped - Access all data within workspace
        $workspaceId = $this->getUserWorkspaceId($user);
        if ($workspaceId !== null && config('vector-access-control.enable_workspace_scope', true)) {
            Log::debug('Workspace-scoped search', [
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
            ]);
            
            $filterWorkspaceId = is_numeric($workspaceId) ? (int) $workspaceId : (string) $workspaceId;
            
            return array_merge($baseFilters, [
                'workspace_id' => $filterWorkspaceId,
            ]);
        }

        // LEVEL 3: User-scoped - Access only own data
        Log::debug('User-scoped search', [
            'user_id' => $userId,
        ]);
        
        // Smart type casting: if userId is numeric, cast to int; otherwise keep as string (for UUIDs)
        $filterUserId = is_numeric($userId) ? (int) $userId : (string) $userId;
        
        return array_merge($baseFilters, [
            'user_id' => $filterUserId,
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

        if ($this->getUserWorkspaceId($user) !== null) {
            return 'workspace';
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

        // Check workspace access
        $workspaceId = $this->getUserWorkspaceId($user);
        if ($workspaceId !== null) {
            $modelWorkspaceId = $model->workspace_id ?? null;
            if ($modelWorkspaceId && $modelWorkspaceId == $workspaceId) {
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
