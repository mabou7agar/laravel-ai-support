<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\GenericModuleActionDTO;
use LaravelAIEngine\Services\Actions\GenericModuleActionRepository;
use LaravelAIEngine\Services\Actions\GenericModuleActionService;
use LaravelAIEngine\Tests\TestCase;

class GenericModuleActionServiceTest extends TestCase
{
    public function test_line_item_foreign_key_defaults_to_resource_key_instead_of_invoice(): void
    {
        $service = new GenericModuleActionService(new GenericModuleActionRepository());
        $method = new \ReflectionMethod($service, 'lineItemForeignKey');
        $method->setAccessible(true);

        $dto = new GenericModuleActionDTO(
            actionId: 'create_purchase_order',
            operation: 'create',
            resourceKey: 'purchase_orders',
            resource: [],
            definition: [],
            payload: []
        );

        $this->assertSame('purchase_order_id', $method->invoke($service, $dto, []));
        $this->assertSame('parent_id', $method->invoke($service, $dto, ['foreign_key' => 'parent_id']));
    }
}
