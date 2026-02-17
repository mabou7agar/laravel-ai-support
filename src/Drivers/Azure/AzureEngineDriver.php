<?php

namespace LaravelAIEngine\Drivers\Azure;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AzureEngineDriver implements EngineDriverInterface
{
    private Client $client;
    private string $apiKey;
    private string $region;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('ai-engine.engines.azure.api_key');
        $this->region = config('ai-engine.engines.azure.region', 'eastus');
        $this->baseUrl = config('ai-engine.engines.azure.base_url', "https://{$this->region}.api.cognitive.microsoft.com");

        if (empty($this->apiKey)) {
            throw new AIEngineException('Azure Cognitive Services API key is required');
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('ai-engine.engines.azure.timeout', 60),
            'headers' => [
                'Ocp-Apim-Subscription-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        try {
            switch ($request->entity) {
                case EntityEnum::AZURE_TTS:
                    return $this->generateSpeech($request);
                case EntityEnum::AZURE_STT:
                    return $this->transcribeAudio($request);
                case EntityEnum::AZURE_TRANSLATOR:
                    return $this->translateText($request);
                case EntityEnum::AZURE_TEXT_ANALYTICS:
                    return $this->analyzeText($request);
                case EntityEnum::AZURE_COMPUTER_VISION:
                    return $this->analyzeImage($request);
                default:
                    throw new AIEngineException("Entity {$request->entity->value} not supported by Azure driver");
            }
        } catch (RequestException $e) {
            throw new AIEngineException('Azure Cognitive Services API request failed: ' . $e->getMessage());
        }
    }

    private function generateSpeech(AIRequest $request): AIResponse
    {
        $payload = [
            'text' => $request->getPrompt(),
            'voice' => $request->getParameters()['voice'] ?? 'en-US-AriaNeural',
            'rate' => $request->getParameters()['rate'] ?? '0%',
            'pitch' => $request->getParameters()['pitch'] ?? '0%',
            'volume' => $request->getParameters()['volume'] ?? '0%',
            'output_format' => $request->getParameters()['output_format'] ?? 'audio-16khz-128kbitrate-mono-mp3',
        ];

        $ssml = $this->buildSSML($payload);

        $response = $this->client->post('/cognitiveservices/v1', [
            'headers' => [
                'Content-Type' => 'application/ssml+xml',
                'X-Microsoft-OutputFormat' => $payload['output_format'],
                'User-Agent' => 'LaravelAIEngine/1.0',
            ],
            'body' => $ssml,
        ]);

        $audioContent = $response->getBody()->getContents();
        $filename = $this->saveAudioContent($audioContent, 'mp3');

        $audioData = [
            'filename' => $filename,
            'path' => Storage::url($filename),
            'duration' => $this->estimateAudioDuration($request->getPrompt()),
            'voice' => $payload['voice'],
            'format' => $payload['output_format'],
            'size' => strlen($audioContent),
        ];

        return new AIResponse(
            content: json_encode($audioData),
            usage: [
                'characters_processed' => strlen($request->getPrompt()),
                'audio_duration' => $audioData['duration'],
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::AZURE,
                'service' => 'text_to_speech',
                'audio' => $audioData,
            ]
        );
    }

    private function transcribeAudio(AIRequest $request): AIResponse
    {
        if (empty($request->getParameters()['audio_file'])) {
            throw new AIEngineException('Audio file is required for speech-to-text');
        }

        $audioContent = Storage::get($request->getParameters()['audio_file']);

        $response = $this->client->post('/speechtotext/v3.0/transcriptions', [
            'headers' => [
                'Content-Type' => 'audio/wav',
            ],
            'body' => $audioContent,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        $transcriptionData = [
            'text' => $data['DisplayText'] ?? '',
            'confidence' => $data['Confidence'] ?? 0,
            'duration' => $data['Duration'] ?? 0,
            'language' => $data['Language'] ?? 'en-US',
            'words' => $data['NBest'][0]['Words'] ?? [],
        ];

        return new AIResponse(
            content: $transcriptionData['text'],
            usage: [
                'audio_duration' => $transcriptionData['duration'],
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::AZURE,
                'service' => 'speech_to_text',
                'transcription' => $transcriptionData,
            ]
        );
    }

    private function translateText(AIRequest $request): AIResponse
    {
        $payload = [
            [
                'text' => $request->getPrompt(),
            ]
        ];

        $targetLanguage = $request->getParameters()['target_language'] ?? 'es';
        $sourceLanguage = $request->getParameters()['source_language'] ?? null;

        $queryParams = ['api-version' => '3.0', 'to' => $targetLanguage];
        if ($sourceLanguage) {
            $queryParams['from'] = $sourceLanguage;
        }

        $response = $this->client->post('/translator/text/v3.0/translate?' . http_build_query($queryParams), [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $translation = $data[0]['translations'][0] ?? [];

        $translationData = [
            'original_text' => $request->getPrompt(),
            'translated_text' => $translation['text'] ?? '',
            'source_language' => $data[0]['detectedLanguage']['language'] ?? $sourceLanguage,
            'target_language' => $targetLanguage,
            'confidence' => $data[0]['detectedLanguage']['score'] ?? 1.0,
        ];

        return new AIResponse(
            content: $translationData['translated_text'],
            usage: [
                'characters_translated' => strlen($request->getPrompt()),
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::AZURE,
                'service' => 'translator',
                'translation' => $translationData,
            ]
        );
    }

    private function analyzeText(AIRequest $request): AIResponse
    {
        $payload = [
            'documents' => [
                [
                    'id' => '1',
                    'text' => $request->getPrompt(),
                    'language' => $request->getParameters()['language'] ?? 'en',
                ]
            ]
        ];

        $analysisType = $request->getParameters()['analysis_type'] ?? 'sentiment';

        $endpoint = match ($analysisType) {
            'sentiment' => '/text/analytics/v3.1/sentiment',
            'key_phrases' => '/text/analytics/v3.1/keyPhrases',
            'entities' => '/text/analytics/v3.1/entities/recognition/general',
            'language' => '/text/analytics/v3.1/languages',
            default => '/text/analytics/v3.1/sentiment',
        };

        $response = $this->client->post($endpoint, [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $document = $data['documents'][0] ?? [];

        $analysisData = [
            'analysis_type' => $analysisType,
            'results' => $document,
            'language' => $document['language'] ?? 'en',
        ];

        return new AIResponse(
            content: json_encode($analysisData),
            usage: [
                'characters_analyzed' => strlen($request->getPrompt()),
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::AZURE,
                'service' => 'text_analytics',
                'analysis' => $analysisData,
            ]
        );
    }

    private function analyzeImage(AIRequest $request): AIResponse
    {
        if (empty($request->getParameters()['image_url']) && empty($request->getParameters()['image_file'])) {
            throw new AIEngineException('Image URL or file is required for computer vision');
        }

        $analysisType = $request->getParameters()['analysis_type'] ?? 'analyze';

        if (!empty($request->getParameters()['image_url'])) {
            $payload = ['url' => $request->getParameters()['image_url']];
            $headers = ['Content-Type' => 'application/json'];
            $body = json_encode($payload);
        } else {
            $imageContent = Storage::get($request->getParameters()['image_file']);
            $headers = ['Content-Type' => 'application/octet-stream'];
            $body = $imageContent;
        }

        $features = $request->getParameters()['features'] ?? ['Categories', 'Description', 'Objects', 'Tags'];
        $endpoint = "/vision/v3.2/{$analysisType}?" . http_build_query(['visualFeatures' => implode(',', $features)]);

        $response = $this->client->post($endpoint, [
            'headers' => $headers,
            'body' => $body,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        $visionData = [
            'analysis_type' => $analysisType,
            'categories' => $data['categories'] ?? [],
            'description' => $data['description'] ?? [],
            'objects' => $data['objects'] ?? [],
            'tags' => $data['tags'] ?? [],
            'faces' => $data['faces'] ?? [],
            'adult' => $data['adult'] ?? [],
        ];

        return new AIResponse(
            content: json_encode($visionData),
            usage: [
                'images_analyzed' => 1,
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::AZURE,
                'service' => 'computer_vision',
                'vision' => $visionData,
            ]
        );
    }

    private function buildSSML(array $payload): string
    {
        return sprintf(
            '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="en-US">
                <voice name="%s">
                    <prosody rate="%s" pitch="%s" volume="%s">
                        %s
                    </prosody>
                </voice>
            </speak>',
            $payload['voice'],
            $payload['rate'],
            $payload['pitch'],
            $payload['volume'],
            htmlspecialchars($payload['text'])
        );
    }

    private function saveAudioContent(string $content, string $extension): string
    {
        $filename = 'ai-generated/azure/audio/' . Str::uuid() . '.' . $extension;
        Storage::put($filename, $content);
        return $filename;
    }

    private function estimateAudioDuration(string $text): float
    {
        // Rough estimation: average speaking rate is about 150 words per minute
        $wordCount = str_word_count($text);
        return ($wordCount / 150) * 60; // Duration in seconds
    }

    public function stream(AIRequest $request): \Generator
    {
        // Azure Cognitive Services doesn't support streaming for most services
        // Return the full response as a single chunk
        $response = $this->generate($request);
        yield $response->getContent();
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::AZURE_TTS => [
                'name' => 'Azure Text-to-Speech',
                'description' => 'Neural text-to-speech with natural voices',
                'features' => ['neural_voices', 'ssml_support', 'multiple_languages'],
                'supported_languages' => 75,
            ],
            EntityEnum::AZURE_STT => [
                'name' => 'Azure Speech-to-Text',
                'description' => 'Accurate speech recognition and transcription',
                'features' => ['real_time', 'batch_processing', 'custom_models'],
                'supported_languages' => 85,
            ],
            EntityEnum::AZURE_TRANSLATOR => [
                'name' => 'Azure Translator',
                'description' => 'Real-time text translation',
                'features' => ['90_languages', 'auto_detect', 'custom_models'],
                'supported_languages' => 90,
            ],
            EntityEnum::AZURE_TEXT_ANALYTICS => [
                'name' => 'Azure Text Analytics',
                'description' => 'Sentiment analysis, key phrase extraction, entity recognition',
                'features' => ['sentiment', 'key_phrases', 'entities', 'language_detection'],
                'supported_languages' => 50,
            ],
            EntityEnum::AZURE_COMPUTER_VISION => [
                'name' => 'Azure Computer Vision',
                'description' => 'Image analysis and object detection',
                'features' => ['object_detection', 'ocr', 'face_detection', 'image_description'],
                'supported_formats' => ['JPEG', 'PNG', 'GIF', 'BMP'],
            ],
        ];
    }

    public function validateRequest(AIRequest $request): bool
    {
        // Check if model is supported
        if (!in_array($request->entity, [
            EntityEnum::AZURE_TTS,
            EntityEnum::AZURE_STT,
            EntityEnum::AZURE_TRANSLATOR,
            EntityEnum::AZURE_TEXT_ANALYTICS,
            EntityEnum::AZURE_COMPUTER_VISION,
        ])) {
            throw new AIEngineException("Model {$request->entity->value} is not supported by Azure driver");
        }

        // Validate based on service type
        switch ($request->entity) {
            case EntityEnum::AZURE_TTS:
                if (empty($request->getPrompt())) {
                    throw new AIEngineException('Text is required for text-to-speech');
                }
                break;

            case EntityEnum::AZURE_STT:
                if (empty($request->getParameters()['audio_file'])) {
                    throw new AIEngineException('Audio file is required for speech-to-text');
                }
                break;

            case EntityEnum::AZURE_COMPUTER_VISION:
                if (empty($request->getParameters()['image_url']) && empty($request->getParameters()['image_file'])) {
                    throw new AIEngineException('Image URL or file is required for computer vision');
                }
                break;
        }

        return true;
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::AZURE;
    }
}
