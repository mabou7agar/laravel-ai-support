<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\DataCollector\DataCollectorChatService;
use LaravelAIEngine\DTOs\DataCollectorConfig;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use LaravelAIEngine\Services\Media\DocumentService;

/**
 * Example controller for Data Collector Chat
 * 
 * This controller demonstrates how to integrate the Data Collector
 * into your application. You can extend or customize this for your needs.
 */
class DataCollectorController extends Controller
{
    public function __construct(
        protected DataCollectorChatService $dataCollector,
        protected DocumentService $documents,
        protected ?LocaleResourceService $localeResources = null
    ) {}

    /**
     * Start a new data collection session
     * 
     * POST /api/ai-engine/data-collector/start
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'config_name' => 'required|string',
            'session_id' => 'nullable|string',
            'initial_data' => 'nullable|array',
        ]);

        $configName = $request->input('config_name');
        $sessionId = $request->input('session_id', 'dc-' . uniqid());
        $initialData = $request->input('initial_data', []);

        // Get the registered config
        $config = $this->dataCollector->getConfig($configName);
        
        if (!$config) {
            return response()->json([
                'success' => false,
                'error' => "Configuration '{$configName}' not found.",
            ], 404);
        }

        $response = $this->dataCollector->startCollection($sessionId, $config, $initialData);
        $metadata = $response->getMetadata();

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $metadata,
            // Flatten commonly used fields for easier frontend access
            'config_name' => $metadata['config_name'] ?? $configName,
            'fields' => $metadata['fields'] ?? [],
            'current_field' => $metadata['current_field'] ?? null,
            'collected_fields' => $metadata['collected_fields'] ?? [],
            'remaining_fields' => $metadata['remaining_fields'] ?? [],
            'progress' => $metadata['progress'] ?? 0,
            'data' => $metadata['data'] ?? [],
            'config' => $metadata['config'] ?? null,
            'field_definitions' => $metadata['field_definitions'] ?? [],
        ]);
    }

    /**
     * Start a new data collection with inline config
     * 
     * POST /api/ai-engine/data-collector/start-custom
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function startCustom(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string',             // Optional - auto-generated UUID if not provided
            'title' => 'required|string',            // Required - display title for the user
            'description' => 'nullable|string',
            'fields' => 'required|array',
            'session_id' => 'nullable|string',
            'initial_data' => 'nullable|array',
            'confirm_before_complete' => 'nullable|boolean',
            'allow_enhancement' => 'nullable|boolean',
            'allow_skip_optional' => 'nullable|boolean',
            'language' => 'nullable|string',
            'success_message' => 'nullable|string',
            'cancel_message' => 'nullable|string',
            'system_prompt' => 'nullable|string',
            'action_summary' => 'nullable|string',
            'action_summary_prompt' => 'nullable|string',
            'action_summary_prompt_config' => 'nullable|array',
            'output_schema' => 'nullable|array',
            'output_prompt' => 'nullable|string',
            'output_config' => 'nullable|array',
            'on_complete_action' => 'nullable|string',
            'metadata' => 'nullable|array',
            'detect_locale' => 'nullable|boolean',
        ]);

        $sessionId = $request->input('session_id', 'dc-' . uniqid());
        
        $config = new DataCollectorConfig(
            name: $request->input('name'),           // Will auto-generate UUID if null
            title: $request->input('title'),
            description: $request->input('description') ?? '',
            fields: $request->input('fields'),
            onCompleteAction: $request->input('on_complete_action'),
            confirmBeforeComplete: $request->boolean('confirm_before_complete', true),
            allowEnhancement: $request->boolean('allow_enhancement', true),
            allowSkipOptional: $request->boolean('allow_skip_optional', true),
            successMessage: $request->input('success_message'),
            cancelMessage: $request->input('cancel_message'),
            metadata: $request->input('metadata', []),
            systemPrompt: $request->input('system_prompt'),
            actionSummary: $request->input('action_summary'),
            actionSummaryPrompt: $request->input('action_summary_prompt'),
            actionSummaryPromptConfig: $request->input('action_summary_prompt_config'),
            outputSchema: $request->input('output_schema'),
            outputPrompt: $request->input('output_prompt'),
            outputConfig: $request->input('output_config'),
            locale: $request->input('language'),
            detectLocale: $request->boolean('detect_locale', false),
        );

        $response = $this->dataCollector->startCollection(
            $sessionId,
            $config,
            $request->input('initial_data', [])
        );
        $metadata = $response->getMetadata();

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $metadata,
            // Flatten commonly used fields for easier frontend access
            'config_name' => $metadata['config_name'] ?? $config->name,
            'fields' => $metadata['fields'] ?? [],
            'current_field' => $metadata['current_field'] ?? null,
            'collected_fields' => $metadata['collected_fields'] ?? [],
            'remaining_fields' => $metadata['remaining_fields'] ?? [],
            'progress' => $metadata['progress'] ?? 0,
            'data' => $metadata['data'] ?? [],
            'config' => $metadata['config'] ?? null,
            'field_definitions' => $metadata['field_definitions'] ?? [],
        ]);
    }

    /**
     * Process a message in an active data collection session
     * 
     * POST /api/ai-engine/data-collector/message
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function message(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'message' => 'required|string',
            'config_name' => 'nullable|string',
            'engine' => 'nullable|string',
            'model' => 'nullable|string',
        ]);

        $response = $this->dataCollector->processMessage(
            $request->input('session_id'),
            $request->input('message'),
            $request->input('engine', 'openai'),
            $request->input('model', 'gpt-4o')
        );

        $metadata = $response->getMetadata();

        return response()->json([
            'success' => $response->isSuccessful(),
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $metadata,
            // Flatten commonly used fields for easier frontend access
            'config_name' => $metadata['config_name'] ?? null,
            'status' => $metadata['status'] ?? null,
            'is_complete' => $metadata['is_complete'] ?? false,
            'is_cancelled' => $metadata['is_cancelled'] ?? false,
            'requires_confirmation' => $metadata['requires_confirmation'] ?? false,
            'allows_enhancement' => $metadata['allows_enhancement'] ?? false,
            'current_field' => $metadata['current_field'] ?? null,
            'collected_fields' => $metadata['collected_fields'] ?? [],
            'remaining_fields' => $metadata['remaining_fields'] ?? [],
            'fields' => $metadata['fields'] ?? [],
            'progress' => $metadata['progress'] ?? 0,
            'data' => $metadata['data'] ?? [],
            'summary' => $metadata['summary'] ?? null,
            'action_summary' => $metadata['action_summary'] ?? null,
            'result' => $metadata['result'] ?? null,
            'generated_output' => $metadata['generated_output'] ?? null,
            'config' => $metadata['config'] ?? null,
        ]);
    }

    /**
     * Get the current state of a data collection session
     * 
     * GET /api/ai-engine/data-collector/status/{sessionId}
     * 
     * @param string $sessionId
     * @return JsonResponse
     */
    public function status(string $sessionId): JsonResponse
    {
        $state = $this->dataCollector->getSessionState($sessionId);

        if (!$state) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'status' => $state->status,
            'data' => $state->getData(),
            'current_field' => $state->currentField,
            'is_complete' => $state->isComplete(),
            'is_cancelled' => $state->isCancelled(),
            'validation_errors' => $state->validationErrors,
        ]);
    }

    /**
     * Cancel a data collection session
     * 
     * POST /api/ai-engine/data-collector/cancel
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function cancel(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $response = $this->dataCollector->cancelSession($request->input('session_id'));

        return response()->json([
            'success' => $response->isSuccessful(),
            'message' => $response->getContent(),
            'metadata' => $response->getMetadata(),
        ]);
    }

    /**
     * Get collected data from a session
     * 
     * GET /api/ai-engine/data-collector/data/{sessionId}
     * 
     * @param string $sessionId
     * @return JsonResponse
     */
    public function getData(string $sessionId): JsonResponse
    {
        $data = $this->dataCollector->getCollectedData($sessionId);

        if (empty($data) && !$this->dataCollector->isDataCollectionSession($sessionId)) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'data' => $data,
        ]);
    }

    /**
     * Analyze uploaded file and extract data
     * 
     * POST /api/ai-engine/data-collector/analyze-file
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function analyzeFile(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,txt,doc,docx,md,markdown,csv,xls,xlsx,ppt,pptx,rtf,odt,json,xml,html,htm',
            'session_id' => 'required|string',
            'fields' => 'nullable|string',
            'field_config' => 'nullable|string',
            'language' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $sessionId = $request->input('session_id');
        $fields = json_decode($request->input('fields', '[]'), true) ?: [];
        $fieldConfig = json_decode($request->input('field_config', '{}'), true) ?: [];
        $language = $request->input('language', $this->fallbackLocale());
        $locale = $this->locale()->resolveLocale($language);

        try {
            // Extract text from file
            $content = $this->extractFileContent($file);
            
            if (empty($content)) {
                return response()->json([
                    'success' => false,
                    'message' => $this->locale()->translation(
                        'ai-engine::runtime.data_collector.api.could_not_read_file',
                        locale: $locale
                    ) ?: 'Could not read file content.',
                ]);
            }

            // Use AI to extract structured data
            $extractedData = $this->dataCollector->extractDataFromContent(
                $sessionId,
                $content,
                $fields,
                $language,
                $fieldConfig
            );

            if (empty($extractedData)) {
                return response()->json([
                    'success' => false,
                    'message' => $this->locale()->translation(
                        'ai-engine::runtime.data_collector.api.could_not_extract_file',
                        locale: $locale
                    ) ?: 'Could not extract data from file.',
                ]);
            }

            return response()->json([
                'success' => true,
                'extracted_data' => $extractedData,
                'message' => $this->locale()->translation(
                    'ai-engine::runtime.data_collector.api.data_extracted_success',
                    locale: $locale
                ) ?: 'Data extracted successfully.',
            ]);

        } catch (\Exception $e) {
            \Log::error('File analysis error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $this->locale()->translation(
                    'ai-engine::runtime.data_collector.api.file_analysis_error',
                    locale: $locale
                ) ?: 'Error analyzing file.',
            ], 500);
        }
    }

    /**
     * Apply extracted data to session and go to confirmation
     * 
     * POST /api/ai-engine/data-collector/apply-extracted
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function applyExtracted(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'extracted_data' => 'required|array',
            'language' => 'nullable|string',
        ]);

        $sessionId = $request->input('session_id');
        $extractedData = $request->input('extracted_data');
        $language = $request->input('language', $this->fallbackLocale());
        $locale = $this->locale()->resolveLocale($language);

        try {
            $response = $this->dataCollector->applyExtractedData(
                $sessionId,
                $extractedData,
                $language
            );

            return response()->json([
                'success' => $response->isSuccessful(),
                'message' => $response->getContent(),
                'actions' => $response->getActions(),
                'metadata' => $response->getMetadata(),
                'requires_confirmation' => $response->getMetadata()['requires_confirmation'] ?? true,
            ]);

        } catch (\Exception $e) {
            \Log::error('Apply extracted data error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $this->locale()->translation(
                    'ai-engine::runtime.data_collector.api.apply_data_error',
                    locale: $locale
                ) ?: 'Error applying data.',
            ], 500);
        }
    }

    protected function fallbackLocale(): string
    {
        $fallback = config('ai-engine.localization.fallback_locale');
        if (is_string($fallback) && trim($fallback) !== '') {
            return $this->locale()->resolveLocale($fallback);
        }

        return $this->locale()->resolveLocale(app()->getLocale());
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }

    /**
     * Extract text content from uploaded file
     */
    protected function extractFileContent($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $content = $this->documents->extractText($file->getRealPath(), $extension);

        // Clean and limit content
        $content = trim(preg_replace('/\s+/', ' ', $content));
        return mb_substr($content, 0, 50000); // Limit to ~50k chars
    }
}
