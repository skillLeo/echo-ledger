<?php

/*
| laravel/mcp for the Claude and ChatGPT connector (docs/API.md "Claude and ChatGPT connector"). Only apps that send the
| browser back to one of these addresses can register. MCP_REDIRECT_DOMAINS replaces the list (comma-separated, with
| the scheme), for example when an app starts using a new address.
*/

$domains = array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_REDIRECT_DOMAINS', '')))));

return [
    'redirect_domains' => $domains !== [] ? $domains : [
        'https://claude.ai',
        'https://claude.com',
        'https://chatgpt.com',
        'https://chat.openai.com',
        // Claude Code and other desktop clients listen on this machine.
        'http://localhost',
        'http://127.0.0.1',
    ],

    'custom_schemes' => [],

    // Null: this server's own address.
    'authorization_server' => null,

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    'clients' => [],
];
