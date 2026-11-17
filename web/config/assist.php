<?php

declare(strict_types=1);

/*
 * Live Assist (v1.9 L1, docs/API_ASSIST.md). The plans (D5, 2026-11-16) and the relay's catalog: the models it may use,
 * their prices (US$ per million tokens) and which model each mode runs. GET /api/internal/assist/providers sends the
 * catalog to the relay and it replaces the relay's own list, so every field the relay reads is here as relay.toml has it
 * (services/assist-relay/config/relay.toml): the options (effort, thinking) passed on unchanged, and
 * `hold_until_complete` on the models whose refusal can come after words have streamed.
 */
return [
    // Plan key => what Live Assist gives it. `claim`: the plan the relay token names (free, pro, ultra). `live_modes`:
    // the modes of a live session, the most capable last. `seconds` and `cents`: the month's live assist time and model
    // budget (US cents). `practice`: Practice's own allowance (since 1.9.0-h), counted apart from the live one: Practice
    // sessions, their questions, facts, summary and report are billed to it.
    'plans' => [
        'free' => ['claim' => 'free', 'live_modes' => [], 'seconds' => 0, 'cents' => 0, 'practice' => ['seconds' => 30 * 60, 'cents' => 100]],
        'pro' => ['claim' => 'pro', 'live_modes' => ['fast', 'balanced'], 'seconds' => 10 * 3600, 'cents' => 1500, 'practice' => ['seconds' => 5 * 3600, 'cents' => 500]],
        'business' => ['claim' => 'ultra', 'live_modes' => ['fast', 'balanced', 'deep', 'max'], 'seconds' => 40 * 3600, 'cents' => 6000, 'practice' => ['seconds' => 10 * 3600, 'cents' => 1000]],
        'personal' => ['claim' => 'ultra', 'live_modes' => ['fast', 'balanced', 'deep', 'max'], 'seconds' => 40 * 3600, 'cents' => 6000, 'practice' => ['seconds' => 10 * 3600, 'cents' => 1000]],
    ],

    // A Practice session runs this mode on every plan, Free included, within the plan's assist time.
    'practice_modes' => ['fast'],

    // Every mode, the most capable first (the relay's budget ladder).
    'modes_order' => ['max', 'deep', 'balanced', 'fast'],

    'modes' => [
        'fast' => ['primary' => 'claude-haiku-5-5', 'fallback' => 'openai/gpt-oss-120b', 'transcript' => 'recent', 'max_output_tokens' => 300],
        'balanced' => ['primary' => 'claude-haiku-5-5', 'fallback' => 'openai/gpt-oss-120b', 'upgrade' => 'claude-sonnet-5-5', 'transcript' => 'full', 'max_output_tokens' => 400],
        'deep' => ['primary' => 'claude-sonnet-5-5', 'fallback' => 'claude-haiku-5-5', 'transcript' => 'full', 'max_output_tokens' => 500],
        'max' => ['primary' => 'claude-opus-5-5', 'fallback' => 'claude-sonnet-5-5', 'transcript' => 'full', 'max_output_tokens' => 600],
    ],

    'models' => [
        'claude-haiku-5-5' => [
            'provider' => 'anthropic', 'first_token_timeout_ms' => 2500,
            'price' => ['input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50],
            'long_context' => ['above_tokens' => 100000, 'input' => 0.50, 'cache_write' => 0.625, 'cache_read' => 0.05, 'output' => 2.50],
            'options' => ['effort' => 'low', 'thinking' => 'disabled'],
        ],
        // Held until complete: a safety refusal can come after words have streamed, and a refusal is never shown.
        'claude-sonnet-5-5' => [
            'provider' => 'anthropic', 'hold_until_complete' => true, 'first_token_timeout_ms' => 4000,
            'price' => ['input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.10, 'output' => 10.00],
            'options' => ['effort' => 'low', 'thinking' => 'between_tools'],
        ],
        'claude-opus-5-5' => [
            'provider' => 'anthropic', 'hold_until_complete' => true, 'first_token_timeout_ms' => 6000,
            'price' => ['input' => 4.00, 'cache_write' => 5.00, 'cache_read' => 0.20, 'output' => 20.00],
            'options' => ['effort' => 'low'],
        ],
        'openai/gpt-oss-120b' => [
            'provider' => 'groq', 'first_token_timeout_ms' => 2000,
            'price' => ['input' => 0.15, 'cache_write' => 0.15, 'cache_read' => 0.15, 'output' => 0.60],
            'options' => ['reasoning_effort' => 'low'],
        ],
        'gpt-6-luna' => [
            'provider' => 'openai', 'enabled' => false, 'first_token_timeout_ms' => 2500,
            'price' => ['input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50],
        ],
        'gemini-3.8-flash' => [
            'provider' => 'gemini', 'enabled' => false, 'first_token_timeout_ms' => 2500,
            'price' => ['input' => 1.50, 'cache_write' => 1.50, 'cache_read' => 0.15, 'output' => 7.50],
            'options' => ['thinking_budget' => 0],
        ],
    ],

    // The usage kinds the relay reports (1.9.0-h): a suggestion's (`suggestion`, or the relay's own kind of it) and its
    // background calls. `report` is the server's own: the post-call report (L7).
    'usage_kinds' => ['suggestion', 'answer', 'help_now', 'regenerate', 'shorter', 'formal', 'translate', 'upgrade', 'practice_question', 'facts', 'summary', 'report', 'ask'],

    // The post-call report (L7): the model that writes its words (from the catalog above, for its price), how much of the
    // transcript it reads, and the coaching numbers' settings.
    'report' => [
        'model' => 'claude-sonnet-5-5',
        'max_output_tokens' => 6000,
        // A report made again (a late post from the relay) asks the model at most this many times in all.
        'max_runs' => 3,
        'transcript_chars' => 60000,
        'long_pause_ms' => 3000,
        // Counted as whole words or phrases, ignoring case.
        'filler_words' => ['um', 'uh', 'erm', 'er', 'hmm', 'you know', 'i mean', 'basically', 'actually', 'sort of', 'kind of', 'matlab', 'yaani'],
    ],

    // Ask over a session (L7): a question about one call, answered from its transcript, report and facts, with the
    // moments it rests on. Billed as usage kind "ask" to the session's allowance (Practice's for Practice).
    'ask' => [
        'model' => 'claude-haiku-5-5',
        'max_output_tokens' => 800,
        'transcript_chars' => 40000,
        'question_chars' => 500,
    ],

    // Provider keys the relay knows (services/assist-relay/src/domain.rs ProviderKind); any other key stays here.
    'relay_providers' => ['anthropic', 'openai', 'gemini', 'groq'],

    // The relay token's lifetime (the contract's ceiling), and how long a rotated-out secret still works.
    'token_ttl_seconds' => 900,
    'secret_overlap_seconds' => 900,
    // The report waits this long after the end for the relay's last posts (a later one has it made again).
    'report_delay_seconds' => 30,
    // A live session nobody refreshed a token for in this long is over (the app is gone).
    'abandoned_after_seconds' => 1200,
];
