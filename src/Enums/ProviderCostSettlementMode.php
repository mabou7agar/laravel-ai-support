<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

enum ProviderCostSettlementMode: string
{
    /** Preserve the historical behaviour: estimates remain the billing floor. */
    case EstimateFloor = 'estimate_floor';

    /** Use provider-reported USD as the source of truth when it is available. */
    case ProviderCost = 'provider_cost';
}
