<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\StructuredCollectionDefinition;
use LaravelAIEngine\Tests\UnitTestCase;

class StructuredCollectionDefinitionTest extends UnitTestCase
{
    public function test_builder_creates_json_schema_and_callback_payload(): void
    {
        $definition = StructuredCollectionDefinition::make('lead_capture')
            ->description('Collect lead details from chat.')
            ->addField('name', 'string', required: true, description: 'Lead name')
            ->addField('email', 'string', required: true, format: 'email')
            ->addField('notes', 'string')
            ->confirmBeforeComplete()
            ->closeOnComplete()
            ->callbackUrl('https://callback.test/leads', headers: ['X-App' => 'demo']);

        $payload = $definition->toArray();

        $this->assertSame('lead_capture', $payload['name']);
        $this->assertSame(['name', 'email'], $payload['schema']['required']);
        $this->assertSame('email', $payload['schema']['properties']['email']['format']);
        $this->assertTrue($payload['confirm_before_complete']);
        $this->assertTrue($payload['close_on_complete']);
        $this->assertSame('url', $payload['callback']['type']);
        $this->assertSame('https://callback.test/leads', $payload['callback']['url']);
        $this->assertSame(['X-App' => 'demo'], $payload['callback']['headers']);
    }

    public function test_definition_can_be_restored_from_request_array(): void
    {
        $definition = StructuredCollectionDefinition::fromArray([
            'name' => 'support_ticket',
            'schema' => [
                'type' => 'object',
                'required' => ['subject'],
                'properties' => [
                    'subject' => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'high']],
                ],
            ],
        ]);

        $this->assertSame('support_ticket', $definition->name);
        $this->assertSame(['subject'], $definition->requiredFields());
        $this->assertSame(['subject', 'priority'], array_keys($definition->properties()));
    }

    public function test_convenience_field_builders_create_schema_and_ui_metadata(): void
    {
        $definition = StructuredCollectionDefinition::make('training_request')
            ->addText('name', required: true)
            ->addTextarea('notes')
            ->addEmail('email', required: true)
            ->addPhone('phone')
            ->addUrl('website')
            ->addNumber('budget')
            ->addInteger('seats')
            ->addBoolean('needs_certificate')
            ->addDate('start_date')
            ->addDateTime('starts_at')
            ->addTime('preferred_time')
            ->addSelect('level', [
                ['value' => 'beginner', 'labels' => ['en' => 'Beginner', 'ar' => 'مبتدئ']],
                ['value' => 'advanced', 'labels' => ['en' => 'Advanced', 'ar' => 'متقدم']],
            ], required: true)
            ->addMultiSelect('topics', ['php', 'laravel'])
            ->addRadio('delivery', ['online', 'onsite'])
            ->addCheckboxes('addons', ['recording', 'certificate'])
            ->addFile('attachment')
            ->addHidden('source')
            ->addJson('metadata')
            ->addArray('tags')
            ->addObject('billing');

        $schema = $definition->schema();

        $this->assertSame(['name', 'email', 'level'], $schema['required']);
        $this->assertSame('textarea', $schema['properties']['notes']['metadata']['ui']);
        $this->assertSame('email', $schema['properties']['email']['format']);
        $this->assertSame('phone', $schema['properties']['phone']['metadata']['ui']);
        $this->assertSame('uri', $schema['properties']['website']['format']);
        $this->assertSame('number', $schema['properties']['budget']['type']);
        $this->assertSame('integer', $schema['properties']['seats']['type']);
        $this->assertSame('boolean', $schema['properties']['needs_certificate']['type']);
        $this->assertSame('date', $schema['properties']['start_date']['format']);
        $this->assertSame('date-time', $schema['properties']['starts_at']['format']);
        $this->assertSame('time', $schema['properties']['preferred_time']['format']);
        $this->assertSame(['beginner', 'advanced'], $schema['properties']['level']['enum']);
        $this->assertSame('select', $schema['properties']['level']['metadata']['ui']);
        $this->assertSame('multiselect', $schema['properties']['topics']['metadata']['ui']);
        $this->assertSame('radio', $schema['properties']['delivery']['metadata']['ui']);
        $this->assertSame('checkboxes', $schema['properties']['addons']['metadata']['ui']);
        $this->assertSame('file', $schema['properties']['attachment']['metadata']['ui']);
        $this->assertSame('hidden', $schema['properties']['source']['metadata']['ui']);
        $this->assertSame('object', $schema['properties']['metadata']['type']);
        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertSame('object', $schema['properties']['billing']['type']);
    }

    public function test_preview_presentation_can_be_enabled_without_replacing_generic_field_api(): void
    {
        $definition = StructuredCollectionDefinition::make('training_request')
            ->addText('name', required: true)
            ->withPreview('html');

        $payload = $definition->toArray();

        $this->assertSame('html', $payload['presentation']['mode']);
        $this->assertTrue($payload['presentation']['preview']);
        $this->assertSame('html', StructuredCollectionDefinition::fromArray($payload)->presentation()['mode']);
    }
}
