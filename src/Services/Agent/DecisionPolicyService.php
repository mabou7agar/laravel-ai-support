<?php

namespace LaravelAIEngine\Services\Agent;

class DecisionPolicyService
{
    public function shouldPreferFollowUpAnswer(array $signals): bool
    {
        return ($signals['has_entity_list_context'] ?? false)
            && ($signals['is_follow_up_question'] ?? false)
            && !($signals['is_explicit_list_request'] ?? false)
            && !($signals['is_explicit_entity_lookup'] ?? false)
            && !($signals['is_option_selection'] ?? false)
            && !($signals['is_positional_reference'] ?? false);
    }
}
