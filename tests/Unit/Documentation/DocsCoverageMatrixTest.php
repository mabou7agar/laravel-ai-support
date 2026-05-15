<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocsCoverageMatrixTest extends TestCase
{
    private const COVERAGE_MAP = [
        'index' => [
            'tests/Feature/Acceptance/AgentRefactorAcceptanceTest.php',
            'tests/Feature/AIEngineIntegrationTest.php',
        ],
        'guides/admin-ui' => [
            'tests/Feature/Admin/AdminUiAccessTest.php',
            'tests/Feature/Admin/AdminManifestManagerTest.php',
            'tests/Feature/Admin/AdminNodeManagementTest.php',
            'tests/Feature/Admin/AdminPolicyManagementTest.php',
        ],
        'guides/architecture' => [
            'tests/Unit/Services/Agent/AgentServiceResolutionTest.php',
            'tests/Unit/Services/UnifiedEngineManagerTest.php',
            'tests/Unit/AIEngineServiceProviderConfigMergeTest.php',
        ],
        'guides/capability-memory' => [
            'tests/Unit/Services/Agent/AgentCapabilityRegistryTest.php',
        ],
        'guides/chat-flow-examples' => [
            'tests/Feature/Acceptance/AgentRefactorAcceptanceTest.php',
            'tests/Unit/Services/Agent/LaravelAgentProcessorFollowUpTest.php',
            'tests/Unit/Services/Agent/AgentConversationServiceTest.php',
        ],
        'guides/concepts' => [
            'tests/Unit/Services/Agent/IntentRouterTest.php',
            'tests/Unit/Services/Agent/AgentPlannerTest.php',
            'tests/Unit/Services/DataCollector/AutonomousCollectorSessionServiceTest.php',
            'tests/Unit/Services/RAG/RAGPlannerServiceTest.php',
        ],
        'guides/configuration' => [
            'tests/Unit/AIEngineServiceProviderConfigMergeTest.php',
            'tests/Unit/Support/Config/AIEngineConfigDefaultsTest.php',
        ],
        'guides/conversation-sessions' => [
            'tests/Unit/Services/ConversationManagerTest.php',
            'tests/Feature/Api/ConversationListApiTest.php',
            'tests/Unit/Http/Controllers/Concerns/ExtractsConversationContextPayloadTest.php',
        ],
        'guides/copy-paste-playbooks' => [
            'tests/Feature/Acceptance/AgentRefactorAcceptanceTest.php',
            'tests/Feature/Api/ConversationListApiTest.php',
            'tests/Feature/Node/NodeApiRoutesTest.php',
        ],
        'guides/direct-generation-recipes' => [
            'tests/Feature/AIEngineIntegrationTest.php',
            'tests/Feature/Api/GenerateVideoApiTest.php',
            'tests/Feature/Api/GenerateApiCreditsTest.php',
            'tests/Unit/Console/Commands/TestAIMediaCommandTest.php',
            'tests/Unit/Services/Fal/FalMediaWorkflowServiceTest.php',
        ],
        'guides/end-to-end-graph-walkthrough' => [
            'tests/Feature/Acceptance/GraphRAGAcceptanceTest.php',
            'tests/Unit/Services/Graph/GraphQueryPlannerTest.php',
            'tests/Unit/Services/Graph/Neo4jRetrievalServiceTest.php',
        ],
        'guides/entity-list-preview-ux' => [
            'tests/Feature/Acceptance/AgentRefactorAcceptanceTest.php',
            'tests/Unit/Services/Summary/EntitySummaryServiceTest.php',
            'tests/Unit/Services/RAG/RAGStructuredDataServiceTest.php',
        ],
        'guides/federation' => [
            'tests/Feature/Node/NodeApiRoutesTest.php',
            'tests/Feature/Node/BulkSyncNodesCommandTest.php',
            'tests/Unit/Services/Node/NodeRouterServiceTest.php',
            'tests/Unit/Services/Node/NodeOwnershipResolverTest.php',
        ],
        'guides/flutter-dio-integration' => [
            'tests/Unit/Http/Middleware/StandardizeApiResponseMiddlewareTest.php',
            'tests/Feature/Api/ConversationListApiTest.php',
        ],
        'guides/graph-rag-neo4j' => [
            'tests/Feature/Acceptance/GraphRAGAcceptanceTest.php',
            'tests/Unit/Services/Graph/Neo4jRetrievalServiceTest.php',
            'tests/Unit/Services/Node/NodeRouterServiceGraphPreferenceTest.php',
            'tests/Unit/Console/Commands/SyncNeo4jGraphCommandTest.php',
        ],
        'guides/graph-relation-modeling' => [
            'tests/Unit/Services/Graph/GraphOntologyServiceTest.php',
            'tests/Unit/Services/Graph/GraphCypherPlanCompilerTest.php',
            'tests/Unit/Services/Graph/GraphKnowledgeBaseServiceTest.php',
        ],
        'guides/knowledge-base-security' => [
            'tests/Unit/Services/Graph/GraphKnowledgeBaseServiceTest.php',
            'tests/Unit/Services/Graph/GraphBackendResolverTest.php',
            'tests/Unit/Services/Node/NodeRouterServiceGraphPreferenceTest.php',
        ],
        'guides/localization' => [
            'tests/Unit/Http/Middleware/SetRequestLocaleMiddlewareTest.php',
            'tests/Unit/Services/RAG/RAGPlannerServiceTest.php',
        ],
        'guides/model-config-tools' => [
            'tests/Feature/Actions/ActionRegistryTest.php',
            'tests/Feature/Admin/AdminManifestManagerTest.php',
            'tests/Unit/Services/Node/NodeManifestServiceTest.php',
        ],
        'guides/multi-app-federation' => [
            'tests/Feature/Node/NodeApiRoutesTest.php',
            'tests/Feature/Node/BulkSyncNodesCommandTest.php',
            'tests/Feature/Node/CleanupNodesCommandTest.php',
            'tests/Unit/Services/Node/NodeBulkSyncServiceTest.php',
        ],
        'guides/neo4j-ops-runbook' => [
            'tests/Unit/Console/Commands/Neo4jGraphCommandsTest.php',
            'tests/Unit/Console/Commands/InitNeo4jGraphCommandTest.php',
            'tests/Unit/Console/Commands/SyncNeo4jGraphCommandTest.php',
        ],
        'guides/policy-learning' => [
            'tests/Feature/Policies/DecisionFeedbackPersistenceTest.php',
            'tests/Feature/Policies/DecisionPolicyEvaluateCommandTest.php',
            'tests/Feature/Policies/DecisionPromptPolicyServiceTest.php',
            'tests/Unit/Console/DecisionFeedbackReportCommandTest.php',
        ],
        'guides/quickstart' => [
            'tests/Unit/Console/TestRealAgentFlowCommandTest.php',
            'tests/Unit/Console/InfrastructureHealthCommandTest.php',
            'tests/Feature/AIEngineIntegrationTest.php',
        ],
        'guides/rag-indexing' => [
            'tests/Feature/MediaEmbeddingsIntegrationTest.php',
            'tests/Unit/Services/Vector/EmbeddingServiceFakeModeTest.php',
            'tests/Unit/Traits/HasMediaEmbeddingsTest.php',
        ],
        'guides/single-app-setup' => [
            'tests/Unit/AIEngineServiceProviderConfigMergeTest.php',
            'tests/Unit/Support/Config/AIEngineConfigDefaultsTest.php',
            'tests/Feature/AIEngineIntegrationTest.php',
        ],
        'guides/testing-playbook' => [
            'tests/Unit/Console/TestRealAgentFlowCommandTest.php',
            'tests/Unit/Console/InfrastructureHealthCommandTest.php',
            'tests/Feature/Acceptance/AgentRefactorAcceptanceTest.php',
        ],
        'guides/troubleshooting' => [
            'tests/Unit/Support/InfrastructureHealthServiceTest.php',
            'tests/Unit/Console/InfrastructureHealthCommandTest.php',
            'tests/Feature/Node/NodeApiRoutesTest.php',
        ],
        'reference/api-reference' => [
            'tests/Feature/Api/ConversationListApiTest.php',
            'tests/Feature/Api/GenerateApiCreditsTest.php',
            'tests/Feature/Api/GenerateVideoApiTest.php',
            'tests/Feature/Node/NodeApiRoutesTest.php',
        ],
        'reference/commands' => [
            'tests/Unit/Console/Commands/TestEnginesCommandTest.php',
            'tests/Unit/Console/TestRealAgentFlowCommandTest.php',
            'tests/Unit/Console/InfrastructureHealthCommandTest.php',
            'tests/Unit/Console/Commands/GenerateFalReferencePackCommandTest.php',
            'tests/Unit/Console/Commands/GenerateFalCharacterCommandTest.php',
            'tests/Unit/Console/Commands/DocsIndexCommandTest.php',
        ],
        'reference/env-reference' => [
            'tests/Unit/Support/Config/AIEngineConfigDefaultsTest.php',
            'tests/Unit/Http/Middleware/SetRequestLocaleMiddlewareTest.php',
            'tests/Feature/Admin/AdminUiAccessTest.php',
        ],
        'reference/qdrant-to-neo4j-migration' => [
            'tests/Unit/Services/Graph/GraphBackendResolverTest.php',
            'tests/Unit/Services/Graph/GraphVectorNamingServiceTest.php',
            'tests/Unit/Console/Commands/SyncNeo4jGraphCommandTest.php',
        ],
        'reference/upgrade' => [
            'tests/Unit/AIEngineServiceProviderConfigMergeTest.php',
            'tests/Unit/Facades/EngineTest.php',
            'tests/Unit/Services/EngineProxyTest.php',
        ],
    ];

    public function test_every_docs_page_has_a_coverage_mapping(): void
    {
        $documentedPages = $this->docsPages();
        $mappedPages = array_keys(self::COVERAGE_MAP);

        sort($documentedPages);
        sort($mappedPages);

        self::assertSame($documentedPages, $mappedPages, 'Update DocsCoverageMatrixTest when docs-site navigation changes.');
    }

    #[DataProvider('coverageProvider')]
    public function test_mapped_tests_exist_for_documented_page(string $page, string $testFile): void
    {
        $fullPath = $this->repoRoot() . '/' . $testFile;

        self::assertFileExists($fullPath, "Missing mapped test file [{$testFile}] for docs page [{$page}]");

        $contents = file_get_contents($fullPath);
        self::assertIsString($contents);
        self::assertMatchesRegularExpression('/function\s+test_|#\[Test\]|@test\b/', $contents, "Mapped file [{$testFile}] for docs page [{$page}] does not look like a test file.");
    }

    public static function coverageProvider(): array
    {
        $rows = [];

        foreach (self::COVERAGE_MAP as $page => $testFiles) {
            foreach ($testFiles as $testFile) {
                $rows[$page . ' => ' . $testFile] = [$page, $testFile];
            }
        }

        return $rows;
    }

    private function docsPages(): array
    {
        $docsJson = json_decode((string) file_get_contents($this->repoRoot() . '/docs-site/docs.json'), true, 512, JSON_THROW_ON_ERROR);
        $pages = [];

        foreach (($docsJson['navigation']['tabs'] ?? []) as $tab) {
            foreach (($tab['groups'] ?? []) as $group) {
                foreach (($group['pages'] ?? []) as $page) {
                    if (is_string($page) && $page !== '') {
                        $pages[] = $page;
                    }
                }
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
