<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;

/**
 * Grounded knowledge-base retrieval for the AiNative runtime.
 *
 * AiNative is a pure tool-calling loop with no built-in access to the vector /
 * document RAG store. This tool exposes the SAME retrieval pipeline the classic
 * SEARCH_RAG path uses (AgentConversationService::executeSearchRAG), so the runtime
 * can ground answers in indexed knowledge. It is also what a force_rag turn calls:
 * AiNativePromptBuilder instructs the planner to call this first when the caller set
 * force_rag (see the force_rag directive there).
 */
class SearchKnowledgeTool extends SimpleAgentTool
{
    public string $name = 'search_knowledge';

    public string $description = 'Semantic search over the project knowledge base (RAG / vector store): retrieves relevant passages from indexed documents and text to ground an answer. Use for open-ended "what/why/how/about" questions or when you are unsure of a fact. For exact counts, lists, or filters of structured records, use the data_query tool instead.';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $parameters = [
        'query' => ['type' => 'string', 'required' => true, 'description' => 'What to look up in the knowledge base.'],
        'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Maximum number of results to retrieve (1-50).'],
    ];

    public function __construct(protected AgentConversationService $conversation)
    {
    }

    protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $query = trim((string) ($parameters['query'] ?? ''));
        if ($query === '') {
            return ActionResult::failure('A non-empty "query" is required to search the knowledge base.');
        }

        $options = ['force_rag' => true, 'use_rag' => true];
        if (isset($parameters['limit']) && is_numeric($parameters['limit'])) {
            $options['limit'] = max(1, min(50, (int) $parameters['limit']));
        }

        // executeSearchRAG can decide a request is really a CRUD action and "exit to
        // orchestrator" via the reroute callback. Inside a tool there is no orchestrator
        // to re-enter, so we return a benign note instead and let the runtime's own
        // action tools handle the write on a later step.
        $response = $this->conversation->executeSearchRAG(
            $query,
            $context,
            $options,
            static fn (string $rerouteMessage, $sessionId, $userId, array $rerouteOptions): AgentResponse => AgentResponse::conversational(
                'The knowledge base did not hold a direct answer; this request may need an action tool instead of retrieval.',
                $context
            )
        );

        $text = trim((string) $response->message);
        if ($text === '') {
            return ActionResult::failure('The knowledge base returned no usable result for this query.');
        }

        return ActionResult::success($text, [
            'query' => $query,
            'metadata' => is_array($response->metadata ?? null) ? $response->metadata : [],
        ]);
    }
}
