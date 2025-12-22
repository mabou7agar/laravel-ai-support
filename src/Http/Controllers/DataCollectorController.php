<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\DataCollector\DataCollectorChatService;
use LaravelAIEngine\DTOs\DataCollectorConfig;

/**
 * Example controller for Data Collector Chat
 * 
 * This controller demonstrates how to integrate the Data Collector
 * into your application. You can extend or customize this for your needs.
 */
class DataCollectorController extends Controller
{
    public function __construct(
        protected DataCollectorChatService $dataCollector
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

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $response->getMetadata(),
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
            'name' => 'required|string',
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'fields' => 'required|array',
            'session_id' => 'nullable|string',
            'initial_data' => 'nullable|array',
            'confirm_before_complete' => 'nullable|boolean',
            'allow_enhancement' => 'nullable|boolean',
            'language' => 'nullable|string',
        ]);

        $sessionId = $request->input('session_id', 'dc-' . uniqid());
        
        $config = new DataCollectorConfig(
            name: $request->input('name'),
            title: $request->input('title') ?? '',
            description: $request->input('description') ?? '',
            fields: $request->input('fields'),
            confirmBeforeComplete: $request->boolean('confirm_before_complete', true),
            allowEnhancement: $request->boolean('allow_enhancement', true),
            locale: $request->input('language'),
        );

        $response = $this->dataCollector->startCollection(
            $sessionId,
            $config,
            $request->input('initial_data', [])
        );

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $response->getMetadata(),
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
            'engine' => 'nullable|string',
            'model' => 'nullable|string',
        ]);

        $response = $this->dataCollector->processMessage(
            $request->input('session_id'),
            $request->input('message'),
            $request->input('engine', 'openai'),
            $request->input('model', 'gpt-4o')
        );

        return response()->json([
            'success' => $response->isSuccessful(),
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $response->getMetadata(),
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
            'file' => 'required|file|max:10240|mimes:pdf,txt,doc,docx',
            'session_id' => 'required|string',
            'fields' => 'nullable|string',
            'field_config' => 'nullable|string',
            'language' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $sessionId = $request->input('session_id');
        $fields = json_decode($request->input('fields', '[]'), true) ?: [];
        $fieldConfig = json_decode($request->input('field_config', '{}'), true) ?: [];
        $language = $request->input('language', 'en');

        try {
            // Extract text from file
            $content = $this->extractFileContent($file);
            
            if (empty($content)) {
                return response()->json([
                    'success' => false,
                    'message' => $language === 'ar' 
                        ? 'لم نتمكن من قراءة محتوى الملف.' 
                        : 'Could not read file content.',
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
                    'message' => $language === 'ar' 
                        ? 'لم نتمكن من استخراج بيانات من الملف.' 
                        : 'Could not extract data from file.',
                ]);
            }

            return response()->json([
                'success' => true,
                'extracted_data' => $extractedData,
                'message' => $language === 'ar' 
                    ? 'تم استخراج البيانات بنجاح.' 
                    : 'Data extracted successfully.',
            ]);

        } catch (\Exception $e) {
            \Log::error('File analysis error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $language === 'ar' 
                    ? 'حدث خطأ أثناء تحليل الملف.' 
                    : 'Error analyzing file.',
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
        $language = $request->input('language', 'en');

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
                'message' => $language === 'ar' 
                    ? 'حدث خطأ أثناء تطبيق البيانات.' 
                    : 'Error applying data.',
            ], 500);
        }
    }

    /**
     * Extract text content from uploaded file
     */
    protected function extractFileContent($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $content = '';

        switch ($extension) {
            case 'txt':
                $content = file_get_contents($file->getRealPath());
                break;

            case 'pdf':
                $content = $this->extractPdfContent($file);
                break;

            case 'doc':
            case 'docx':
                $content = $this->extractDocContent($file);
                break;
        }

        // Clean and limit content
        $content = trim(preg_replace('/\s+/', ' ', $content));
        return mb_substr($content, 0, 50000); // Limit to ~50k chars
    }

    /**
     * Extract text from PDF file
     */
    protected function extractPdfContent($file): string
    {
        // Try using Smalot PDF Parser if available
        if (class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file->getRealPath());
                return $pdf->getText();
            } catch (\Exception $e) {
                \Log::warning('PDF parsing failed: ' . $e->getMessage());
            }
        }

        // Fallback: try pdftotext command if available
        $outputFile = tempnam(sys_get_temp_dir(), 'pdf_');
        $command = sprintf('pdftotext -layout %s %s 2>/dev/null', 
            escapeshellarg($file->getRealPath()), 
            escapeshellarg($outputFile)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($outputFile)) {
            $content = file_get_contents($outputFile);
            unlink($outputFile);
            return $content;
        }

        return '';
    }

    /**
     * Extract text from DOC/DOCX file
     */
    protected function extractDocContent($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'docx') {
            // Extract from DOCX (ZIP with XML)
            $zip = new \ZipArchive();
            if ($zip->open($file->getRealPath()) === true) {
                $content = '';
                $xml = $zip->getFromName('word/document.xml');
                if ($xml) {
                    $dom = new \DOMDocument();
                    @$dom->loadXML($xml);
                    $content = strip_tags($dom->saveXML());
                }
                $zip->close();
                return $content;
            }
        }

        // For DOC files, try antiword if available
        if ($extension === 'doc') {
            $command = sprintf('antiword %s 2>/dev/null', escapeshellarg($file->getRealPath()));
            exec($command, $output, $returnCode);
            if ($returnCode === 0) {
                return implode("\n", $output);
            }
        }

        return '';
    }
}
