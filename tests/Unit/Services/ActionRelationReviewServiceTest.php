<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\Actions\ActionRelationReviewService;
use LaravelAIEngine\Tests\TestCase;

class ActionRelationReviewServiceTest extends TestCase
{
    public function test_review_exposes_pending_relation_create_and_next_option(): void
    {
        $service = new ActionRelationReviewService();

        $review = $service->review('create_sales_invoice', [
            'customer_name' => 'Smoke Customer',
            'create_missing_relations' => true,
        ], [
            ['field' => 'customer_id', 'label' => 'Smoke Customer', 'will_create' => true],
        ]);

        $this->assertTrue($review['requires_relation_approval']);
        $this->assertSame('customer', $review['pending_creates'][0]['relation_type']);
        $this->assertSame(['customer_email'], $review['pending_creates'][0]['required_fields']);
        $this->assertSame(['customer_name', 'customer_email'], $review['pending_creates'][0]['all_required_fields']);

        $options = $service->nextOptions($review);
        $this->assertSame('relation_create_confirmation', $options[0]['type']);
        $this->assertSame('customer_id', $options[0]['approval_key']);
    }

    public function test_create_candidates_detects_missing_customer_and_products(): void
    {
        $service = new ActionRelationReviewService();

        $candidates = $service->createCandidates('create_sales_invoice', [
            'create_missing_relations' => true,
            'customer_name' => 'Smoke Customer',
            'items' => [
                ['product_name' => 'Smoke Product'],
            ],
        ]);

        $this->assertSame(['customer_id', 'items.0.product_id'], collect($candidates)->pluck('field')->all());
    }
}
