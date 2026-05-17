<?php

return [
    'language' => [
        'name' => 'English',
    ],

    'intent' => [
        'confirm' => ['yes', 'y', 'ok', 'okay', 'confirm', 'sure', 'yep', 'yeah', 'yup', 'proceed', 'go ahead', 'do it', 'make it'],
        'reject' => ['no', 'n', 'cancel', 'stop', 'abort', 'nevermind', 'never mind', 'reject', 'nope'],
        'greeting' => ['hi', 'hello', 'hey', 'greetings', 'good morning', 'good afternoon', 'good evening'],
        'cancel' => ['cancel', 'stop', 'quit', 'exit', 'abort', 'nevermind', 'never mind'],
        'deny' => ['no', 'n', 'cancel', 'change', 'modify', 'edit'],
        'done' => ['done', 'finished', 'that is all', 'no more changes', 'looks good now'],
        'modify' => ['change', 'modify', 'edit', 'update', 'replace', 'instead'],
        'duplicate_use' => ['use', 'yes'],
        'duplicate_create' => ['new', 'create'],
        'possessive' => ['my', 'mine', 'our', 'ours'],
        'query_prefixes' => ['list ', 'show ', 'get ', 'find ', 'search ', 'display ', 'view ', 'what are ', 'how many ', 'count '],
    ],

    'response' => [
        'affirmative' => ['yes', 'y', 'true', 'confirm', 'approved'],
        'negative' => ['no', 'n', 'false', 'reject', 'decline'],
    ],

    'relation' => [
        'use_existing' => ['use existing', 'use this', 'use it', 'choose existing', 'select existing', 'keep existing'],
        'create_new' => ['create new', 'new one', 'create missing', 'add new', 'make new'],
    ],

    'entities' => [
        'aliases' => [
            'invoice' => [
                'invoice',
                'invoices',
                'sales invoice',
                'sales invoices',
                'customer invoice',
                'customer invoices',
                'فاتورة',
                'فواتير',
                'فاتورة بيع',
                'فواتير بيع',
            ],
            'bill' => [
                'bill',
                'bills',
                'vendor bill',
                'vendor bills',
                'purchase bill',
                'purchase bills',
                'supplier bill',
                'supplier invoice',
                'فاتورة مشتريات',
                'فواتير مشتريات',
                'فاتورة مورد',
                'فواتير مورد',
            ],
        ],
    ],

    'user' => [
        'current_placeholders' => [
            'current_user_id',
            'current user id',
            'current user',
            'authenticated user',
            'auth user',
            'my user id',
            'me',
            'myself',
            'self',
        ],
    ],
];
