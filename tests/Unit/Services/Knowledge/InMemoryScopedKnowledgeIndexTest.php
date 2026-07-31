<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Knowledge;

use LaravelAIEngine\Contracts\ScopedKnowledgeIndex;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\Enums\KnowledgeScope;
use LaravelAIEngine\Services\Knowledge\InMemoryScopedKnowledgeIndex;
use LaravelAIEngine\Services\Knowledge\PersistentVectorScopedKnowledgeIndex;
use LaravelAIEngine\Tests\UnitTestCase;

final class InMemoryScopedKnowledgeIndexTest extends UnitTestCase
{
    public function test_config_can_select_the_persistent_vector_binding(): void
    {
        config()->set('ai-agent.assistant.knowledge_index.driver', 'vector');
        $this->app->forgetInstance(ScopedKnowledgeIndex::class);

        self::assertInstanceOf(
            PersistentVectorScopedKnowledgeIndex::class,
            app(ScopedKnowledgeIndex::class),
        );
    }

    public function test_it_ranks_mixed_arabic_and_english_knowledge_without_a_phrase_map(): void
    {
        $documents = [
            new ScopedKnowledgeDocument(
                id: 'course-guide',
                text: 'دليل إدارة Laravel course من الإنشاء حتى النشر',
                scope: KnowledgeScope::GlobalShared,
                metadata: ['title' => 'Course operations'],
            ),
            new ScopedKnowledgeDocument(
                id: 'billing-guide',
                text: 'Invoice and subscription operations',
                scope: KnowledgeScope::GlobalShared,
            ),
        ];

        $matches = (new InMemoryScopedKnowledgeIndex())->search(
            $documents,
            'إدارة Laravel course',
            5,
        );

        self::assertCount(1, $matches);
        self::assertSame('course-guide', $matches[0]->document->id);
        self::assertGreaterThan(1.0, $matches[0]->score);
    }

    public function test_it_uses_metadata_title_and_keywords_and_returns_a_stable_order(): void
    {
        $documents = [
            new ScopedKnowledgeDocument(
                id: 'b',
                text: 'Operations',
                scope: KnowledgeScope::GlobalShared,
                metadata: ['keywords' => ['academy', 'course']],
            ),
            new ScopedKnowledgeDocument(
                id: 'a',
                text: 'Operations',
                scope: KnowledgeScope::GlobalShared,
                metadata: ['title' => 'Academy course'],
            ),
        ];

        $matches = (new InMemoryScopedKnowledgeIndex())->search($documents, 'academy course', 5);

        self::assertSame(['a', 'b'], array_map(
            static fn ($match): string => $match->document->id,
            $matches,
        ));
    }

    public function test_it_returns_no_results_for_empty_or_unmatched_queries(): void
    {
        $documents = [
            new ScopedKnowledgeDocument('guide', 'Create a course', KnowledgeScope::GlobalShared),
        ];
        $index = new InMemoryScopedKnowledgeIndex();

        self::assertSame([], $index->search($documents, ''));
        self::assertSame([], $index->search($documents, 'revenue report'));
    }

    public function test_default_index_honors_the_configured_document_bound(): void
    {
        config()->set('ai-agent.assistant.knowledge_index.max_documents', 1);
        $documents = [
            new ScopedKnowledgeDocument('first', 'Unrelated guide', KnowledgeScope::GlobalShared),
            new ScopedKnowledgeDocument('second', 'Target course guide', KnowledgeScope::GlobalShared),
        ];

        self::assertSame(
            [],
            (new InMemoryScopedKnowledgeIndex())->search($documents, 'target course'),
        );
    }
}
