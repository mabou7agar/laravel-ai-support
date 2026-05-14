<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;

class AICreditReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_REFUNDED = 'refunded';

    protected $table = 'ai_credit_reservations';

    protected $fillable = [
        'uuid',
        'owner_id',
        'engine',
        'ai_model',
        'amount',
        'status',
        'idempotency_key',
        'request_payload',
        'metadata',
        'reserved_at',
        'finalized_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'request_payload' => 'array',
        'metadata' => 'array',
        'reserved_at' => 'datetime',
        'finalized_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];
}
