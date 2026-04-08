<?php

/**
 * Minimal Prism config for the ExamplePress Generative UI Agent.
 *
 * Provider/model/key are resolved at call-time from WordPress options
 * (ep_agent_provider, ep_agent_model, ep_agent_api_key) by LLMClient,
 * not from environment variables — this file only declares which
 * providers Prism should know about.
 */

return [
    'prism_server' => [
        'enabled' => false,
    ],

    'request_timeout' => 120,

    'anthropic' => [
        'default_thinking_budget' => 1024,
    ],

    'providers' => [
        'anthropic' => [
            'api_key' => get_option('ep_agent_api_key', ''),
            'version' => '2023-06-01',
        ],
        'openai' => [
            'url'          => 'https://api.openai.com/v1',
            'api_key'      => get_option('ep_agent_api_key', ''),
            'organization' => null,
            'project'      => null,
        ],
    ],
];
