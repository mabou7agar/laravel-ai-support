<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Concerns;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\RelationResolver;

/**
 * Lets a final/write tool declare related records to find-or-create deterministically
 * before it persists, instead of hand-coding the resolution. Declare {@see relations()}
 * and call {@see resolveRelations()} at the top of execute().
 *
 *   protected function relations(): array
 *   {
 *       return [[
 *           'field'    => 'customer_id',
 *           'model'    => \App\Models\Customer::class,
 *           'identity' => ['email'],
 *           'map'      => ['email' => 'customer_email', 'name' => 'customer_name'],
 *           'create'   => ['name', 'email'],
 *       ]];
 *   }
 */
trait ResolvesAgentRelations
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function relations(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function resolveRelations(array $payload, UnifiedActionContext $context): array
    {
        $relations = $this->relations();
        if ($relations === []) {
            return $payload;
        }

        $resolver = app(RelationResolver::class);
        foreach ($relations as $relation) {
            $payload = $resolver->resolve($payload, $relation, $this->relationScope($context, $relation));
        }

        return $payload;
    }

    /**
     * Override to constrain a relation's lookup/create to a tenant/owner.
     *
     * @param array<string, mixed> $relation
     * @return array<string, mixed>
     */
    protected function relationScope(UnifiedActionContext $context, array $relation): array
    {
        return [];
    }
}
