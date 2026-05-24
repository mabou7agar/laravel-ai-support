<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

class RealtimeSdpExchangeRequest extends CreateRealtimeSessionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sdp' => ['required', 'string'],
        ]);
    }
}
