<?php

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Drivers\Midjourney\MidjourneyEngineDriver;
use LaravelAIEngine\Drivers\Serper\SerperEngineDriver;
use LaravelAIEngine\Drivers\Unsplash\UnsplashEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

/**
 * Functional tests for the thinly-tested media/search drivers:
 * Midjourney (image gen + poll), Serper (web search), Unsplash (photo search).
 *
 * All three drivers build their own Guzzle client internally, so the mock
 * client + history middleware is injected through reflection over the private
 * client property after construction. No real network is performed.
 */
class DrvMediaSearchImageDriversTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $drvMediaHistory = [];

    /** @var string[] */
    private array $drvMediaTempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->drvMediaTempFiles as $file) {
            @unlink($file);
        }
        $this->drvMediaTempFiles = [];

        parent::tearDown();
    }

    /**
     * Build a mock Guzzle client that records outgoing requests into
     * $this->drvMediaHistory and replays the supplied responses in order.
     *
     * @param array<int, Response> $responses
     */
    private function drvMediaMockClient(array $responses): Client
    {
        $this->drvMediaHistory = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->drvMediaHistory));

        return new Client([
            'handler' => $stack,
            'base_uri' => 'https://example.test',
        ]);
    }

    /**
     * Overwrite a private/protected client property on a driver with the mock.
     */
    private function drvMediaInjectClient(object $driver, string $property, Client $client): void
    {
        $ref = new \ReflectionProperty($driver, $property);
        $ref->setAccessible(true);
        $ref->setValue($driver, $client);
    }

    // ===================================================================
    // Serper - web search
    // ===================================================================

    public function test_serper_web_search_posts_query_and_maps_organic_results(): void
    {
        $driver = new SerperEngineDriver([
            'api_key' => 'serper-key',
            'base_url' => 'https://google.serper.dev',
        ]);

        $this->drvMediaInjectClient($driver, 'httpClient', $this->drvMediaMockClient([
            new Response(200, ['content-type' => 'application/json'], json_encode([
                'searchParameters' => ['q' => 'laravel testing'],
                'searchInformation' => ['totalResults' => '42', 'searchTime' => 0.31],
                'organic' => [
                    [
                        'title' => 'Laravel Testing Guide',
                        'link' => 'https://laravel.com/docs/testing',
                        'snippet' => 'How to test Laravel apps.',
                        'position' => 1,
                    ],
                    [
                        'title' => 'Pest PHP',
                        'link' => 'https://pestphp.com',
                        'snippet' => 'An elegant testing framework.',
                        'position' => 2,
                    ],
                ],
            ])),
        ]));

        $response = $driver->webSearch(new AIRequest(
            prompt: 'laravel testing',
            engine: EngineEnum::Serper,
            model: EntityEnum::from(EntityEnum::SERPER_SEARCH),
            parameters: ['location' => 'gb', 'language' => 'en', 'num' => 5],
        ));

        $this->assertTrue($response->isSuccessful());

        // Request assertions: correct verb, endpoint, auth header and JSON body.
        $sent = $this->drvMediaHistory[0]['request'];
        $this->assertSame('POST', $sent->getMethod());
        $this->assertStringContainsString('/search', (string) $sent->getUri());
        $body = json_decode((string) $sent->getBody(), true);
        $this->assertSame('laravel testing', $body['q']);
        $this->assertSame('gb', $body['gl']);
        $this->assertSame(5, $body['num']);

        // Response mapping: organic results flattened to title/link/snippet.
        $results = json_decode($response->getContent(), true);
        $this->assertCount(2, $results);
        $this->assertSame('Laravel Testing Guide', $results[0]['title']);
        $this->assertSame('https://pestphp.com', $results[1]['link']);

        $usage = $response->getUsage();
        $this->assertSame('search', $usage['search_type']);
        $this->assertSame(2, $usage['results_count']);
        $this->assertSame('42', (string) $usage['total_results']);
    }

    public function test_serper_image_search_sets_images_type_and_maps_image_fields(): void
    {
        $driver = new SerperEngineDriver([
            'api_key' => 'serper-key',
            'base_url' => 'https://google.serper.dev',
        ]);

        $this->drvMediaInjectClient($driver, 'httpClient', $this->drvMediaMockClient([
            new Response(200, [], json_encode([
                'images' => [
                    [
                        'title' => 'A cat',
                        'imageUrl' => 'https://img.test/cat.jpg',
                        'imageWidth' => 800,
                        'imageHeight' => 600,
                        'thumbnailUrl' => 'https://img.test/cat-thumb.jpg',
                        'source' => 'example.com',
                        'link' => 'https://example.com/cat',
                    ],
                ],
            ])),
        ]));

        $response = $driver->webSearch(new AIRequest(
            prompt: 'cats',
            engine: EngineEnum::Serper,
            model: EntityEnum::from(EntityEnum::SERPER_IMAGES),
            parameters: ['search_type' => 'images'],
        ));

        $this->assertTrue($response->isSuccessful());

        $body = json_decode((string) $this->drvMediaHistory[0]['request']->getBody(), true);
        $this->assertSame('images', $body['type']);
        $this->assertSame('active', $body['safe']);

        $results = json_decode($response->getContent(), true);
        $this->assertSame('https://img.test/cat.jpg', $results[0]['imageUrl']);
        $this->assertSame(800, $results[0]['imageWidth']);
        $this->assertSame('images', $response->getUsage()['search_type']);
    }

    public function test_serper_returns_error_response_when_http_client_throws(): void
    {
        $driver = new SerperEngineDriver([
            'api_key' => 'serper-key',
            'base_url' => 'https://google.serper.dev',
        ]);

        $this->drvMediaInjectClient($driver, 'httpClient', $this->drvMediaMockClient([
            new Response(500, [], 'boom'),
        ]));

        $response = $driver->webSearch(new AIRequest(
            prompt: 'will fail',
            engine: EngineEnum::Serper,
            model: EntityEnum::from(EntityEnum::SERPER_SEARCH),
        ));

        $this->assertFalse($response->isSuccessful());
        $this->assertStringContainsString('Serper API error', $response->getError());
    }

    // ===================================================================
    // Unsplash - photo search
    // ===================================================================

    public function test_unsplash_photo_search_sends_query_params_and_maps_results(): void
    {
        $driver = new UnsplashEngineDriver([
            'access_key' => 'unsplash-access',
            'base_url' => 'https://api.unsplash.com',
        ]);

        $this->drvMediaInjectClient($driver, 'httpClient', $this->drvMediaMockClient([
            new Response(200, ['content-type' => 'application/json'], json_encode([
                'total' => 1234,
                'total_pages' => 62,
                'results' => [
                    [
                        'id' => 'abc123',
                        'description' => 'A mountain at sunrise',
                        'alt_description' => 'mountain',
                        'urls' => [
                            'raw' => 'https://images.test/raw',
                            'full' => 'https://images.test/full',
                            'regular' => 'https://images.test/regular',
                            'small' => 'https://images.test/small',
                            'thumb' => 'https://images.test/thumb',
                        ],
                        'width' => 4000,
                        'height' => 3000,
                        'color' => '#abcdef',
                        'likes' => 99,
                        'user' => [
                            'id' => 'u1',
                            'username' => 'shooter',
                            'name' => 'Sam Shooter',
                        ],
                        'tags' => [
                            ['title' => 'nature', 'type' => 'search'],
                        ],
                    ],
                ],
            ])),
        ]));

        $response = $driver->searchPhotos(new AIRequest(
            prompt: 'mountains',
            engine: EngineEnum::Unsplash,
            model: EntityEnum::from(EntityEnum::UNSPLASH_SEARCH),
            parameters: ['page' => 2, 'per_page' => 50, 'orientation' => 'landscape', 'color' => 'blue'],
        ));

        $this->assertTrue($response->isSuccessful());

        // Request assertions: GET /search/photos with Client-ID auth and query string.
        $sent = $this->drvMediaHistory[0]['request'];
        $this->assertSame('GET', $sent->getMethod());
        $uri = (string) $sent->getUri();
        $this->assertStringContainsString('/search/photos', $uri);

        parse_str($sent->getUri()->getQuery(), $query);
        $this->assertSame('mountains', $query['query']);
        $this->assertSame('2', $query['page']);
        // per_page is capped at 30.
        $this->assertSame('30', $query['per_page']);
        $this->assertSame('landscape', $query['orientation']);
        $this->assertSame('blue', $query['color']);

        // Response mapping: results normalized into the rich photo shape.
        $photos = json_decode($response->getContent(), true);
        $this->assertCount(1, $photos);
        $this->assertSame('abc123', $photos[0]['id']);
        $this->assertSame('https://images.test/regular', $photos[0]['urls']['regular']);
        $this->assertSame('shooter', $photos[0]['user']['username']);
        $this->assertSame('nature', $photos[0]['tags'][0]['title']);

        $usage = $response->getUsage();
        $this->assertSame(1234, $usage['total_results']);
        $this->assertSame(62, $usage['total_pages']);
        $this->assertSame(1, $usage['photos_count']);
    }

    public function test_unsplash_photo_details_hits_id_endpoint(): void
    {
        $driver = new UnsplashEngineDriver([
            'access_key' => 'unsplash-access',
            'base_url' => 'https://api.unsplash.com',
        ]);

        $this->drvMediaInjectClient($driver, 'httpClient', $this->drvMediaMockClient([
            new Response(200, [], json_encode([
                'id' => 'photo-77',
                'urls' => ['regular' => 'https://images.test/77'],
                'user' => ['username' => 'jane'],
            ])),
        ]));

        $response = $driver->getPhotoDetails(new AIRequest(
            prompt: 'photo-77',
            engine: EngineEnum::Unsplash,
            model: EntityEnum::from(EntityEnum::UNSPLASH_SEARCH),
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('/photos/photo-77', (string) $this->drvMediaHistory[0]['request']->getUri());

        $photo = json_decode($response->getContent(), true);
        $this->assertSame('photo-77', $photo['id']);
        $this->assertSame('jane', $photo['user']['username']);
    }

    // ===================================================================
    // Midjourney - image generation + poll
    // ===================================================================

    private function drvMediaMidjourneyConfig(): void
    {
        config()->set('ai-engine.engines.midjourney.api_key', 'mj-key');
        config()->set('ai-engine.engines.midjourney.base_url', 'https://api.midjourney.com');
        config()->set('ai-engine.engines.midjourney.discord_token', 'discord-token');
        config()->set('ai-engine.engines.midjourney.server_id', 'server-1');
        config()->set('ai-engine.engines.midjourney.channel_id', 'channel-1');
        config()->set('ai-engine.engines.midjourney.timeout', 5);
    }

    public function test_midjourney_constructor_requires_api_key_and_discord_token(): void
    {
        config()->set('ai-engine.engines.midjourney.api_key', '');
        config()->set('ai-engine.engines.midjourney.base_url', 'https://api.midjourney.com');
        config()->set('ai-engine.engines.midjourney.discord_token', '');
        config()->set('ai-engine.engines.midjourney.server_id', '');
        config()->set('ai-engine.engines.midjourney.channel_id', '');

        $this->expectException(\LaravelAIEngine\Exceptions\AIEngineException::class);

        new MidjourneyEngineDriver();
    }

    public function test_midjourney_generate_submits_imagine_polls_and_maps_images(): void
    {
        $this->drvMediaMidjourneyConfig();
        Storage::fake();

        // Local file used as the "remote" image URL so saveImageFromUrl()'s
        // file_get_contents() resolves without any network access.
        $tempImage = tempnam(sys_get_temp_dir(), 'drvmedia_mj_') . '.png';
        file_put_contents($tempImage, "\x89PNG drvmedia-bytes");
        $this->drvMediaTempFiles[] = $tempImage;
        $imageUrl = 'file://' . $tempImage;

        $driver = new MidjourneyEngineDriver();

        // 1) POST /v1/imagine -> job id, 2) GET /v1/jobs/{id} -> completed w/ images.
        $this->drvMediaInjectClient($driver, 'client', $this->drvMediaMockClient([
            new Response(200, [], json_encode(['job_id' => 'job-xyz'])),
            new Response(200, [], json_encode([
                'status' => 'completed',
                'images' => [
                    ['url' => $imageUrl, 'width' => 1024, 'height' => 1024, 'hash' => 'h1'],
                ],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'a serene lake',
            engine: EngineEnum::Midjourney,
            model: new EntityEnum(EntityEnum::MIDJOURNEY_V6),
            parameters: ['aspect_ratio' => '16:9'],
        ));

        // Success path now builds a complete AIResponse carrying the engine/model.
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(EngineEnum::Midjourney, $response->getEngine());
        $this->assertSame(EntityEnum::MIDJOURNEY_V6, $response->getModel()->value);

        // Mapped images are returned as the JSON content and reflected in usage.
        $images = json_decode($response->getContent(), true);
        $this->assertCount(1, $images);
        $this->assertSame($imageUrl, $images[0]['url']);
        $this->assertSame(1, $response->getUsage()['images_generated']);
        $this->assertSame('job-xyz', $response->getMetadata()['job_id']);

        // Imagine request: correct endpoint + payload incl. version-decorated prompt.
        $imagine = $this->drvMediaHistory[0]['request'];
        $this->assertSame('POST', $imagine->getMethod());
        $this->assertStringContainsString('/v1/imagine', (string) $imagine->getUri());
        $payload = json_decode((string) $imagine->getBody(), true);
        $this->assertSame('imagine', $payload['type']);
        $this->assertStringContainsString('--v 6', $payload['prompt']);
        $this->assertStringContainsString('--ar 16:9', $payload['prompt']);
        $this->assertSame('discord-token', $payload['discord_token']);
        $this->assertSame('server-1', $payload['server_id']);

        // Poll request hit the job endpoint with the returned id.
        $this->assertStringContainsString('/v1/jobs/job-xyz', (string) $this->drvMediaHistory[1]['request']->getUri());
        $this->assertCount(2, $this->drvMediaHistory);

        // The downloaded image was persisted to storage during processImages().
        $stored = Storage::files('ai-generated/midjourney/images');
        $this->assertNotEmpty($stored);
    }

    public function test_midjourney_generate_throws_when_job_fails(): void
    {
        $this->drvMediaMidjourneyConfig();

        $driver = new MidjourneyEngineDriver();

        $this->drvMediaInjectClient($driver, 'client', $this->drvMediaMockClient([
            new Response(200, [], json_encode(['job_id' => 'job-fail'])),
            new Response(200, [], json_encode(['status' => 'failed', 'error' => 'content policy'])),
        ]));

        $this->expectException(\LaravelAIEngine\Exceptions\AIEngineException::class);
        $this->expectExceptionMessage('content policy');

        $driver->generate(new AIRequest(
            prompt: 'bad prompt',
            engine: EngineEnum::Midjourney,
            model: new EntityEnum(EntityEnum::MIDJOURNEY_V6),
        ));
    }

    public function test_midjourney_validate_request_rejects_unsupported_model(): void
    {
        $this->drvMediaMidjourneyConfig();

        $driver = new MidjourneyEngineDriver();

        $this->expectException(\LaravelAIEngine\Exceptions\AIEngineException::class);

        $driver->validateRequest(new AIRequest(
            prompt: 'hello',
            engine: EngineEnum::Midjourney,
            model: new EntityEnum('not-midjourney'),
        ));
    }
}
