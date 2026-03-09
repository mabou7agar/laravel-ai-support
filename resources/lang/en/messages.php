<?php

return [
    'api' => [
        'request_completed' => 'Request completed.',
        'request_failed' => 'Request failed.',
    ],

    'agent' => [
        'no_results_found' => 'No results found.',
        'no_more_results' => "No more results to show. You've seen all :count results.",
        'reached_end_of_results' => "You've reached the end. All :count results have been shown.",
        'rag_no_relevant_info' => "I couldn't find any relevant information. Could you please rephrase your question?",
        'rag_remote_unreachable' => "I couldn't reach the remote node right now. Please try again in a moment.",
        'rag_node_not_found' => "I couldn't find a connected node that owns this data.",
        'rag_model_not_found' => "I couldn't match this request to an available data model.",
        'rag_lookup_failed' => "I couldn't complete the data lookup right now. Please try again.",

        'node_no_remote_specified' => 'No remote node specified.',
        'node_matching_remote_not_found' => "I couldn't find a remote node matching ':resource'.",
        'node_unreachable' => "I couldn't reach remote node ':node':location (:summary). Please verify the node is running and try again.",

        'selection_unrecognized' => "I couldn't understand which item you're referring to. Could you be more specific?",
        'selection_item_not_found' => "I couldn't find item #:position in the previous list. Please check the number and try again.",
        'selection_details_unavailable' => "I found the :type but couldn't retrieve its details. Please try again.",
    ],
];
