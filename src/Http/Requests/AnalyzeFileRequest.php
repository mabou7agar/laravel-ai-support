<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:10240|mimes:pdf,txt,doc,docx,png,jpg,jpeg,gif,webp,md,markdown,csv,xls,xlsx,ppt,pptx,rtf,odt,json,xml,html,htm',
            'message' => 'nullable|string|max:2000',
            'session_id' => 'required|string|max:255',
            'engine' => 'nullable|string|max:80',
            'model' => 'nullable|string|max:160',
            'use_rag' => 'nullable|boolean',
            'rag_collections' => 'nullable',
            'user_id' => 'nullable|string|max:255',
        ];
    }

    public function ragCollections(): array
    {
        $value = $this->input('rag_collections', []);

        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded)
                ? array_values(array_filter($decoded, 'is_string'))
                : [];
        }

        return [];
    }
}
