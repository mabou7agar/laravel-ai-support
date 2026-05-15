<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Live;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Models\AIMedia;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use LaravelAIEngine\Tests\TestCase;

class LiveFeatureMatrixTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('ai-engine.admin_ui.enabled', true);
        $app['config']->set('ai-engine.admin_ui.route_prefix', 'ai-engine/admin');
        $app['config']->set('ai-engine.admin_ui.middleware', ['web']);
        $app['config']->set('ai-engine.nodes.enabled', true);
    }

    public function test_live_feature_matrix_reports_passed_failed_and_skipped_features(): void
    {
        $results = [];

        foreach ($this->selectedFeatures() as $feature) {
            $results[] = match ($feature) {
                'admin_ui' => $this->runFeature('admin_ui', fn (): array => $this->checkAdminUi()),
                'rag_api' => $this->runFeature('rag_api', fn (): array => $this->checkRagApi()),
                'node_public_api' => $this->runFeature('node_public_api', fn (): array => $this->checkNodePublicApi()),
                'ai_media' => $this->runFeature('ai_media', fn (): array => $this->checkAiMedia()),
                'text_generation' => $this->runFeature('text_generation', fn (): array => $this->checkLiveTextGeneration()),
                'image_generation' => $this->runFeature('image_generation', fn (): array => $this->checkLiveImageGeneration()),
                'video_generation' => $this->runFeature('video_generation', fn (): array => $this->checkLiveVideoGeneration()),
                'tts_generation' => $this->runFeature('tts_generation', fn (): array => $this->checkLiveTtsGeneration()),
                'transcription' => $this->runFeature('transcription', fn (): array => $this->checkLiveTranscription()),
                'agent_flow' => $this->runFeature('agent_flow', fn (): array => $this->checkLiveAgentFlow()),
                'graph_rag' => $this->runFeature('graph_rag', fn (): array => $this->checkLiveGraphRag()),
                default => $this->skippedResult($feature, 'Unknown feature name.'),
            };
        }

        $summary = $this->summarize($results);
        fwrite(STDERR, json_encode([
            'summary' => $summary,
            'features' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $failed = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'failed'));
        $skipped = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'skipped'));

        $this->assertCount(
            0,
            $failed,
            'Live feature matrix failures: ' . json_encode($failed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if ($this->readBoolEnv('AI_ENGINE_LIVE_REQUIRE_ALL')) {
            $this->assertCount(
                0,
                $skipped,
                'Live feature matrix has skipped features while AI_ENGINE_LIVE_REQUIRE_ALL=true: '
                . json_encode($skipped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    private function selectedFeatures(): array
    {
        $raw = getenv('AI_ENGINE_LIVE_FEATURES');
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'admin_ui',
                'rag_api',
                'node_public_api',
                'ai_media',
                'text_generation',
                'image_generation',
                'video_generation',
                'tts_generation',
                'transcription',
                'agent_flow',
                'graph_rag',
            ];
        }

        $features = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $features), static fn (string $feature): bool => $feature !== ''));
    }

    private function runFeature(string $name, callable $callback): array
    {
        $start = microtime(true);

        try {
            $result = $callback();
            $result['name'] = $name;
            $result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);

            return $result;
        } catch (\Throwable $e) {
            if ($this->isProviderCredentialOrEntitlementFailure($e)) {
                return $this->skippedResult(
                    $name,
                    'Live provider rejected the request because the configured credential, account, or resource is not available for this feature.',
                    ['error' => $e->getMessage()]
                );
            }

            return [
                'name' => $name,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }

    private function isProviderCredentialOrEntitlementFailure(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, '402')
            || str_contains($message, '401')
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'invalid api key')
            || str_contains($message, 'invalid_api_key')
            || str_contains($message, 'payment required')
            || str_contains($message, 'payment_required')
            || str_contains($message, 'paid plan')
            || str_contains($message, 'paid_plan_required')
            || str_contains($message, 'insufficient quota')
            || str_contains($message, 'insufficient_quota')
            || str_contains($message, 'quota_exceeded');
    }

    private function summarize(array $results): array
    {
        $counts = [
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($results as $result) {
            $status = $result['status'] ?? 'failed';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'total' => count($results),
            'passed' => $counts['passed'],
            'failed' => $counts['failed'],
            'skipped' => $counts['skipped'],
            'live_provider_mode' => $this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS'),
        ];
    }

    private function passedResult(string $message, array $details = []): array
    {
        return [
            'status' => 'passed',
            'message' => $message,
            'details' => $details,
        ];
    }

    private function skippedResult(string $name, string $message, array $details = []): array
    {
        return [
            'name' => $name,
            'status' => 'skipped',
            'message' => $message,
            'details' => $details,
            'duration_ms' => 0,
        ];
    }

    private function checkAdminUi(): array
    {
        config()->set('ai-engine.admin_ui.enabled', true);
        config()->set('ai-engine.admin_ui.route_prefix', 'ai-engine/admin');
        config()->set('ai-engine.admin_ui.access.allow_localhost', false);
        config()->set('ai-engine.admin_ui.access.allowed_user_ids', []);
        config()->set('ai-engine.admin_ui.access.allowed_emails', []);
        config()->set('ai-engine.admin_ui.access.allowed_ips', ['127.0.0.1']);

        $dashboard = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/ai-engine/admin');
        $nodes = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/ai-engine/admin/nodes');
        $health = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/ai-engine/admin/health');
        $policies = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/ai-engine/admin/policies');

        $dashboard->assertOk()->assertSee('Admin')->assertSee('Manifest Manager');
        $nodes->assertOk()->assertSee('Nodes');
        $health->assertOk()->assertSee('Infrastructure Health');
        $policies->assertOk()->assertSee('Prompt Policies');

        return $this->passedResult('Admin UI routes responded successfully.', [
            'paths' => [
                '/ai-engine/admin',
                '/ai-engine/admin/nodes',
                '/ai-engine/admin/health',
                '/ai-engine/admin/policies',
            ],
        ]);
    }

    private function checkRagApi(): array
    {
        $health = $this->getJson('/api/v1/rag/health');
        $engines = $this->getJson('/api/v1/rag/engines');
        $collections = $this->getJson('/api/v1/rag/collections');

        $health->assertOk()->assertJsonPath('success', true);
        $engines->assertOk()->assertJsonPath('success', true);
        $collections->assertOk()->assertJsonPath('success', true);

        $collectionsPayload = $collections->json('data.collections') ?? [];

        return $this->passedResult('RAG API surface responded successfully.', [
            'collections_count' => is_array($collectionsPayload) ? count($collectionsPayload) : 0,
        ]);
    }

    private function checkNodePublicApi(): array
    {
        $health = $this->getJson('/api/ai-engine/health');
        $manifest = $this->getJson('/api/ai-engine/manifest');

        $health->assertStatus(200);
        $manifest->assertOk();

        return $this->passedResult('Node public API responded successfully.', [
            'health_status' => $health->json('status'),
            'manifest_name' => $manifest->json('name'),
        ]);
    }

    private function checkAiMedia(): array
    {
        $exitCode = Artisan::call('ai-engine:test-ai-media', [
            '--write-test' => true,
            '--cleanup' => true,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exitCode, $output);
        $this->assertIsArray($payload, $output);
        $this->assertTrue((bool) ($payload['summary']['table_exists'] ?? false), $output);
        $this->assertTrue((bool) ($payload['write_test']['exists_on_disk'] ?? false), $output);

        return $this->passedResult('AIMedia storage write/read/cleanup succeeded.', [
            'disk' => $payload['summary']['disk'] ?? null,
            'directory' => $payload['summary']['directory'] ?? null,
        ]);
    }

    private function checkLiveTextGeneration(): array
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            return $this->skippedResult('text_generation', 'Set AI_ENGINE_RUN_LIVE_TESTS=true to enable billed live provider checks.');
        }

        $pairs = $this->resolveProviderMatrix('AI_ENGINE_LIVE_TEXT_PROVIDER_MATRIX', fn (): array => [$this->resolveLiveTextEngineAndModel()]);
        if ($pairs === []) {
            return $this->skippedResult('text_generation', 'No live text provider credential is configured.');
        }

        $details = [];
        foreach ($pairs as [$engine, $model]) {
            $this->configureLiveProvider($engine, $model);
            $response = $this->postJson('/api/v1/ai/generate/text', [
                'prompt' => 'Reply with exactly two words: live ok',
                'engine' => $engine,
                'model' => $model,
                'max_tokens' => 20,
                'temperature' => 0,
            ]);

            $response->assertOk()->assertJsonPath('success', true);
            $details[] = [
                'engine' => $engine,
                'model' => $model,
                'content_length' => strlen((string) $response->json('data.content')),
            ];
        }

        return $this->passedResult('Live text generation succeeded.', [
            'providers' => $details,
        ]);
    }

    private function checkLiveImageGeneration(): array
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            return $this->skippedResult('image_generation', 'Set AI_ENGINE_RUN_LIVE_TESTS=true to enable billed live provider checks.');
        }

        $pairs = $this->resolveProviderMatrix('AI_ENGINE_LIVE_IMAGE_PROVIDER_MATRIX', fn (): array => [$this->resolveLiveImageEngineAndModel()]);
        if ($pairs === []) {
            return $this->skippedResult('image_generation', 'No live image provider credential is configured.');
        }

        $details = [];
        foreach ($pairs as [$engine, $model]) {
            $this->configureLiveProvider($engine, $model);

            $response = $this->postJson('/api/v1/ai/generate/image', [
                'prompt' => 'A minimal black square on a white background',
                'engine' => $engine,
                'model' => $model,
                'count' => 1,
            ]);

            $payload = $response->json();
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $response->assertJsonPath('success', true);

            $files = $payload['data']['files'] ?? [];
            $this->assertNotEmpty($files, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $details[] = [
                'engine' => $engine,
                'model' => $model,
                'files_count' => is_array($files) ? count($files) : 0,
            ];
        }

        return $this->passedResult('Live image generation succeeded.', ['providers' => $details]);
    }

    private function checkLiveVideoGeneration(): array
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            return $this->skippedResult('video_generation', 'Set AI_ENGINE_RUN_LIVE_TESTS=true to enable billed live provider checks.');
        }

        $pairs = $this->resolveProviderMatrix('AI_ENGINE_LIVE_VIDEO_PROVIDER_MATRIX', fn (): array => [$this->resolveLiveVideoEngineAndModel()]);
        if ($pairs === []) {
            return $this->skippedResult('video_generation', 'No live video provider credential is configured.');
        }

        $details = [];
        foreach ($pairs as [$engine, $model]) {
            $this->configureLiveProvider($engine, $model);

            $response = $this->postJson('/api/v1/ai/generate/video', [
                'prompt' => 'A short animation of a bouncing ball',
                'engine' => $engine,
                'model' => $model,
                'duration' => '4',
                'generate_audio' => false,
                'async' => $engine === 'fal_ai',
                'use_webhook' => false,
            ]);

            $payload = $response->json();
            if ($engine === 'fal_ai') {
                if ($response->getStatusCode() !== 202) {
                    throw new \RuntimeException(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }

                $jobId = (string) ($payload['data']['job_id'] ?? '');
                $this->assertNotSame('', $jobId, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $completed = $this->waitForFalVideoJob($jobId);
                $files = data_get($completed, 'data.metadata.response.files', []);
                $this->assertNotEmpty($files, json_encode($completed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $details[] = [
                    'engine' => $engine,
                    'model' => $model,
                    'job_id' => $jobId,
                    'files_count' => is_array($files) ? count($files) : 0,
                ];

                continue;
            }

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $response->assertJsonPath('success', true);

            $files = $payload['data']['files'] ?? [];
            $this->assertNotEmpty($files, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $details[] = [
                'engine' => $engine,
                'model' => $model,
                'files_count' => is_array($files) ? count($files) : 0,
            ];
        }

        return $this->passedResult('Live video generation succeeded.', ['providers' => $details]);
    }

    private function waitForFalVideoJob(string $jobId): array
    {
        $timeout = max(30, (int) getenv('AI_ENGINE_LIVE_FAL_VIDEO_TIMEOUT') ?: 300);
        $interval = max(2, (int) getenv('AI_ENGINE_LIVE_FAL_VIDEO_POLL_INTERVAL') ?: 5);
        $deadline = time() + $timeout;
        $lastPayload = [];

        do {
            $response = $this->getJson("/api/v1/ai/generate/video/jobs/{$jobId}?refresh=1");
            $payload = $response->json() ?? [];
            $lastPayload = $payload;

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $status = (string) data_get($payload, 'data.status', '');
            if ($status === 'completed') {
                return $payload;
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                throw new \RuntimeException(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            sleep($interval);
        } while (time() < $deadline);

        throw new \RuntimeException('FAL video job did not complete before timeout: ' . json_encode($lastPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function checkLiveTtsGeneration(): array
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            return $this->skippedResult('tts_generation', 'Set AI_ENGINE_RUN_LIVE_TESTS=true to enable billed live provider checks.');
        }

        $pairs = $this->resolveProviderMatrix('AI_ENGINE_LIVE_TTS_PROVIDER_MATRIX', fn (): array => [$this->resolveLiveTtsEngineAndModel()]);
        if ($pairs === []) {
            return $this->skippedResult('tts_generation', 'No live TTS provider credential is configured.');
        }

        $details = [];
        foreach ($pairs as [$engine, $model]) {
            $this->configureLiveProvider($engine, $model);
            $alias = 'voice-hero-' . uniqid();
            app(FalCharacterStore::class)->save([
                'name' => 'Voice Hero',
                'voice_id' => (string) (getenv('ELEVENLABS_VOICE_ID') ?: config('ai-engine.engines.eleven_labs.default_voice_id', 'pNInz6obpgDQGcFmaJgB')),
                'voice_settings' => [
                    'stability' => 0.35,
                    'similarity_boost' => 0.75,
                    'style' => 0.1,
                    'use_speaker_boost' => true,
                ],
            ], $alias);

            $beforeId = AIMedia::query()->max('id');
            $response = $this->postJson('/api/v1/ai/generate/tts', [
                'text' => 'This is a live text to speech check.',
                'engine' => $engine,
                'model' => $model,
                'minutes' => 1,
                'use_character' => $alias,
            ]);

            $payload = $response->json();
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $response->assertJsonPath('success', true);

            $audio = AIMedia::query()
                ->when($beforeId !== null, fn ($query) => $query->where('id', '>', $beforeId))
                ->where('content_type', 'audio')
                ->latest('id')
                ->first();

            $this->assertNotNull($audio, 'Expected a persisted AIMedia audio row after live TTS generation.');

            $details[] = [
                'engine' => $engine,
                'model' => $model,
                'character_alias' => $alias,
                'media_id' => $audio?->id,
                'disk' => $audio?->disk,
            ];
        }

        return $this->passedResult('Live TTS generation succeeded.', ['providers' => $details]);
    }

    private function checkLiveTranscription(): array
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            return $this->skippedResult('transcription', 'Set AI_ENGINE_RUN_LIVE_TESTS=true to enable billed live provider checks.');
        }

        $transcribePairs = $this->resolveProviderMatrix('AI_ENGINE_LIVE_TRANSCRIBE_PROVIDER_MATRIX', fn (): array => [$this->resolveLiveTranscriptionEngineAndModel()]);

        if ($transcribePairs === []) {
            return $this->skippedResult('transcription', 'No live transcription provider credential is configured.');
        }

        $details = [];
        foreach ($transcribePairs as [$engine, $model]) {
            $this->configureLiveProvider($engine, $model);
            $absolutePath = $this->createLiveTranscriptionWavFile();

            $upload = new UploadedFile(
                $absolutePath,
                'live-transcription.wav',
                'audio/wav',
                null,
                true
            );

            try {
                $response = $this->post('/api/v1/ai/generate/transcribe', [
                    'engine' => $engine,
                    'model' => $model,
                    'audio_minutes' => 0.1,
                    'file' => $upload,
                ], [
                    'Accept' => 'application/json',
                ]);
            } finally {
                if (is_file($absolutePath)) {
                    unlink($absolutePath);
                }
            }

            $payload = json_decode($response->getContent(), true);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException($response->getContent());
            }

            $this->assertIsArray($payload, $response->getContent());
            $this->assertTrue((bool) ($payload['success'] ?? false), $response->getContent());

            $details[] = [
                'engine' => $engine,
                'model' => $model,
                'transcript_length' => strlen((string) ($payload['data']['content'] ?? '')),
            ];
        }

        return $this->passedResult('Live transcription succeeded using a generated WAV fixture.', [
            'providers' => $details,
        ]);
    }

    private function createLiveTranscriptionWavFile(): string
    {
        $sampleRate = 16000;
        $durationSeconds = 1;
        $samples = $sampleRate * $durationSeconds;
        $data = '';

        for ($i = 0; $i < $samples; $i++) {
            $amplitude = (int) round(8000 * sin(2 * M_PI * 440 * ($i / $sampleRate)));
            $data .= pack('v', $amplitude & 0xffff);
        }

        $path = sys_get_temp_dir() . '/ai-engine-live-transcription-' . bin2hex(random_bytes(6)) . '.wav';
        $dataLength = strlen($data);
        $header = 'RIFF'
            . pack('V', 36 + $dataLength)
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            . 'data'
            . pack('V', $dataLength);

        file_put_contents($path, $header . $data);

        return $path;
    }

    private function checkLiveAgentFlow(): array
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            return $this->skippedResult('agent_flow', 'Set AI_ENGINE_RUN_LIVE_TESTS=true to enable billed live provider checks.');
        }

        $pairs = $this->resolveProviderMatrix('AI_ENGINE_LIVE_AGENT_PROVIDER_MATRIX', fn (): array => [$this->resolveLiveTextEngineAndModel()]);
        if ($pairs === []) {
            return $this->skippedResult('agent_flow', 'No live text provider credential is configured for agent flow.');
        }

        $details = [];
        foreach ($pairs as [$engine, $model]) {
            $this->configureLiveProvider($engine, $model);

            $userId = (string) $this->createTestUser()->id;
            $options = [
                '--session' => 'live-matrix-agent-' . uniqid(),
                '--user' => $userId,
                '--engine' => $engine,
                '--model' => $model,
                '--json' => true,
                '--script' => (string) (getenv('AI_ENGINE_LIVE_SCRIPT') ?: 'minimal'),
            ];

            if ($this->readBoolEnv('AI_ENGINE_LIVE_LOCAL_ONLY', true)) {
                $options['--local-only'] = true;
            }

            $exitCode = Artisan::call('ai-engine:test-real-agent', $options);
            $output = Artisan::output();
            $payload = json_decode($output, true);

            $this->assertSame(0, $exitCode, $output);
            $this->assertIsArray($payload, $output);
            $this->assertSame(0, $payload['summary']['failed_turns'] ?? null, $output);
            $this->assertGreaterThan(0, $payload['summary']['successful_turns'] ?? 0, $output);

            $details[] = [
                'engine' => $engine,
                'model' => $model,
                'successful_turns' => $payload['summary']['successful_turns'] ?? 0,
            ];
        }

        return $this->passedResult('Live agent flow succeeded.', [
            'providers' => $details,
        ]);
    }

    private function checkLiveGraphRag(): array
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_NEO4J_LIVE_TESTS')) {
            return $this->skippedResult('graph_rag', 'Set AI_ENGINE_RUN_NEO4J_LIVE_TESTS=true to enable live graph checks.');
        }

        $url = trim((string) getenv('AI_ENGINE_NEO4J_URL'));
        $database = trim((string) (getenv('AI_ENGINE_NEO4J_DATABASE') ?: 'neo4j'));
        $username = trim((string) (getenv('AI_ENGINE_NEO4J_USERNAME') ?: 'neo4j'));
        $password = trim((string) getenv('AI_ENGINE_NEO4J_PASSWORD'));

        if ($url === '' || $password === '') {
            return $this->skippedResult('graph_rag', 'Missing AI_ENGINE_NEO4J_URL or AI_ENGINE_NEO4J_PASSWORD.');
        }

        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withBasicAuth($username, $password)
            ->post(rtrim($url, '/') . '/db/' . trim($database, '/') . '/query/v2', [
                'statement' => 'RETURN 1 AS ok',
                'parameters' => (object) [],
            ]);

        $this->assertContains($response->status(), [200, 202], $response->body());
        $this->assertIsArray($response->json('data'), $response->body());

        return $this->passedResult('Live GraphRAG Query API responded successfully.', [
            'neo4j_url' => $url,
            'database' => $database,
        ]);
    }

    private function resolveLiveTextEngineAndModel(): array
    {
        $preferredEngine = getenv('AI_ENGINE_LIVE_ENGINE') ?: null;
        $preferredModel = getenv('AI_ENGINE_LIVE_MODEL') ?: null;

        if (is_string($preferredEngine) && trim($preferredEngine) !== '') {
            return $this->resolveProviderPair(trim($preferredEngine), $preferredModel ?: null);
        }

        foreach (['openai', 'anthropic', 'gemini', 'openrouter'] as $engine) {
            [$resolvedEngine, $resolvedModel] = $this->resolveProviderPair($engine);
            if ($resolvedEngine !== null && $resolvedModel !== null) {
                return [$resolvedEngine, $resolvedModel];
            }
        }

        return [null, null];
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function resolveProviderMatrix(string $envName, callable $fallback): array
    {
        $raw = getenv($envName);
        if (!is_string($raw) || trim($raw) === '') {
            return array_values(array_filter($fallback(), static fn ($pair): bool => is_array($pair) && !empty($pair[0]) && !empty($pair[1])));
        }

        $pairs = [];
        foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }

            [$engine, $model] = array_map('trim', explode(':', $pair, 2));
            [$resolvedEngine, $resolvedModel] = $this->resolveProviderPair($engine, $model);
            if ($resolvedEngine !== null && $resolvedModel !== null) {
                $pairs[] = [$resolvedEngine, $resolvedModel];
            }
        }

        return $pairs;
    }

    private function resolveLiveImageEngineAndModel(): array
    {
        $preferredEngine = getenv('AI_ENGINE_LIVE_IMAGE_ENGINE') ?: getenv('AI_ENGINE_LIVE_ENGINE') ?: null;
        $preferredModel = getenv('AI_ENGINE_LIVE_IMAGE_MODEL') ?: null;

        if (is_string($preferredEngine) && trim($preferredEngine) !== '') {
            return $this->resolveProviderPair(trim($preferredEngine), $preferredModel ?: match ($preferredEngine) {
                'openai' => 'gpt-image-1-mini',
                'fal_ai' => 'fal-ai/nano-banana-2',
                default => null,
            });
        }

        foreach ([
            ['fal_ai', 'fal-ai/nano-banana-2'],
            ['openai', 'gpt-image-1-mini'],
        ] as [$engine, $model]) {
            [$resolvedEngine, $resolvedModel] = $this->resolveProviderPair($engine, $model);
            if ($resolvedEngine !== null && $resolvedModel !== null) {
                return [$resolvedEngine, $resolvedModel];
            }
        }

        return [null, null];
    }

    private function resolveLiveVideoEngineAndModel(): array
    {
        $preferredEngine = getenv('AI_ENGINE_LIVE_VIDEO_ENGINE') ?: null;
        $preferredModel = getenv('AI_ENGINE_LIVE_VIDEO_MODEL') ?: null;

        if (is_string($preferredEngine) && trim($preferredEngine) !== '') {
            return $this->resolveProviderPair(trim($preferredEngine), $preferredModel ?: null);
        }

        return $this->resolveProviderPair('fal_ai', 'bytedance/seedance-2.0/text-to-video');
    }

    private function resolveLiveTtsEngineAndModel(): array
    {
        $preferredEngine = getenv('AI_ENGINE_LIVE_TTS_ENGINE') ?: null;
        $preferredModel = getenv('AI_ENGINE_LIVE_TTS_MODEL') ?: null;

        if (is_string($preferredEngine) && trim($preferredEngine) !== '') {
            return $this->resolveProviderPair(trim($preferredEngine), $preferredModel ?: null);
        }

        return $this->resolveProviderPair('eleven_labs', 'eleven_multilingual_v2');
    }

    private function resolveLiveTranscriptionEngineAndModel(): array
    {
        $preferredEngine = getenv('AI_ENGINE_LIVE_TRANSCRIBE_ENGINE') ?: null;
        $preferredModel = getenv('AI_ENGINE_LIVE_TRANSCRIBE_MODEL') ?: null;

        if (is_string($preferredEngine) && trim($preferredEngine) !== '') {
            return $this->resolveProviderPair(trim($preferredEngine), $preferredModel ?: null);
        }

        return $this->resolveProviderPair('openai', 'whisper-1');
    }

    private function resolveProviderPair(string $engine, ?string $model = null): array
    {
        if (!$this->hasLiveCredential($engine)) {
            return [null, null];
        }

        $resolvedModel = $model ?: match ($engine) {
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-sonnet-20240620',
            'gemini' => 'gemini-1.5-flash',
            'openrouter' => 'openai/gpt-4o-mini',
            'eleven_labs' => 'eleven_multilingual_v2',
            'fal_ai' => 'fal-ai/nano-banana-2',
            default => null,
        };

        return [$engine, $resolvedModel];
    }

    private function hasLiveCredential(string $engine): bool
    {
        return match ($engine) {
            'openai' => $this->hasNonEmptyEnv('OPENAI_API_KEY'),
            'anthropic' => $this->hasNonEmptyEnv('ANTHROPIC_API_KEY'),
            'gemini' => $this->hasNonEmptyEnv('GEMINI_API_KEY'),
            'openrouter' => $this->hasNonEmptyEnv('OPENROUTER_API_KEY'),
            'eleven_labs' => $this->hasNonEmptyEnv('ELEVENLABS_API_KEY'),
            'fal_ai' => $this->hasAnyNonEmptyEnv(['FAL_AI_API_KEY', 'FAL_API_KEY', 'FALAI_API_KEY']),
            default => false,
        };
    }

    private function hasNonEmptyEnv(string $key): bool
    {
        $value = getenv($key);

        return is_string($value) && trim($value) !== '';
    }

    private function hasAnyNonEmptyEnv(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->hasNonEmptyEnv($key)) {
                return true;
            }
        }

        return false;
    }

    private function firstNonEmptyEnv(array $keys): string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function configureLiveProvider(string $engine, string $model): void
    {
        config()->set('ai-engine.default', $engine);
        config()->set('ai-engine.default_model', $model);

        match ($engine) {
            'openai' => config()->set('ai-engine.engines.openai.api_key', (string) getenv('OPENAI_API_KEY')),
            'anthropic' => config()->set('ai-engine.engines.anthropic.api_key', (string) getenv('ANTHROPIC_API_KEY')),
            'gemini' => config()->set('ai-engine.engines.gemini.api_key', (string) getenv('GEMINI_API_KEY')),
            'openrouter' => config()->set('ai-engine.engines.openrouter.api_key', (string) getenv('OPENROUTER_API_KEY')),
            'eleven_labs' => config()->set('ai-engine.engines.eleven_labs.api_key', (string) getenv('ELEVENLABS_API_KEY')),
            'fal_ai' => config()->set('ai-engine.engines.fal_ai.api_key', $this->firstNonEmptyEnv(['FAL_AI_API_KEY', 'FAL_API_KEY', 'FALAI_API_KEY'])),
            default => null,
        };
    }

    private function readBoolEnv(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
