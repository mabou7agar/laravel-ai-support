<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

class AdminNodeBulkSyncApplyRequest extends AdminNodeBulkSyncPayloadRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'prune' => ['nullable', 'boolean'],
            'ping' => ['nullable', 'boolean'],
        ]);
    }
}
