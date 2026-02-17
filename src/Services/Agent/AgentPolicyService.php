<?php

namespace LaravelAIEngine\Services\Agent;

class AgentPolicyService
{
    public function __construct(
        protected array $config = []
    ) {
    }

    public function noCollectorMatchMessage(string $message): string
    {
        if ($this->isDestructiveIntent($message)) {
            return $this->message('no_collector_destructive', 'Delete operations are not currently available through the AI assistant. Please use the application interface to delete records.');
        }

        return $this->message('no_collector_generic', "I couldn't find a way to handle that request. I can help you create, update, or search for records. What would you like to do?");
    }

    public function noPausedSessionMessage(): string
    {
        return $this->message('no_paused_session', "There's no paused session to resume.");
    }

    public function resumeSessionMessage(string $collectorName): string
    {
        $template = $this->message('resume_session', "Welcome back! Let's continue with your :collector.");
        return str_replace(':collector', $collectorName, $template);
    }

    public function ragNoResultsMessage(): string
    {
        return $this->message('rag_no_results', 'No results found.');
    }

    public function ragNoRelevantInfoMessage(): string
    {
        return $this->message('rag_no_relevant_info', "I couldn't find any relevant information. Could you please rephrase your question?");
    }

    public function nodeNotFoundMessage(string $resource): string
    {
        $template = $this->message('node_not_found', "I couldn't find a remote node matching ':resource'.");
        return str_replace(':resource', $resource, $template);
    }

    public function nodeUnreachableMessage(string $nodeSlug, ?string $nodeUrl, ?string $error = null): string
    {
        $summary = $error ? preg_replace('/\s+/', ' ', trim($error)) : 'unknown routing error';
        $maxLength = (int) $this->getConfig(
            'limits.node_error_summary_max',
            $this->getConfig('messages.node_error_summary_max', 220)
        );
        if (is_string($summary) && $maxLength > 0 && strlen($summary) > $maxLength) {
            $summary = substr($summary, 0, $maxLength) . '...';
        }

        $template = $this->message(
            'node_unreachable',
            "I couldn't reach remote node ':node':location (:summary). I did not run a local fallback query to avoid mixed-domain results. Please verify the node is running and try again."
        );

        return str_replace(
            [':node', ':location', ':summary'],
            [$nodeSlug, $nodeUrl ? " at {$nodeUrl}" : '', (string) $summary],
            $template
        );
    }

    public function positionalUnknownMessage(): string
    {
        return $this->message('positional_unknown', "I couldn't understand which item you're referring to. Could you be more specific?");
    }

    public function positionalNotFoundMessage(int $position): string
    {
        $template = $this->message('positional_not_found', "I couldn't find item #:position in the previous list. Please check the number and try again.");
        return str_replace(':position', (string) $position, $template);
    }

    public function positionalDetailsUnavailableMessage(string $entityType): string
    {
        $template = $this->message('positional_details_unavailable', "I found the :entity but couldn't retrieve its details. Please try again.");
        return str_replace(':entity', $entityType, $template);
    }

    public function selectedOptionMessage(int $optionNumber, string $detail): string
    {
        $template = $this->message('selected_option', "**Selected option :number**\n\n:detail");
        return str_replace(
            [':number', ':detail'],
            [(string) $optionNumber, $detail],
            $template
        );
    }

    public function optionRemoteLookupMessage(string $entityType, int $entityId): string
    {
        $template = $this->message('option_remote_lookup', 'show details for :entity id :id');
        return str_replace(
            [':entity', ':id'],
            [$entityType, (string) $entityId],
            $template
        );
    }

    public function optionLocalLookupMessage(string $entityType, int $entityId): string
    {
        $template = $this->message('option_local_lookup', 'show full details for :entity id :id');
        return str_replace(
            [':entity', ':id'],
            [$entityType, (string) $entityId],
            $template
        );
    }

    public function toolNotSpecifiedMessage(): string
    {
        return $this->message('tool_not_specified', 'No tool specified.');
    }

    public function collectorNotSpecifiedMessage(): string
    {
        return $this->message('collector_not_specified', 'No collector specified.');
    }

    public function collectorUnavailableMessage(string $collectorName): string
    {
        $template = $this->message('collector_unavailable', "Collector ':collector' not available.");
        return str_replace(':collector', $collectorName, $template);
    }

    protected function isDestructiveIntent(string $message): bool
    {
        $keywords = $this->getConfig('intents.destructive_verbs', ['delete', 'remove', 'cancel']);
        if (!is_array($keywords) || empty($keywords)) {
            return false;
        }

        $pattern = '/\b(' . implode('|', array_map(fn ($w) => preg_quote((string) $w, '/'), $keywords)) . ')\b/i';
        return preg_match($pattern, $message) === 1;
    }

    protected function message(string $key, string $default): string
    {
        $value = $this->getConfig("messages.{$key}", $default);
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    protected function getConfig(string $key, $default = null)
    {
        $value = data_get($this->config, $key, null);
        if ($value !== null) {
            return $value;
        }

        try {
            return config("ai-agent.policy.{$key}", $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
