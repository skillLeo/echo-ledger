<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

/**
 * The real models of HTTP mode: each request goes to its model's provider (config `assist.models.*.provider`). Anthropic
 * goes through the SDK, Groq through the HTTP client; a provider the server has no driver for is "not configured".
 * Keys are read from the credential vault by the providers' own classes, never here.
 */
final class LiveModels implements ModelGateway
{
    public function __construct(private readonly AnthropicModels $anthropic, private readonly GroqModels $groq) {}

    public function stream(ModelRequest $request): \Generator
    {
        yield from match ($request->provider) {
            'anthropic' => $this->anthropic->stream($request),
            'groq' => $this->groq->stream($request),
            default => throw new ModelFailure(ModelFailure::NOT_CONFIGURED),
        };
    }
}
