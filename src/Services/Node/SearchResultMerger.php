<?php

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Search Result Merger
 * 
 * Handles merging and ranking of search results from multiple nodes
 */
class SearchResultMerger
{
    const STRATEGY_SCORE = 'score';
    const STRATEGY_ROUND_ROBIN = 'round_robin';
    const STRATEGY_NODE_PRIORITY = 'node_priority';
    const STRATEGY_DIVERSITY = 'diversity';
    const STRATEGY_HYBRID = 'hybrid';
    
    /**
     * Merge results from multiple nodes
     */
    public function merge(
        array $localResults,
        array $remoteResults,
        int $limit,
        string $query,
        string $strategy = self::STRATEGY_SCORE
    ): array {
        // Combine all results
        $allResults = $this->combineResults($localResults, $remoteResults);
        
        // Apply deduplication if enabled
        if (config('ai-engine.nodes.merge.deduplication', true)) {
            $allResults = $this->deduplicateResults($allResults);
        }
        
        // Apply merge strategy
        $mergedResults = match($strategy) {
            self::STRATEGY_SCORE => $this->mergeByScore($allResults, $limit),
            self::STRATEGY_ROUND_ROBIN => $this->mergeByRoundRobin($allResults, $limit),
            self::STRATEGY_NODE_PRIORITY => $this->mergeByNodePriority($allResults, $limit),
            self::STRATEGY_DIVERSITY => $this->mergeByDiversity($allResults, $limit, $query),
            self::STRATEGY_HYBRID => $this->mergeByHybrid($allResults, $limit, $query),
            default => $this->mergeByScore($allResults, $limit),
        };
        
        Log::channel('ai-engine')->debug('Results merged', [
            'strategy' => $strategy,
            'input_count' => count($allResults),
            'output_count' => count($mergedResults),
            'limit' => $limit,
        ]);
        
        return $mergedResults;
    }
    
    /**
     * Combine local and remote results
     */
    protected function combineResults(array $localResults, array $remoteResults): array
    {
        $combined = [];
        
        // Add local results
        if (isset($localResults['results']) && is_array($localResults['results'])) {
            foreach ($localResults['results'] as $result) {
                $result['source_node'] = $localResults['node'] ?? 'master';
                $result['source_node_name'] = $localResults['node_name'] ?? 'Master Node';
                $combined[] = $result;
            }
        }
        
        // Add remote results
        foreach ($remoteResults as $nodeResults) {
            if (isset($nodeResults['results']) && is_array($nodeResults['results'])) {
                foreach ($nodeResults['results'] as $result) {
                    $result['source_node'] = $nodeResults['node'] ?? 'unknown';
                    $result['source_node_name'] = $nodeResults['node_name'] ?? 'Unknown Node';
                    $combined[] = $result;
                }
            }
        }
        
        return $combined;
    }
    
    /**
     * Deduplicate results based on content similarity
     */
    protected function deduplicateResults(array $results): array
    {
        $seen = [];
        $deduplicated = [];
        
        foreach ($results as $result) {
            // Create a hash of the content for deduplication
            $hash = $this->getResultHash($result);
            
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $deduplicated[] = $result;
            } else {
                Log::channel('ai-engine')->debug('Duplicate result removed', [
                    'hash' => $hash,
                    'content_preview' => substr($result['content'] ?? '', 0, 100),
                ]);
            }
        }
        
        return $deduplicated;
    }
    
    /**
     * Get hash for result deduplication
     */
    protected function getResultHash(array $result): string
    {
        // Use model_class + model_id if available
        if (isset($result['model_class']) && isset($result['id'])) {
            return md5($result['model_class'] . ':' . $result['id']);
        }
        
        // Fallback to content hash
        $content = $result['content'] ?? $result['title'] ?? $result['name'] ?? '';
        return md5(strtolower(trim($content)));
    }
    
    /**
     * Merge by relevance score (default)
     */
    protected function mergeByScore(array $results, int $limit): array
    {
        // Sort by score descending
        usort($results, function($a, $b) {
            $scoreA = $a['score'] ?? $a['relevance_score'] ?? 0;
            $scoreB = $b['score'] ?? $b['relevance_score'] ?? 0;
            return $scoreB <=> $scoreA;
        });
        
        return array_slice($results, 0, $limit);
    }
    
    /**
     * Merge by round-robin (fair distribution across nodes)
     */
    protected function mergeByRoundRobin(array $results, int $limit): array
    {
        // Group by source node
        $byNode = [];
        foreach ($results as $result) {
            $node = $result['source_node'] ?? 'unknown';
            $byNode[$node][] = $result;
        }
        
        // Round-robin selection
        $merged = [];
        $nodeKeys = array_keys($byNode);
        $nodeCount = count($nodeKeys);
        $index = 0;
        
        while (count($merged) < $limit && !empty($byNode)) {
            $nodeKey = $nodeKeys[$index % $nodeCount];
            
            if (!empty($byNode[$nodeKey])) {
                $merged[] = array_shift($byNode[$nodeKey]);
            }
            
            // Remove empty nodes
            if (empty($byNode[$nodeKey])) {
                unset($byNode[$nodeKey]);
                $nodeKeys = array_keys($byNode);
                $nodeCount = count($nodeKeys);
                if ($nodeCount === 0) break;
            }
            
            $index++;
        }
        
        return $merged;
    }
    
    /**
     * Merge by node priority (master first, then by node weight)
     */
    protected function mergeByNodePriority(array $results, int $limit): array
    {
        // Sort by node priority, then by score
        usort($results, function($a, $b) {
            $nodeA = $a['source_node'] ?? 'unknown';
            $nodeB = $b['source_node'] ?? 'unknown';
            
            // Master node has highest priority
            if ($nodeA === 'master' && $nodeB !== 'master') return -1;
            if ($nodeB === 'master' && $nodeA !== 'master') return 1;
            
            // Then by score
            $scoreA = $a['score'] ?? $a['relevance_score'] ?? 0;
            $scoreB = $b['score'] ?? $b['relevance_score'] ?? 0;
            return $scoreB <=> $scoreA;
        });
        
        return array_slice($results, 0, $limit);
    }
    
    /**
     * Merge by diversity (maximize variety of sources and types)
     */
    protected function mergeByDiversity(array $results, int $limit, string $query): array
    {
        $merged = [];
        $seenTypes = [];
        $seenNodes = [];
        
        // Sort by score first
        usort($results, function($a, $b) {
            $scoreA = $a['score'] ?? $a['relevance_score'] ?? 0;
            $scoreB = $b['score'] ?? $b['relevance_score'] ?? 0;
            return $scoreB <=> $scoreA;
        });
        
        // Select diverse results
        foreach ($results as $result) {
            if (count($merged) >= $limit) break;
            
            $type = $result['model_type'] ?? $result['type'] ?? 'unknown';
            $node = $result['source_node'] ?? 'unknown';
            
            // Prefer results from different types and nodes
            $typeCount = $seenTypes[$type] ?? 0;
            $nodeCount = $seenNodes[$node] ?? 0;
            
            // Add result if we haven't seen too many of this type/node
            $maxPerType = max(2, (int)($limit / 4));
            $maxPerNode = max(3, (int)($limit / 3));
            
            if ($typeCount < $maxPerType && $nodeCount < $maxPerNode) {
                $merged[] = $result;
                $seenTypes[$type] = $typeCount + 1;
                $seenNodes[$node] = $nodeCount + 1;
            }
        }
        
        // Fill remaining slots with best scores
        if (count($merged) < $limit) {
            foreach ($results as $result) {
                if (count($merged) >= $limit) break;
                if (!in_array($result, $merged, true)) {
                    $merged[] = $result;
                }
            }
        }
        
        return $merged;
    }
    
    /**
     * Merge by hybrid strategy (combines score and diversity)
     */
    protected function mergeByHybrid(array $results, int $limit, string $query): array
    {
        // Take top 70% by score
        $scoreLimit = (int)($limit * 0.7);
        $scoreResults = $this->mergeByScore($results, $scoreLimit);
        
        // Take remaining 30% by diversity
        $diversityLimit = $limit - count($scoreResults);
        $remainingResults = array_filter($results, function($r) use ($scoreResults) {
            return !in_array($r, $scoreResults, true);
        });
        $diversityResults = $this->mergeByDiversity($remainingResults, $diversityLimit, $query);
        
        return array_merge($scoreResults, $diversityResults);
    }
    
    /**
     * Get merge statistics
     */
    public function getStatistics(array $results): array
    {
        $byNode = [];
        $byType = [];
        $scores = [];
        
        foreach ($results as $result) {
            $node = $result['source_node'] ?? 'unknown';
            $type = $result['model_type'] ?? 'unknown';
            $score = $result['score'] ?? $result['relevance_score'] ?? 0;
            
            $byNode[$node] = ($byNode[$node] ?? 0) + 1;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $scores[] = $score;
        }
        
        return [
            'total_results' => count($results),
            'by_node' => $byNode,
            'by_type' => $byType,
            'avg_score' => !empty($scores) ? array_sum($scores) / count($scores) : 0,
            'min_score' => !empty($scores) ? min($scores) : 0,
            'max_score' => !empty($scores) ? max($scores) : 0,
        ];
    }
}
