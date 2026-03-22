<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Agent Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the agents below should be the
    | default for agent operations when no explicit agent is provided
    | for the operation. This should be any agent defined below.
    |
    */

    'default' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | Below are each of your agents defined for this application. Each
    | represents an agent configuration which can be used to perform
    | tasks like text, image, and audio creation via agents.
    |
    */

    'agents' => [
        'default' => [
            'name' => 'Default Agent',
            'description' => 'The default agent configuration.',
            'provider' => 'gemini',
        ],
        // You can define more agents here with different configurations.
    ],
];