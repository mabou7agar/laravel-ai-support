<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final class RoutingDecisionAction
{
    public const CONTINUE_RUN = 'continue_run';
    public const CONTINUE_COLLECTOR = 'continue_collector';
    public const CONTINUE_NODE = 'continue_node';
    public const HANDLE_SELECTION = 'handle_selection';
    public const RUN_DETERMINISTIC = 'run_deterministic';
    public const SEARCH_RAG = 'search_rag';
    public const USE_TOOL = 'use_tool';
    public const RUN_SUB_AGENT = 'run_sub_agent';
    public const START_COLLECTOR = 'start_collector';
    public const ROUTE_TO_NODE = 'route_to_node';
    public const PAUSE_AND_HANDLE = 'pause_and_handle';
    public const CONVERSATIONAL = 'conversational';
    public const NEED_USER_INPUT = 'need_user_input';
    public const FAIL = 'fail';
    public const ABSTAIN = 'abstain';

    public static function all(): array
    {
        return [
            self::CONTINUE_RUN,
            self::CONTINUE_COLLECTOR,
            self::CONTINUE_NODE,
            self::HANDLE_SELECTION,
            self::RUN_DETERMINISTIC,
            self::SEARCH_RAG,
            self::USE_TOOL,
            self::RUN_SUB_AGENT,
            self::START_COLLECTOR,
            self::ROUTE_TO_NODE,
            self::PAUSE_AND_HANDLE,
            self::CONVERSATIONAL,
            self::NEED_USER_INPUT,
            self::FAIL,
            self::ABSTAIN,
        ];
    }
}
