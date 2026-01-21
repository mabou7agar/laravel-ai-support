<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Content Moderation Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for the AI Engine's content moderation service.
    |
    */

    'enabled' => true,

    // Default lists moved from hardcoded service
    'banned_words' => [
        'spam',
        'scam',
        'fraud',
        'illegal',
        'hack',
        'crack'
    ],

    'sensitive_topics' => [
        'politics',
        'religion',
        'violence',
        'drugs',
        'weapons'
    ],

    'patterns' => [
        'harmful' => [
            '/\b(kill|murder|suicide|bomb|weapon)\b/i',
            '/\b(hate|racist|discrimination)\b/i',
            '/\b(illegal|fraud|scam)\b/i',
        ],
        'bias' => [
            '/\b(all (men|women|people) are\b)/i',
            '/\b(always|never) (men|women|people)\b/i',
            '/\b(typical|stereotypical)\b/i',
        ]
    ],

    // Feature flags
    'features' => [
        'ai_moderation' => true,
        'banned_words_check' => true,
        'sensitive_topic_check' => true,
        'harmful_content_check' => true,
        'bias_check' => true,
    ],

    // Scoring
    'scores' => [
        'banned_word' => 0.3,
        'sensitive_topic' => 0.2,
        'harmful_content' => 0.4,
        'bias' => 0.2,
    ]
];
