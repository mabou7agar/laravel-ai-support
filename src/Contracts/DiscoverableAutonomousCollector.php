<?php

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;

/**
 * Interface for auto-discoverable autonomous collectors
 * 
 * Implement this interface in your collector config class to enable
 * automatic discovery. The system will scan configured directories
 * and register any classes implementing this interface.
 * 
 * Example:
 * ```php
 * class InvoiceAutonomousConfig implements DiscoverableAutonomousCollector
 * {
 *     public static function getName(): string
 *     {
 *         return 'invoice';
 *     }
 * 
 *     public static function getConfig(): AutonomousCollectorConfig
 *     {
 *         return new AutonomousCollectorConfig(
 *             goal: 'Create a sales invoice',
 *             tools: [...],
 *             outputSchema: [...],
 *             onComplete: fn($data) => Invoice::create($data),
 *         );
 *     }
 * 
 *     public static function getDescription(): string
 *     {
 *         return 'Create invoices with customer and products';
 *     }
 * }
 * ```
 */
interface DiscoverableAutonomousCollector
{
    /**
     * Get the unique name for this collector
     */
    public static function getName(): string;

    /**
     * Get the collector configuration
     */
    public static function getConfig(): AutonomousCollectorConfig;

    /**
     * Get a description for this collector (used in AI detection)
     */
    public static function getDescription(): string;

    /**
     * Get priority (higher = checked first)
     * Optional - default is 0
     */
    public static function getPriority(): int;

    /**
     * Get the model class this collector is for
     * Used for RAG queries to know which model to filter
     */
    public static function getModelClass(): ?string;

    /**
     * Get filter configuration for database queries
     * AI uses this to apply filters from user queries like "invoices at 26-01-2026"
     * 
     * @return array{
     *   user_field?: string,    // Field for user ownership
     *   date_field?: string,    // Primary date field
     *   status_field?: string,  // Status field
     *   amount_field?: string,  // Amount/total field
     * }
     */
    public static function getFilterConfig(): array;
}
