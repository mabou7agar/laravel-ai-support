<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Models\AICreditReservation;

class CreditReservationRepository
{
    public function findByUuid(string $uuid): ?AICreditReservation
    {
        return AICreditReservation::query()->where('uuid', $uuid)->first();
    }

    public function findByIdempotencyKey(?string $key): ?AICreditReservation
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return AICreditReservation::query()->where('idempotency_key', $key)->first();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createReserved(
        string $ownerId,
        AIRequest $request,
        float $amount,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): AICreditReservation {
        return AICreditReservation::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $ownerId,
            'engine' => $request->getEngine()->value,
            'ai_model' => $request->getModel()->value,
            'amount' => $amount,
            'status' => AICreditReservation::STATUS_RESERVED,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => [
                'prompt' => $request->getPrompt(),
                'engine' => $request->getEngine()->value,
                'model' => $request->getModel()->value,
                'parameters' => $request->getParameters(),
            ],
            'metadata' => $metadata,
            'reserved_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markFinalized(AICreditReservation $reservation, array $metadata = []): bool
    {
        return $reservation->update([
            'status' => AICreditReservation::STATUS_FINALIZED,
            'metadata' => array_merge($reservation->metadata ?? [], $metadata),
            'finalized_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRefunded(AICreditReservation $reservation, array $metadata = []): bool
    {
        return $reservation->update([
            'status' => AICreditReservation::STATUS_REFUNDED,
            'metadata' => array_merge($reservation->metadata ?? [], $metadata),
            'refunded_at' => now(),
        ]);
    }
}
