<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Http\Requests\AnalyzeFileRequest;
use LaravelAIEngine\Services\FileAnalysisService;

class FileAnalysisApiController extends Controller
{
    public function __construct(
        protected FileAnalysisService $fileAnalysis,
    ) {}

    public function analyze(AnalyzeFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $response = $this->fileAnalysis->analyzeFile(
                $file,
                $request->input('message', 'Analyze this file and extract relevant information.'),
                (string) $request->input('session_id'),
                $request->input('engine', 'openai'),
                $request->input('model', 'gpt-4o'),
                $request->boolean('use_rag', true),
                $request->ragCollections(),
                $request->input('user_id') ?? $request->user()?->getAuthIdentifier()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'response' => $response['content'],
                    'extracted_data' => $response['extracted_data'] ?? null,
                    'file_type' => $file->getMimeType(),
                    'file_name' => $file->getClientOriginalName(),
                    'sources' => $response['sources'] ?? [],
                    'rag_enabled' => $response['rag_enabled'] ?? false,
                    'suggested_actions' => $response['suggestions'] ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('File analysis error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
