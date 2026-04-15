<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Support\Fal;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use LaravelAIEngine\Tests\TestCase;

class FalCharacterStoreTest extends TestCase
{
    public function test_get_falls_back_to_database_when_cache_entry_is_missing(): void
    {
        $store = app(FalCharacterStore::class);

        $store->save([
            'name' => 'Mina',
            'frontal_image_url' => 'https://app.test/storage/generated/mina-front.png',
            'frontal_provider_image_url' => 'https://v3.fal.media/files/mina-front.png',
            'voice_id' => 'voice-mina',
            'metadata' => [
                'entity_type' => 'character',
            ],
        ], 'mina-db-fallback');

        Cache::forget('ai-engine.fal.characters.mina-db-fallback');
        Cache::forget('ai-engine.fal.characters.index');
        Cache::forget('ai-engine.fal.characters.last');

        $restored = $store->get('mina-db-fallback');

        $this->assertNotNull($restored);
        $this->assertSame('mina-db-fallback', $restored['alias']);
        $this->assertSame('https://app.test/storage/generated/mina-front.png', $restored['frontal_image_url']);
        $this->assertSame('https://v3.fal.media/files/mina-front.png', $restored['frontal_provider_image_url']);
        $this->assertSame('voice-mina', $restored['voice_id']);
        $this->assertIsArray(Cache::get('ai-engine.fal.characters.mina-db-fallback'));
    }

    public function test_get_last_and_all_fall_back_to_database_when_cache_is_missing(): void
    {
        $store = app(FalCharacterStore::class);

        $store->save([
            'name' => 'First Pack',
            'frontal_image_url' => 'https://app.test/storage/generated/first.png',
            'metadata' => [
                'entity_type' => 'character',
            ],
        ], 'first-pack');

        $store->save([
            'name' => 'Second Pack',
            'frontal_image_url' => 'https://app.test/storage/generated/second.png',
            'voice_id' => 'voice-second',
            'metadata' => [
                'entity_type' => 'character',
            ],
        ], 'second-pack');

        Cache::forget('ai-engine.fal.characters.first-pack');
        Cache::forget('ai-engine.fal.characters.second-pack');
        Cache::forget('ai-engine.fal.characters.index');
        Cache::forget('ai-engine.fal.characters.last');

        $last = $store->getLast();
        $all = $store->all();

        $this->assertNotNull($last);
        $this->assertSame('second-pack', $last['alias']);
        $this->assertArrayHasKey('first-pack', $all);
        $this->assertArrayHasKey('second-pack', $all);
        $this->assertSame('voice-second', $all['second-pack']['voice_id']);
    }
}
