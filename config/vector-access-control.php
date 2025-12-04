<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vector Search Access Control
    |--------------------------------------------------------------------------
    |
    | Configure multi-tenant access control for RAG/Vector searches
    |
    */

    // Allow anonymous (unauthenticated) users to search
    'allow_anonymous_search' => env('AI_ENGINE_ALLOW_ANONYMOUS_SEARCH', false),

    // Enable tenant-scoped access (users can see data within their tenant/organization)
    'enable_tenant_scope' => env('AI_ENGINE_ENABLE_TENANT_SCOPE', true),

    // Enable workspace-scoped access (users can see data within their current workspace)
    'enable_workspace_scope' => env('AI_ENGINE_ENABLE_WORKSPACE_SCOPE', true),

    // Admin roles that can access ALL data
    'admin_roles' => [
        'super-admin',
        'admin',
        'support',
        'moderator',
    ],

    // Tenant field names to check (in order of priority)
    'tenant_fields' => [
        'tenant_id',
        'organization_id',
        'company_id',
        'team_id',
    ],

    // Workspace field names to check (in order of priority)
    'workspace_fields' => [
        'workspace_id',
        'current_workspace_id',
    ],

    // User ownership field names to check
    'user_fields' => [
        'user_id',
        'owner_id',
        'created_by',
        'author_id',
    ],

    // Public data access
    'allow_public_data' => env('AI_ENGINE_ALLOW_PUBLIC_DATA', true),
    
    // Public data field names
    'public_fields' => [
        'is_public' => true,
        'visibility' => 'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Lookup Caching
    |--------------------------------------------------------------------------
    |
    | Cache user lookups to improve performance
    | Users are cached for 5 minutes by default
    |
    */
    'cache_user_lookups' => env('AI_ENGINE_CACHE_USER_LOOKUPS', true),

    /*
    |--------------------------------------------------------------------------
    | Access Level Logging
    |--------------------------------------------------------------------------
    |
    | Log access level for debugging and auditing
    |
    */
    'log_access_level' => env('AI_ENGINE_LOG_ACCESS_LEVEL', true),

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | In strict mode, users without proper authentication cannot access any data
    | In non-strict mode, fallback to basic user_id filtering
    |
    */
    'strict_mode' => env('AI_ENGINE_STRICT_MODE', true),
];
