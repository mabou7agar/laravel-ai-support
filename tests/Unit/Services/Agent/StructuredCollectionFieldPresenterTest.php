<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\StructuredCollectionDefinition;
use LaravelAIEngine\Services\Agent\StructuredCollectionFieldPresenter;
use LaravelAIEngine\Tests\UnitTestCase;

class StructuredCollectionFieldPresenterTest extends UnitTestCase
{
    public function test_it_presents_fields_with_required_state_and_localized_options(): void
    {
        $definition = StructuredCollectionDefinition::make('training_request')
            ->addText('name', required: true, description: 'Student name')
            ->addSelect('level', [
                ['value' => 'beginner', 'labels' => ['en' => 'Beginner', 'ar' => 'مبتدئ']],
                ['value' => 'advanced', 'labels' => ['en' => 'Advanced', 'ar' => 'متقدم']],
            ], required: true)
            ->addDate('start_date');

        $fields = (new StructuredCollectionFieldPresenter())->present($definition, 'ar');

        $this->assertSame('name', $fields[0]['name']);
        $this->assertSame('text', $fields[0]['ui']);
        $this->assertTrue($fields[0]['required']);
        $this->assertSame('Student name', $fields[0]['description']);
        $this->assertSame('level', $fields[1]['name']);
        $this->assertSame('select', $fields[1]['ui']);
        $this->assertSame([
            ['value' => 'beginner', 'label' => 'مبتدئ'],
            ['value' => 'advanced', 'label' => 'متقدم'],
        ], $fields[1]['options']);
        $this->assertSame('date', $fields[2]['format']);
        $this->assertSame('date', $fields[2]['ui']);
    }

    public function test_it_falls_back_to_enum_values_for_options(): void
    {
        $definition = StructuredCollectionDefinition::make('support_ticket')
            ->addField('priority', 'string', enum: ['low', 'high']);

        $fields = (new StructuredCollectionFieldPresenter())->present($definition, 'fr');

        $this->assertSame('select', $fields[0]['ui']);
        $this->assertSame([
            ['value' => 'low', 'label' => 'Low'],
            ['value' => 'high', 'label' => 'High'],
        ], $fields[0]['options']);
    }
}
