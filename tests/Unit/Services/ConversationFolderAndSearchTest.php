<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\ConversationTranscriptService;
use LaravelAIEngine\Tests\TestCase;

class ConversationFolderAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private ConversationManager $manager;
    private ConversationTranscriptService $transcripts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(ConversationManager::class);
        $this->transcripts = app(ConversationTranscriptService::class);
    }

    public function test_set_conversation_folder_assigns_and_clears(): void
    {
        $conversation = $this->manager->createConversation(userId: 'u-1', title: 'Project chat');

        $this->assertTrue($this->manager->setConversationFolder($conversation->conversation_id, 'folder-a'));
        $this->assertSame('folder-a', $conversation->fresh()->folder_id);

        // Empty string clears the folder.
        $this->assertTrue($this->manager->setConversationFolder($conversation->conversation_id, ''));
        $this->assertNull($conversation->fresh()->folder_id);
    }

    public function test_list_filters_by_folder(): void
    {
        $a = $this->manager->createConversation(userId: 'u-1', title: 'In folder');
        $this->manager->setConversationFolder($a->conversation_id, 'work');
        $this->manager->createConversation(userId: 'u-1', title: 'No folder');

        $all = $this->transcripts->listUserConversations('u-1');
        $this->assertSame(2, $all->pagination->total);

        $work = $this->transcripts->listUserConversations('u-1', 20, 1, 'work');
        $this->assertSame(1, $work->pagination->total);
    }

    public function test_search_matches_title(): void
    {
        $this->manager->createConversation(userId: 'u-1', title: 'Quarterly invoice review');
        $this->manager->createConversation(userId: 'u-1', title: 'Holiday plans');

        $result = $this->transcripts->searchUserConversations('u-1', 'invoice');

        $this->assertSame(1, $result->pagination->total);
    }

    public function test_search_matches_message_content(): void
    {
        $conversation = $this->manager->createConversation(userId: 'u-1', title: 'Untitled');
        $this->manager->addUserMessage($conversation->conversation_id, 'Please summarize the pelican report');

        $this->manager->createConversation(userId: 'u-1', title: 'Other');

        $result = $this->transcripts->searchUserConversations('u-1', 'pelican');

        $this->assertSame(1, $result->pagination->total);
    }

    public function test_search_is_scoped_to_user(): void
    {
        $other = $this->manager->createConversation(userId: 'u-2', title: 'invoice secrets');
        $this->manager->addUserMessage($other->conversation_id, 'invoice details');

        $result = $this->transcripts->searchUserConversations('u-1', 'invoice');

        $this->assertSame(0, $result->pagination->total);
    }

    public function test_blank_search_term_falls_back_to_list(): void
    {
        $this->manager->createConversation(userId: 'u-1', title: 'A');
        $this->manager->createConversation(userId: 'u-1', title: 'B');

        $result = $this->transcripts->searchUserConversations('u-1', '   ');

        $this->assertSame(2, $result->pagination->total);
    }
}
