<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Http\Requests;

use Illuminate\Support\Facades\Validator;
use LaravelAIEngine\Http\Requests\SendMessageRequest;
use LaravelAIEngine\Tests\UnitTestCase;

class SendMessageRequestRagTest extends UnitTestCase
{
    public function test_use_rag_is_a_validated_boolean_field(): void
    {
        $rules = (new SendMessageRequest())->rules();

        $this->assertArrayHasKey('use_rag', $rules);
        $this->assertSame('sometimes|nullable|boolean', $rules['use_rag']);
    }

    public function test_use_rag_rejects_non_boolean_values(): void
    {
        $validator = Validator::make([
            'message' => 'hello',
            'session_id' => 'session',
            'use_rag' => 'not-a-boolean',
        ], (new SendMessageRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('use_rag', $validator->errors()->messages());
    }

    public function test_rag_flag_defaults_to_true_when_absent(): void
    {
        $request = $this->buildRequest([
            'message' => 'hello',
            'session_id' => 'session',
        ]);

        $this->assertTrue($request->useRag());
        $this->assertTrue($request->toDTO()->intelligentRag);
    }

    public function test_use_rag_false_disables_rag(): void
    {
        $request = $this->buildRequest([
            'message' => 'hello',
            'session_id' => 'session',
            'use_rag' => false,
        ]);

        $this->assertFalse($request->useRag());
        $this->assertFalse($request->toDTO()->intelligentRag);
    }

    public function test_legacy_rag_alias_is_honored(): void
    {
        $request = $this->buildRequest([
            'message' => 'hello',
            'session_id' => 'session',
            'rag' => false,
        ]);

        $this->assertFalse($request->useRag());
        $this->assertFalse($request->toDTO()->intelligentRag);
    }

    public function test_use_rag_takes_precedence_over_legacy_alias(): void
    {
        $request = $this->buildRequest([
            'message' => 'hello',
            'session_id' => 'session',
            'use_rag' => true,
            'rag' => false,
        ]);

        $this->assertTrue($request->useRag());
        $this->assertTrue($request->toDTO()->intelligentRag);
    }

    private function buildRequest(array $data): SendMessageRequest
    {
        $request = SendMessageRequest::create('/api/chat', 'POST', $data);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }
}
