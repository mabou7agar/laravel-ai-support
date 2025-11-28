<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * AI Model Registry
 *
 * Dynamic model management for future-proof AI integration.
 * Automatically supports new models like GPT-5, GPT-5.1, etc.
 */
class AIModel extends Model
{
    use SoftDeletes;
    protected $table = 'ai_models';

    protected $fillable = [
        'provider',
        'model_id',
        'name',
        'version',
        'description',
        'capabilities',
        'context_window',
        'pricing',
        'max_tokens',
        'supports_streaming',
        'supports_vision',
        'supports_function_calling',
        'supports_json_mode',
        'is_active',
        'is_deprecated',
        'released_at',
        'deprecated_at',
        'metadata',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'context_window' => 'array',
        'pricing' => 'array',
        'metadata' => 'array',
        'supports_streaming' => 'boolean',
        'supports_vision' => 'boolean',
        'supports_function_calling' => 'boolean',
        'supports_json_mode' => 'boolean',
        'is_active' => 'boolean',
        'is_deprecated' => 'boolean',
        'released_at' => 'datetime',
        'deprecated_at' => 'datetime',
    ];

    /**
     * Get all active models
     */
    public static function active()
    {
        return static::where('is_active', true)
            ->where('is_deprecated', false)
            ->orderBy('provider')
            ->orderBy('name');
    }

    /**
     * Get models by provider
     */
    public static function byProvider(string $provider)
    {
        return static::active()
            ->where('provider', $provider);
    }

    /**
     * Get model by ID with caching
     */
    public static function findByModelId(string $modelId): ?self
    {
        return Cache::remember(
            "ai_model:{$modelId}",
            now()->addHours(24),
            fn() => static::where('model_id', $modelId)->first()
        );
    }

    /**
     * Get all active models with caching
     */
    public static function getAllActive(): array
    {
        return Cache::remember(
            'ai_models:active',
            now()->addHours(24),
            fn() => static::active()->get()->toArray()
        );
    }

    /**
     * Get models grouped by provider
     */
    public static function getGroupedByProvider(): array
    {
        return Cache::remember(
            'ai_models:grouped',
            now()->addHours(24),
            function () {
                return static::active()
                    ->get()
                    ->groupBy('provider')
                    ->map(fn($models) => $models->toArray())
                    ->toArray();
            }
        );
    }

    /**
     * Check if model supports a capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }

    /**
     * Get input token price
     */
    public function getInputPrice(): ?float
    {
        return $this->pricing['input'] ?? null;
    }

    /**
     * Get output token price
     */
    public function getOutputPrice(): ?float
    {
        return $this->pricing['output'] ?? null;
    }

    /**
     * Get context window size
     */
    public function getContextWindowSize(): ?int
    {
        return $this->context_window['input'] ?? null;
    }

    /**
     * Get max output tokens
     */
    public function getMaxOutputTokens(): ?int
    {
        return $this->context_window['output'] ?? $this->max_tokens;
    }

    /**
     * Check if model is vision-capable
     */
    public function isVisionModel(): bool
    {
        return $this->supports_vision || $this->supports('vision');
    }

    /**
     * Check if model supports function calling
     */
    public function supportsFunctionCalling(): bool
    {
        return $this->supports_function_calling || $this->supports('function_calling');
    }

    /**
     * Mark model as deprecated
     */
    public function deprecate(): void
    {
        $this->update([
            'is_deprecated' => true,
            'deprecated_at' => now(),
        ]);

        $this->clearCache();
    }

    /**
     * Clear model cache
     */
    public function clearCache(): void
    {
        Cache::forget("ai_model:{$this->model_id}");
        Cache::forget('ai_models:active');
        Cache::forget('ai_models:grouped');
    }

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        // Clear cache on model changes
        static::saved(function ($model) {
            $model->clearCache();
        });

        static::deleted(function ($model) {
            $model->clearCache();
        });
    }

    /**
     * Scope for chat models
     */
    public function scopeChat($query)
    {
        return $query->where(function ($q) {
            $q->whereJsonContains('capabilities', 'chat')
              ->orWhere('supports_streaming', true);
        });
    }

    /**
     * Scope for vision models
     */
    public function scopeVision($query)
    {
        return $query->where('supports_vision', true);
    }

    /**
     * Scope for function calling models
     */
    public function scopeFunctionCalling($query)
    {
        return $query->where('supports_function_calling', true);
    }

    /**
     * Get display name with version
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->version
            ? "{$this->name} ({$this->version})"
            : $this->name;
    }

    /**
     * Get cost estimate for tokens
     */
    public function estimateCost(int $inputTokens, int $outputTokens): ?float
    {
        if (!$this->pricing) {
            return null;
        }

        $inputCost = ($inputTokens / 1000) * ($this->pricing['input'] ?? 0);
        $outputCost = ($outputTokens / 1000) * ($this->pricing['output'] ?? 0);

        return $inputCost + $outputCost;
    }
}
