<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use Illuminate\Support\Facades\Log;

class CollectorExecutionCoordinator
{
    public function __construct(
        protected ?AutonomousCollectorDiscoveryService $discoveryService = null,
        protected ?AutonomousCollectorHandler $collectorHandler = null,
        protected ?AgentPolicyService $policyService = null
    ) {
    }

    public function execute(
        ?string $collectorName,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $routeToNode
    ): AgentResponse {
        if (!$collectorName) {
            return AgentResponse::failure(
                message: $this->getPolicyService()->collectorNotSpecifiedMessage(),
                context: $context
            );
        }

        Log::channel('ai-engine')->info('CollectorExecutionCoordinator: start collector requested', [
            'collector_name' => $collectorName,
            'message' => substr($message, 0, 100),
        ]);

        $discoveredCollectors = $this->getDiscoveryService()->discoverCollectors(useCache: true, includeRemote: true);

        if (isset($discoveredCollectors[$collectorName])) {
            $collectorInfo = $discoveredCollectors[$collectorName];

            if (($collectorInfo['source'] ?? 'local') === 'remote' && !empty($collectorInfo['node_slug'])) {
                Log::channel('ai-engine')->info('CollectorExecutionCoordinator: routing collector request to node', [
                    'collector_name' => $collectorName,
                    'node' => $collectorInfo['node_slug'],
                    'node_name' => $collectorInfo['node_name'] ?? '',
                ]);

                return $routeToNode(
                    ['resource_name' => $collectorInfo['node_slug']],
                    $message,
                    $context,
                    $options
                );
            }

            if (!empty($collectorInfo['class']) && class_exists($collectorInfo['class'])) {
                $configClass = $collectorInfo['class'];
                $config = method_exists($configClass, 'create')
                    ? $configClass::create()
                    : new $configClass();

                Log::channel('ai-engine')->info('CollectorExecutionCoordinator: starting local autonomous collector', [
                    'collector_name' => $collectorName,
                    'class' => $configClass,
                ]);

                return $this->getCollectorHandler()->handle($message, $context, array_merge($options, [
                    'action' => 'start_autonomous_collector',
                    'collector_match' => [
                        'name' => $collectorName,
                        'config' => $config,
                        'description' => $collectorInfo['description'] ?? '',
                    ],
                ]));
            }
        }

        $match = AutonomousCollectorRegistry::findConfigForMessage($message);
        if (!$match) {
            Log::channel('ai-engine')->error('CollectorExecutionCoordinator: collector not found', [
                'collector_name' => $collectorName,
                'discovered_collectors' => array_keys($discoveredCollectors),
            ]);

            return AgentResponse::failure(
                message: $this->getPolicyService()->collectorUnavailableMessage((string) $collectorName),
                context: $context
            );
        }

        return $this->getCollectorHandler()->handle($message, $context, array_merge($options, [
            'action' => 'start_autonomous_collector',
            'collector_match' => $match,
        ]));
    }

    protected function getDiscoveryService(): AutonomousCollectorDiscoveryService
    {
        if ($this->discoveryService === null) {
            $this->discoveryService = app(AutonomousCollectorDiscoveryService::class);
        }

        return $this->discoveryService;
    }

    protected function getCollectorHandler(): AutonomousCollectorHandler
    {
        if ($this->collectorHandler === null) {
            $this->collectorHandler = app(AutonomousCollectorHandler::class);
        }

        return $this->collectorHandler;
    }

    protected function getPolicyService(): AgentPolicyService
    {
        if ($this->policyService === null) {
            try {
                $this->policyService = app(AgentPolicyService::class);
            } catch (\Throwable $e) {
                $this->policyService = new AgentPolicyService();
            }
        }

        return $this->policyService;
    }
}
