<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\SDK\FileStoreService;
use LaravelAIEngine\Services\SDK\VectorStoreService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class HostedStoreDriversTest extends UnitTestCase
{
    public function test_openai_file_store_upload_get_and_delete_use_provider_api(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-engine-file-');
        file_put_contents($path, 'hello');

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('/v1/files', Mockery::on(fn (array $options): bool => isset($options['multipart'])))
            ->andReturn(new Response(200, [], json_encode(['id' => 'file_1', 'filename' => basename($path)])));
        $client->shouldReceive('get')
            ->once()
            ->with('/v1/files/file_1', Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['id' => 'file_1'])));
        $client->shouldReceive('delete')
            ->once()
            ->with('/v1/files/file_1', Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['deleted' => true])));

        try {
            $files = (new FileStoreService())->provider('openai', ['api_key' => 'test'], $client);

            $uploaded = $files->upload(Document::fromPath($path));
            $loaded = $files->get('file_1');
            $deleted = $files->delete('file_1');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->assertSame('file_1', $uploaded['id']);
        $this->assertSame('file_1', $loaded['id']);
        $this->assertTrue($deleted);
    }

    public function test_openai_vector_store_driver_creates_adds_and_deletes_remote_store(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('/v1/vector_stores', Mockery::on(fn (array $options): bool => ($options['json']['name'] ?? null) === 'Docs'))
            ->andReturn(new Response(200, [], json_encode(['id' => 'vs_1', 'name' => 'Docs'])));
        $client->shouldReceive('post')
            ->once()
            ->with('/v1/vector_stores/vs_1/files', Mockery::on(fn (array $options): bool => ($options['json']['file_id'] ?? null) === 'file_1'))
            ->andReturn(new Response(200, [], json_encode(['id' => 'vsf_1'])));
        $client->shouldReceive('get')
            ->once()
            ->with('/v1/vector_stores/vs_1', Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['id' => 'vs_1', 'name' => 'Docs'])));
        $client->shouldReceive('delete')
            ->once()
            ->with('/v1/vector_stores/vs_1', Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['deleted' => true])));

        $stores = (new VectorStoreService())->provider('openai', ['api_key' => 'test'], $client);

        $created = $stores->create('Docs');
        $updated = $stores->add('vs_1', new Document('file_1', metadata: ['provider_file_id' => 'file_1']));
        $deleted = $stores->delete('vs_1');

        $this->assertSame('vs_1', $created['id']);
        $this->assertSame('openai', $created['provider']);
        $this->assertSame('Docs', $updated['name']);
        $this->assertTrue($deleted);
    }
}
