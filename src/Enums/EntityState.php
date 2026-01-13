<?php

namespace LaravelAIEngine\Enums;

enum EntityState: string
{
    case RESOLVED = 'resolved';
    case MISSING = 'missing';
    case PENDING = 'pending';
    case CREATING = 'creating';
    case FAILED = 'failed';
    case PARTIAL = 'partial';
    
    /**
     * Get the context key for this state
     */
    public function getKey(string $entity): string
    {
        return match($this) {
            self::RESOLVED => "{$entity}_id",
            self::MISSING => "missing_{$entity}",
            self::PENDING => "pending_{$entity}",
            self::CREATING => "creating_{$entity}",
            self::FAILED => "failed_{$entity}",
            self::PARTIAL => "partial_{$entity}",
        };
    }
    
    /**
     * Get description for this state
     */
    public function description(): string
    {
        return match($this) {
            self::RESOLVED => 'Entity successfully resolved',
            self::MISSING => 'Entity not found, needs creation',
            self::PENDING => 'Entity resolution pending user input',
            self::CREATING => 'Entity currently being created',
            self::FAILED => 'Entity resolution failed',
            self::PARTIAL => 'Some entities resolved, some missing',
        };
    }
}
