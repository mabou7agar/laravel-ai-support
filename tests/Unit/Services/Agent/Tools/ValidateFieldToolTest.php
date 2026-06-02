<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool;
use LaravelAIEngine\Tests\TestCase;

class ValidateFieldToolTest extends TestCase
{
    private function tool(): ValidateFieldTool
    {
        return new ValidateFieldTool();
    }

    private function context(): UnifiedActionContext
    {
        return new UnifiedActionContext('valfield', 'u1');
    }

    public function test_get_name_returns_validate_field(): void
    {
        $this->assertSame('validate_field', $this->tool()->getName());
    }

    public function test_get_parameters_declares_required_inputs(): void
    {
        $params = $this->tool()->getParameters();

        $this->assertArrayHasKey('field_name', $params);
        $this->assertArrayHasKey('value', $params);
        $this->assertArrayHasKey('rules', $params);

        $this->assertTrue($params['field_name']['required']);
        $this->assertTrue($params['value']['required']);
        $this->assertTrue($params['rules']['required']);
    }

    public function test_execute_returns_success_for_valid_value(): void
    {
        $result = $this->tool()->execute([
            'field_name' => 'email',
            'value' => 'user@example.com',
            'rules' => 'required|email|max:255',
        ], $this->context());

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertSame("Field 'email' is valid", $result->message);
        $this->assertSame('email', $result->data['field']);
        $this->assertSame('user@example.com', $result->data['value']);
        $this->assertTrue($result->data['valid']);
    }

    public function test_execute_returns_failure_for_invalid_value(): void
    {
        $result = $this->tool()->execute([
            'field_name' => 'email',
            'value' => 'not-an-email',
            'rules' => 'required|email',
        ], $this->context());

        $this->assertFalse($result->success);
        $this->assertSame('Validation failed', $result->error);
        $this->assertSame('email', $result->data['field']);
        $this->assertSame('not-an-email', $result->data['value']);
        $this->assertIsArray($result->data['errors']);
        $this->assertNotEmpty($result->data['errors']);
    }

    public function test_execute_returns_failure_for_missing_required_value(): void
    {
        $result = $this->tool()->execute([
            'field_name' => 'name',
            'value' => '',
            'rules' => 'required',
        ], $this->context());

        $this->assertFalse($result->success);
        $this->assertSame('Validation failed', $result->error);
        $this->assertArrayHasKey('errors', $result->data);
        $this->assertNotEmpty($result->data['errors']);
    }
}
