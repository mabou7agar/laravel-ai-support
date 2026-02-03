<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Unified RAG Search Service
 *
 * Replaces complex FederatedSearchService with simple, fast queries
 * - Queries ai_nodes table for project metadata (no HTTP!)
 * - Searches shared Qdrant directly (no HTTP between projects!)
 * - AI-powered intelligent project selection
 * - 10x faster than old multi-node system
 */
class UnifiedRAGSearchService
{
    protected $vectorSearch;
    protected $aiEngine;
    protected $projectId;

    public function __construct(VectorSearchService $vectorSearch)
    {
        $this->vectorSearch = $vectorSearch;
        $this->aiEngine = app('ai-engine');
        $this->projectId = config('ai-engine.vector.project_id');
    }

    /**
     * Search with intelligent project selection based on context
     */
    public function searchWithContext(
        string $query,
        ?string $conversationContext = null,
        array $options = []
    ): array {
        // Analyze query to determine relevant projects
        $relevantProjects = $this->analyzeQueryForProjects($query, $conversationContext);

        Log::channel('ai-engine')->info('Intelligent project selection', [
            'query' => substr($query, 0, 100),
            'selected_projects' => $relevantProjects,
        ]);

        // Search only relevant projects
        return $this->searchAcrossProjects(
            query: $query,
            projectSlugs: $relevantProjects,
            collections: $options['collections'] ?? [],
            limit: $options['limit'] ?? 10
        );
    }

    /**
     * Search across multiple projects
     */
    public function searchAcrossProjects(
        string $query,
        array $projectSlugs = [],
        array $collections = [],
        int $limit = 10
    ): array {
        // Get project metadata from ai_nodes table (no HTTP!)
        $projectsQuery = AINode::where('type', 'project')
            ->where('status', 'active');

        if (!empty($projectSlugs)) {
            $projectsQuery->whereIn('slug', $projectSlugs);
        }

        $projects = $projectsQuery->get();

        if ($projects->isEmpty()) {
            Log::channel('ai-engine')->warning('No active projects found');
            return $this->emptyResult($query);
        }

        // Build collection names from project metadata
        $collectionsToSearch = $this->buildCollectionList($projects, $collections);

        Log::channel('ai-engine')->info('Searching collections', [
            'collections' => $collectionsToSearch,
            'projects' => $projects->pluck('slug')->toArray(),
        ]);

        // Search all collections in Qdrant (direct query, no HTTP!)
        $results = $this->searchCollections($query, $collectionsToSearch, $limit);

        // Group by project
        return $this->groupResultsByProject($results, $query);
    }

    /**
     * Search only current project
     */
    public function searchLocal(
        string $query,
        array $modelTypes = [],
        int $limit = 10
    ): array {
        return $this->searchAcrossProjects(
            query: $query,
            projectSlugs: [$this->projectId],
            collections: $modelTypes,
            limit: $limit
        );
    }

    /**
     * Analyze query to determine relevant projects using AI
     */
    protected function analyzeQueryForProjects(string $query, ?string $context = null): array
    {
        // Check cache first
        $cacheKey = 'project_routing:' . md5($query . ($context ?? ''));

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        // Get all active projects
        $projects = AINode::where('type', 'project')
            ->where('status', 'active')
            ->get();

        if ($projects->isEmpty()) {
            return [$this->projectId];
        }

        // Build analysis prompt
        $prompt = $this->buildProjectAnalysisPrompt($query, $context, $projects);

        // Use AI to determine relevant projects
        try {
            $response = $this->aiEngine->generateText([
                'prompt' => $prompt,
                'engine' => 'openai',
                'model' => 'gpt-4o-mini',
                'temperature' => 0.1,
                'max_tokens' => 200,
            ]);

            if ($response->isSuccess()) {
                $relevantProjects = $this->parseProjectsFromResponse($response->content, $projects);

                // Cache for 5 minutes
                Cache::put($cacheKey, $relevantProjects, 300);

                return $relevantProjects;
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to analyze projects from query', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: search all projects
        return $projects->pluck('slug')->toArray();
    }

    /**
     * Build prompt for project analysis
     */
    protected function buildProjectAnalysisPrompt(string $query, ?string $context, $projects): string
    {
        $prompt = "Analyze this user query and determine which projects are relevant to search.\n\n";
        $prompt .= "Available Projects:\n";

        foreach ($projects as $project) {
            $prompt .= "- {$project->slug}: {$project->description}\n";

            if (!empty($project->keywords)) {
                $prompt .= "  Keywords: " . implode(', ', $project->keywords) . "\n";
            }

            if (!empty($project->data_types)) {
                $prompt .= "  Data Types: " . implode(', ', $project->data_types) . "\n";
            }
        }

        $prompt .= "\nUser Query: \"{$query}\"\n";

        if ($context) {
            $prompt .= "\nConversation Context: {$context}\n";
        }

        $prompt .= "\nRespond with ONLY the project slugs that are relevant, separated by commas.\n";
        $prompt .= "If all projects are relevant, respond with: all\n";
        $prompt .= "If unsure, include all potentially relevant projects.\n";
        $prompt .= "\nExamples:\n";
        $prompt .= "- project_a\n";
        $prompt .= "- project_a,project_b\n";
        $prompt .= "- all\n";

        return $prompt;
    }

    /**
     * Parse projects from AI response
     */
    protected function parseProjectsFromResponse(string $response, $projects): array
    {
        $response = strtolower(trim($response));

        // Check for "all" keyword
        if (str_contains($response, 'all')) {
            return $projects->pluck('slug')->toArray();
        }

        // Extract project slugs
        $selectedProjects = [];

        foreach ($projects as $project) {
            if (str_contains($response, strtolower($project->slug))) {
                $selectedProjects[] = $project->slug;
            }
        }

        // If no projects found, return all (safe fallback)
        return !empty($selectedProjects) ? $selectedProjects : $projects->pluck('slug')->toArray();
    }

    /**
     * Build list of collections to search
     */
    protected function buildCollectionList($projects, array $requestedCollections): array
    {
        $collectionsToSearch = [];

        foreach ($projects as $project) {
            $projectCollections = $project->collections ?? [];

            foreach ($projectCollections as $collection) {
                $collectionName = is_array($collection) ? ($collection['name'] ?? null) : $collection;

                if (!$collectionName) {
                    continue;
                }

                // If specific collections requested, filter
                if (empty($requestedCollections)) {
                    $collectionsToSearch[] = $collectionName;
                } else {
                    // Check if this collection matches requested types
                    foreach ($requestedCollections as $requested) {
                        if (str_contains($collectionName, $requested)) {
                            $collectionsToSearch[] = $collectionName;
                            break;
                        }
                    }
                }
            }
        }

        return array_unique($collectionsToSearch);
    }

    /**
     * Search collections in Qdrant
     */
    protected function searchCollections(string $query, array $collections, int $limit): array
    {
        $allResults = [];

        foreach ($collections as $collection) {
            try {
                $results = $this->vectorSearch->searchByText(
                    $collection,
                    $query,
                    $limit,
                    0.3 // threshold
                );

                foreach ($results as $result) {
                    $allResults[] = [
                        'collection' => $collection,
                        'score' => $result['score'] ?? 0,
                        'content' => $result['content'] ?? '',
                        'metadata' => $result['metadata'] ?? [],
                        'project_id' => $result['metadata']['project_id'] ?? $this->extractProjectFromCollection($collection),
                        'project_name' => $result['metadata']['project_name'] ?? 'Unknown',
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning("Failed to search collection: {$collection}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Sort by score
        usort($allResults, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($allResults, 0, $limit);
    }

    /**
     * Extract project ID from collection name
     */
    protected function extractProjectFromCollection(string $collection): string
    {
        // Collection format: project_id_model (e.g., project_a_invoices)
        $parts = explode('_', $collection);
        return $parts[0] ?? 'unknown';
    }

    /**
     * Group results by project
     */
    protected function groupResultsByProject(array $results, string $query): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $projectId = $result['project_id'];

            if (!isset($grouped[$projectId])) {
                $grouped[$projectId] = [
                    'project_id' => $projectId,
                    'project_name' => $result['project_name'],
                    'results' => [],
                    'count' => 0,
                ];
            }

            $grouped[$projectId]['results'][] = $result;
            $grouped[$projectId]['count']++;
        }

        return [
            'query' => $query,
            'total_results' => count($results),
            'projects' => array_values($grouped),
            'results' => $results,
        ];
    }

    /**
     * Empty result structure
     */
    protected function emptyResult(string $query): array
    {
        return [
            'query' => $query,
            'total_results' => 0,
            'projects' => [],
            'results' => [],
        ];
    }
}
