<?php

/*
| laravel/mcp for the Claude and ChatGPT connector (docs/API.md "Claude and ChatGPT connector"). Only apps that send the
| browser back to one of these addresses can register, compared whole (App\Http\Controllers\Connector\RegisterController):
| an entry with a path is that exact address, `/*` at the end is one more path segment, no path is the whole site.
| MCP_REDIRECT_DOMAINS replaces the list (comma-separated, with the scheme), for example when an app starts using a new
| address. Prefer the exact address: these sites also carry other people's pages.
*/

$domains = array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_REDIRECT_DOMAINS', '')))));

return [
    'redirect_domains' => $domains !== [] ? $domains : [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
        // ChatGPT uses one or the other, depending on the server; the last segment is its id for the connection.
        'https://chatgpt.com/connector_platform_oauth_redirect',
        'https://chatgpt.com/connector/oauth/*',
        // Claude Code and other desktop clients listen on this machine, on a port of their own.
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
