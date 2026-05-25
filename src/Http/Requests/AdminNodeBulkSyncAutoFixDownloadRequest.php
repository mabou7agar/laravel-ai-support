<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

class AdminNodeBulkSyncAutoFixDownloadRequest extends AdminNodeBulkSyncPayloadRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'autofix_strict' => ['nullable', 'boolean'],
        ]);
    }
}
